# Background processing

Everything that happens outside a web request — bot replies, statistics, cleanup, track
history — is done by one **worker**, and the worker is kept alive by one **supervisor**.

```
systemd (or cron)
   └── radiochatbox.php daemons:start       the supervisor: keeps the worker alive, restarts it on deploy
         └── radiochatbox.php bot:worker    the worker: delivers bot replies and runs the periodic tasks
```

Both are console commands (`src/ConsoleCommands`, run through the `radiochatbox.php` entry
point). The supervisor is the only thing that needs watching from outside; if you have
neither systemd nor a supervisor, [cron covers both](#cron-fallback).

Inside the containers, `./radiochatbox` forwards to the same entry point:
`./radiochatbox bot:status`, `./radiochatbox daemons:start --once`.

---

## Quick reference

```bash
php radiochatbox.php daemons:start              # the process to supervise (interval 10s)
php radiochatbox.php daemons:start --once       # one reconciliation pass, then exit  ← cron fallback
php radiochatbox.php daemons:start --once --dry-run   # show what it would start, change nothing
php radiochatbox.php daemons:start --interactive      # live dashboard of every managed daemon

php radiochatbox.php bot:status                 # worker health, queue depth, task list (exit 2 = wedged)
php radiochatbox.php bot:worker --once --schedule     # process what is due + run due tasks  ← cron fallback
php radiochatbox.php bot:schedule               # cadence, last run, what is due now
php radiochatbox.php bot:run-task cleanup       # run one periodic task by hand
php radiochatbox.php bot:log --problems         # recent LLM calls that failed
```

There is no `stop`/`restart` subcommand: a deploy restart is **automatic** (see
[below](#what-the-supervisor-does)), and the supervisor's own lifecycle is `systemctl` (or
Ctrl+C). Worker health is `bot:status` or the admin dashboard.

---

## Production setup (systemd)

The supervisor is what systemd runs. One unit per installation, because several can share a
server:

`/etc/systemd/system/radiochatbox-daemon@.service`

The `%i` is the instance name you pass after the `@`; here it is the site's domain, and the
path to that installation is built from it. Adjust `WorkingDirectory` to match your own
layout:

```ini
[Unit]
Description=RadioChatBox daemon supervisor (%i)
After=network.target postgresql.service redis.service

[Service]
Type=simple
User=siteuser
WorkingDirectory=/home/siteuser/domains/%i/public_html
ExecStart=/usr/bin/php /home/siteuser/domains/%i/public_html/radiochatbox.php daemons:start

Restart=always
RestartSec=5

# The workers finish the job in hand before exiting.
TimeoutStopSec=60
KillMode=mixed

StandardOutput=journal
StandardError=journal
SyslogIdentifier=radiochatbox-daemon-%i

[Install]
WantedBy=multi-user.target
```

One installation is one instance, so a server that hosts several enables one per site:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now radiochatbox-daemon@radio.example.com
sudo systemctl enable --now radiochatbox-daemon@news.example.com
journalctl -u radiochatbox-daemon@radio.example.com -f
```

Note what is **not** in the unit: the worker. Starting it is the supervisor's job, and
letting systemd start it as well would mean two of them. Stopping is
`systemctl stop radiochatbox-daemon@radio.example.com` — the supervisor exits and releases its
lock; a job in flight finishes first.

---

## What the supervisor does

Every cycle (10s by default, `--interval`) it compares what should be running with what is:

| It finds | It does |
|---|---|
| no worker | starts one |
| a lock left behind, pid gone (crash, `kill -9`, OOM) | starts a replacement |
| a worker alive but with no heartbeat for 5 minutes | asks it to restart — the case a plain pid check calls healthy |
| a newly deployed commit | asks every worker to come back on the new code |
| a healthy worker | nothing |

**Stopping is always a request, never a kill.** It writes a `.stop` file next to the worker's
lock; the worker notices between jobs (its `shouldStop()`) and exits cleanly. A job cut in half
is worse than a job finished late, and the next cycle finds the slot empty and fills it.

**A deployment is a new commit**, read straight from `.git/HEAD` (and `packed-refs` after a
`git gc`) — no `git` binary needed. A commit hash changes once per deploy, whereas file
timestamps change on every editor save. When it changes, the supervisor asks every worker to
restart on the new code — **no manual step**. Deploy detection runs only in the continuous
loop; a single `--once` pass cannot compare two commits, so in the
[cron-once model](#level-1--cron-drives-the-supervisor-recommended-without-systemd) either run
the worker with `--watch-files`, or restart the service after deploying.

Why a supervisor at all: PHP cannot reload code into a running process, so a deploy means
*replacing* processes, and something has to do the replacing.

---

## What the worker does

Two things, in one process.

**Bot replies.** Jobs live in a Redis delayed queue: read delay, then typing delay, then
delivery. See [BOT_REPLIES.md](BOT_REPLIES.md).

**Periodic tasks** (with `--schedule`, which the supervisor passes):

| Task | Every | Notes |
|---|---|---|
| `track_poll` | 30s (`track_poll_seconds` setting) | reads the stream, records the current track; a new track is enriched immediately |
| `track_enrich` | 5m | sweeps up tracks whose metadata is still missing |
| `stats_snapshot` | 5m | user/listener snapshot |
| `stats_hourly` | hourly at :05 | aggregates the finished hour |
| `stats_daily` | daily 00:10 UTC | aggregates the finished day |
| `stats_weekly` / `monthly` / `yearly` | daily, staggered | aggregates the period so far |
| `cleanup` | 1h | expired bans and DM blocks, stale sessions, old messages |
| `prune_llm_log` | daily 03:00 | drops LLM log entries past the retention window |
| `llm_balance_snapshot` | 1h | records the provider balance so real spend is measurable |

Each task records its own last run, duration and error in `scheduled_tasks`. A task that fails
is logged and retried on its next interval — it never takes the worker down, and never blocks
the reply queue for long (at most two tasks run per cycle).

The stream is polled every 30 seconds because the current track has to be caught while it is
playing: bundled with the five-minute snapshot, a three-minute song was missed entirely. It is
cheap — recording de-duplicates atomically, so nothing is written until the track actually
changes.

---

## Cron fallback

You do not need systemd, and you do not need a permanently running process at all. Cron can
drive the same code, with the granularity of the cron interval. Pick **one** of the levels
below; they all guard against overlap through the same lock, so a slow run can never double up.

### Level 1 — cron drives the supervisor (recommended without systemd)

One line per installation — the supervisor is scoped to the directory it runs in:

```cron
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php daemons:start --once >> logs/daemon.log 2>&1
* * * * * cd /home/siteuser/domains/news.example.com/public_html && php radiochatbox.php daemons:start --once >> logs/daemon.log 2>&1
```

Every minute the supervisor does exactly one reconciliation pass and exits: it starts the
worker if it is missing, replaces a crashed one, and asks a wedged one to restart. The worker
itself keeps running continuously between passes, so bot replies stay second-accurate. A worker
that dies is back within a minute.

Deploy detection is skipped in this mode — one pass cannot compare two commits — so let the
worker watch its own code by adding `--watch-files` to its command, or restart the service
after deploying.

### Level 2 — cron drives the worker directly

No supervisor at all. Two shapes:

```cron
# A worker that lives for one minute at a time.
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php bot:worker --schedule --max-runtime=55 >> logs/worker.log 2>&1
```

The worker runs for 55 seconds, exits, and the next minute's cron starts a fresh one. Replies
stay second-accurate while it is up, with a gap of a few seconds each minute. A new deploy is
picked up automatically, because each minute is a new process.

```cron
# Or: process whatever is due, then exit.
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php bot:worker --once --schedule >> logs/worker.log 2>&1
```

Cheapest of all, and enough when replies do not need to be prompt: a reply scheduled for 10
seconds from now is delivered at the next minute boundary instead. `--schedule` means this
single line also runs the periodic tasks.

### Level 3 — the framework cron scheduler

If you would rather express the schedule as cron than run the worker's own scheduler, wire the
framework scheduler (`app/schedule.php`) to a single cron line — it runs whatever is due
(snapshots, aggregations, cleanup, prune) by shelling out to `bot:run-task`:

```cron
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php schedule:run >> logs/schedule.log 2>&1
```

Do **not** run this alongside the worker's `--schedule` — they do not share the per-task
last-run state, so together they would double-execute. Run one or the other.

**Mixing is safe** only within the same driver: every task records its own last run, so the
lock and the last-run state stop the same task running twice inside its interval.

### What always stays in cron

```cron
0 2 * * * cd /home/siteuser/domains/radio.example.com/public_html && pg_dump ... | gzip > backups/daily_$(date +\%Y\%m\%d).sql.gz
```

The database dump. It has to keep working when the application is broken — and if the worker is
the broken part, backups must not stop with it.

---

## Checking on it

**From the admin panel.** The dashboard has a **Background Worker** card: running / stuck /
stopped, uptime, heartbeat age, queue depth, and whether anything is supervising it at all — a
stopped worker with no supervisor stays stopped, which is the worse of the two problems.
Clicking it lists every periodic task with its last run, duration and error.

**From the shell.**

```bash
$ php radiochatbox.php bot:status
Bot worker status
  Worker          : running
  ├─ pid          : 4130 on web1
  ├─ uptime       : 12m 3s
  ├─ heartbeat    : 0s ago
  └─ jobs handled : 87
  ...

$ php radiochatbox.php bot:schedule
track_poll             every 30s     DUE NOW   last 2026-07-25 14:04:48 (ok)
       Read the stream and record the current track
...
```

`bot:status` exits **2** when the worker is wedged (alive, heartbeat old), so monitoring can
alert on it without parsing the output:

```cron
*/5 * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php bot:status > /dev/null || echo "RadioChatBox worker unhealthy" | mail -s alert you@example.com
```

For the supervisor itself, `systemctl status radiochatbox-daemon@radio.example.com` (or
`daemons:start --once --dry-run` to print what it would reconcile without touching anything).

---

## Several installations on one server

Different directories, often the same code and sometimes the same database name. Anything a
*process* owns is therefore named per installation **directory**. Take two installations that
share both the code and the leaf directory name:

```
/home/siteuser/domains/radio.example.com/public_html
/home/siteuser/domains/news.example.com/public_html
```

Each gets its own locks, and they never collide:

```
logs/worker-public_html-3f9a1b2c.lock              # radio.example.com
var/DAEMON_ORCHESTRATOR-public_html-3f9a1b2c.lock

logs/worker-public_html-7c2e4d10.lock              # news.example.com
var/DAEMON_ORCHESTRATOR-public_html-7c2e4d10.lock
```

`public_html-3f9a1b2c` is the directory name plus a short hash of its absolute path. **Nothing
to configure** — it comes from the path, so a second installation is distinct the moment it
exists. The hash is what does the work here: both paths end in `public_html`, so the visible
part is identical and only the hash of the full path tells them apart. The same is true when
two release paths both end in `current`. And when `logs/` is not writable the lock falls back
to the shared system temp directory, where a name like `worker-public_html.lock` from one
installation would otherwise silently keep the other's worker from ever starting.

A setting would have been worse than useless here: copied along with the directory, it would
recreate the very collision it prevents.

Data is a different scope. The Redis prefix stays `radiochatbox:<database>:`, because that
scopes sessions, caches and chat history — and two installations pointed at one database are
meant to share them. The database name comes from each installation's own configuration file;
nothing is passed on the command line.

Each installation runs its own supervisor and its own worker, and a second supervisor for the
*same* installation refuses to start.

---

## When something is wrong

**No bot replies.** `php radiochatbox.php bot:status`. If the worker is stopped and nothing is
supervising it, nothing was going to restart it — start the supervisor (or its systemd unit).
If the supervisor is up, give it a cycle.

**"Another worker is already running".** Expected: the lock is doing its job. If nothing is
actually running, the lock is stale and is taken over automatically after `--stale-after`
seconds (120 by default).

**A task keeps failing.** `php radiochatbox.php bot:schedule` shows the last error per task, as
does the dashboard card. Run it by hand to see the whole thing:
`php radiochatbox.php bot:run-task cleanup`.

**Replies are delivered late.** Check the queue depth in `php radiochatbox.php bot:status`, and
remember that on cron level 2 or 3 delivery is only as prompt as the cron interval.

**Settings changed but nothing happened.** The worker adopts settings between batches (within a
second or two). If it is a *code* change, the process has to be replaced — the supervisor does
it on the next deploy automatically, or use `bot:worker --watch-files` while developing.

**Everything looks fine but nothing is being written.** Look for a wedged worker: alive,
heartbeat old. The supervisor asks it to restart after five minutes; a single
`php radiochatbox.php daemons:start --once` does it now.
