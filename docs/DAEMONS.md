# Background processing

Everything that happens outside a web request — bot replies, statistics, cleanup, track
history — is done by one **worker**, and the worker is kept alive by one **supervisor**.

```
systemd (or cron)
   └── daemon.php run          the supervisor: keeps the worker alive, restarts it on deploy
         └── worker.php run    the worker: delivers bot replies and runs the periodic tasks
```

That is the whole picture. The supervisor is the only thing that needs watching from
outside; if you have neither systemd nor a supervisor, [cron covers
both](#cron-fallback).

---

## Quick reference

```bash
php daemon.php run                 # the process to supervise (interval 10s)
php daemon.php once                # one reconciliation pass, then exit  ← cron fallback
php daemon.php status              # supervisor + every daemon (exit 2 = unhealthy)
php daemon.php restart             # ask every worker to come back on the current code
php daemon.php stop                # ask them to stop after the job in hand

php worker.php status              # worker health, queue depth, task list
php worker.php once --schedule     # process what is due + run due tasks  ← cron fallback
php worker.php schedule            # cadence, last run, what is due now
php worker.php run-task cleanup    # run one periodic task by hand
php worker.php log --problems      # recent LLM calls that failed
```

In development, through the containers: `./radiochatbox daemon status`,
`./radiochatbox bot schedule`.

---

## Production setup (systemd)

The supervisor is what systemd runs. One unit per installation, because several can share
a server:

`/etc/systemd/system/radiochatbox-daemon@.service`

```ini
[Unit]
Description=RadioChatBox daemon supervisor (%i)
After=network.target postgresql.service redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/%i
ExecStart=/usr/bin/php /var/www/%i/daemon.php run
ExecStop=/usr/bin/php /var/www/%i/daemon.php stop

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

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now radiochatbox-daemon@mysite
journalctl -u radiochatbox-daemon@mysite -f
```

Note what is **not** in the unit: the worker. Starting it is the supervisor's job, and
letting systemd start it as well would mean two of them.

---

## What the supervisor does

Every cycle (10s by default) it compares what should be running with what is:

| It finds | It does |
|---|---|
| no worker | starts one |
| a lock left behind, pid gone (crash, `kill -9`, OOM) | starts a replacement |
| a worker alive but with no heartbeat for 5 minutes | asks it to restart — the case a plain pid check calls healthy |
| a newly deployed commit | asks every worker to come back on the new code |
| a healthy worker | nothing |

**Stopping is always a request, never a kill.** It writes a `.stop` file next to the
worker's lock; the worker notices between jobs and exits cleanly. A job cut in half is
worse than a job finished late, and the next cycle finds the slot empty and fills it.

**A deployment is a new commit**, read straight from `.git/HEAD` (and `packed-refs` after
a `git gc`) — no `git` binary needed. A commit hash changes once per deploy, whereas file
timestamps change on every editor save. If the release is not a checkout, deploy detection
is simply skipped rather than guessed at, and you restart with
`php daemon.php restart` in your deploy script.

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

Each task records its own last run, duration and error in `scheduled_tasks`. A task that
fails is logged and retried on its next interval — it never takes the worker down, and
never blocks the reply queue for long (at most two tasks run per cycle).

The stream is polled every 30 seconds because the current track has to be caught while it
is playing: bundled with the five-minute snapshot, a three-minute song was missed
entirely. It is cheap — recording de-duplicates atomically, so nothing is written until
the track actually changes.

---

## Cron fallback

You do not need systemd, and you do not need a permanently running process at all. Cron
can drive the same code, with the granularity of the cron interval. Pick **one** of the
levels below; they all guard against overlap through the same lock, so a slow run can
never double up.

### Level 1 — cron drives the supervisor (recommended without systemd)

```cron
* * * * * cd /var/www/mysite && php daemon.php once >> logs/daemon.log 2>&1
```

Every minute the supervisor does exactly one reconciliation pass and exits: it starts the
worker if it is missing, replaces a crashed one, and asks a wedged one to restart. The
worker itself keeps running continuously between passes, so bot replies stay
second-accurate. A worker that dies is back within a minute.

Deploy detection is skipped in this mode — one pass cannot compare two commits — so add a
line to your deploy script:

```bash
php daemon.php restart      # workers come back on the new code
```

### Level 2 — cron drives the worker directly

No supervisor at all. Two shapes:

```cron
# A worker that lives for one minute at a time.
* * * * * cd /var/www/mysite && php worker.php run --schedule --max-runtime=55 >> logs/worker.log 2>&1
```

The worker runs for 55 seconds, exits, and the next minute's cron starts a fresh one.
Replies stay second-accurate while it is up, with a gap of a few seconds each minute. A
new deploy is picked up automatically, because each minute is a new process.

```cron
# Or: process whatever is due, then exit.
* * * * * cd /var/www/mysite && php worker.php once --schedule >> logs/worker.log 2>&1
```

Cheapest of all, and enough when replies do not need to be prompt: a reply scheduled for
10 seconds from now is delivered at the next minute boundary instead. `--schedule` means
this single line also runs the periodic tasks, so you do not need the entries below.

### Level 3 — the original separate entries

Still supported, and what an existing installation already has. Use this if you would
rather not move the schedule into the worker:

```cron
*/5 * * * * cd /var/www/mysite && php stats-cron.php snapshot >> logs/stats-cron.log 2>&1
5   * * * * cd /var/www/mysite && php stats-cron.php hourly   >> logs/stats-cron.log 2>&1
10  0 * * * cd /var/www/mysite && php stats-cron.php daily    >> logs/stats-cron.log 2>&1
0   * * * * curl -s "https://mysite/api/cron/cleanup.php?token=SECRET" > /dev/null
0   3 * * * cd /var/www/mysite && php worker.php prune-log    >> logs/worker.log 2>&1
* * * * *   cd /var/www/mysite && php worker.php run --max-runtime=55 >> logs/worker.log 2>&1
```

Two things to know if you stay here: the track is only recorded every five minutes (short
songs are missed), and cleanup runs through a token-authenticated public URL. Both are why
the schedule moved into the worker.

**Mixing levels is safe.** Every task records its own last run, so the crontab and the
worker cannot run the same task twice inside its interval, and the lock stops two workers
from existing at once. Migrate one line at a time and delete the old entry when you are
satisfied.

### What always stays in cron

```cron
0 2 * * * cd /var/www/mysite && pg_dump ... | gzip > backups/daily_$(date +\%Y\%m\%d).sql.gz
```

The database dump. It has to keep working when the application is broken — and if the
worker is the broken part, backups must not stop with it.

---

## Checking on it

**From the admin panel.** The dashboard has a **Background Worker** card:
running / stuck / stopped, uptime, heartbeat age, queue depth, and whether anything is
supervising it at all — a stopped worker with no supervisor stays stopped, which is the
worse of the two problems. Clicking it lists every periodic task with its last run,
duration and error.

**From the shell.**

```bash
$ php daemon.php status
installation mysite-3f9a1b2c (/var/www/mysite) | supervisor pid 4123, heartbeat 2s ago
bot_worker   running   pid 4130   heartbeat 0s ago   jobs 87
             Bot replies and the periodic tasks

$ php worker.php schedule
track_poll             every 30s     DUE NOW   last 2026-07-25 14:04:48 (ok)
       Read the stream and record the current track
...
```

`status` exits **2** when something is unhealthy, so monitoring can alert on it without
parsing the output:

```cron
*/5 * * * * cd /var/www/mysite && php daemon.php status > /dev/null || echo "RadioChatBox daemons unhealthy" | mail -s alert you@example.com
```

---

## Several installations on one server

Different directories, often the same code and sometimes the same database name. Anything
a *process* owns is therefore named per installation **directory**:

```
logs/daemon-supervisor-mysite-3f9a1b2c.lock
logs/worker-mysite-3f9a1b2c.lock
```

`mysite-3f9a1b2c` is the directory name plus a short hash of its absolute path. **Nothing
to configure** — it comes from the path, so a second installation is distinct the moment
it exists. The hash matters because two release paths can both end in `current`, and
because when `logs/` is not writable the lock falls back to the shared system temp
directory, where a name like `worker-radiochatbox.lock` from one installation would
silently keep the other's worker from ever starting.

A setting would have been worse than useless here: copied along with the directory, it
would recreate the very collision it prevents.

Data is a different scope. The Redis prefix stays `radiochatbox:<database>:`, because that
scopes sessions, caches and chat history — and two installations pointed at one database
are meant to share them. The database name comes from each installation's own
configuration file; nothing is passed on the command line.

Each installation runs its own supervisor and its own worker, and a second supervisor for
the *same* installation refuses to start.

---

## When something is wrong

**No bot replies.** `php daemon.php status`. If the worker is stopped and the supervisor
is too, nothing was going to restart it — start the supervisor. If the supervisor is up,
give it a cycle.

**"Another worker is already running".** Expected: the lock is doing its job. If nothing is
actually running, the lock is stale and is taken over automatically after
`--stale-after` seconds (120 by default).

**A task keeps failing.** `php worker.php schedule` shows the last error per task, as does
the dashboard card. Run it by hand to see the whole thing: `php worker.php run-task cleanup`.

**Replies are delivered late.** Check the queue depth in `php worker.php status`, and
remember that on cron level 2 or 3 delivery is only as prompt as the cron interval.

**Settings changed but nothing happened.** The worker adopts settings between batches
(within a second or two). If it is a *code* change, the process has to be replaced:
`php daemon.php restart`, or `--watch-files` while developing.

**Everything looks fine but nothing is being written.** Look for a wedged worker: alive,
heartbeat old. The supervisor asks it to restart after five minutes; `php daemon.php once`
does it now.
