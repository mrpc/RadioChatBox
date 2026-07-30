# Background processing

Everything that happens outside a web request — bot replies, statistics, cleanup,
radio track history — is done by a small set of **per-feature workers**, and the
workers are kept alive by one **supervisor**.

```
systemd (or cron)
   └── radiochatbox.php daemons:start          the supervisor
         ├── radiochatbox.php stats:start        statistics snapshots + aggregations   (core)
         ├── radiochatbox.php maintenance:start  bans/blocks/sessions/messages cleanup  (core)
         ├── radiochatbox.php tracker:start      radio track polling + enrichment       (if a stream URL is set)
         └── radiochatbox.php bot:start          LLM auto-replies + bot housekeeping     (if bots are enabled)
```

Each worker owns one feature, has its own single-instance lock, and does one kind
of work. The supervisor spawns **only what the installation needs** — it reads the
feature settings every reconcile cycle, so enabling a feature starts its worker and
disabling it stops the worker gracefully. The bots are an optional feature; the
radio tracker only runs once a stream status URL is configured; statistics and
maintenance are core and always run.

All are console commands (`src/ConsoleCommands`, run through `radiochatbox.php`).
Inside the containers, `./radiochatbox` forwards to the same entry point. If you
have neither systemd nor a supervisor, [cron covers everything](#cron-fallback).

---

## Quick reference

```bash
php radiochatbox.php daemons:start              # the process to supervise (interval 10s)
php radiochatbox.php daemons:start --once       # one reconciliation pass, then exit  ← cron fallback
php radiochatbox.php daemons:start --once --dry-run   # show which workers it would run, change nothing
php radiochatbox.php daemons:start --interactive      # live dashboard of every managed worker

# The feature workers (each also accepts --once / --max-runtime=N / --watch-files):
php radiochatbox.php stats:start                # statistics snapshots + aggregations
php radiochatbox.php maintenance:start          # bans/blocks/sessions/messages cleanup
php radiochatbox.php tracker:start              # radio track polling + enrichment
php radiochatbox.php bot:start                  # LLM auto-replies + bot housekeeping

# Bot diagnostics / one-shots:
php radiochatbox.php bot:status                 # worker health, queue depth, task list (exit 2 = wedged)
php radiochatbox.php bot:schedule               # every periodic task: cadence, last run, due now
php radiochatbox.php bot:run-task cleanup       # run one periodic task by hand
php radiochatbox.php bot:log --problems         # recent LLM calls that failed
```

There is no `stop`/`restart` subcommand: a deploy restart is **automatic** (see
[below](#what-the-supervisor-does)), and the supervisor's own lifecycle is
`systemctl` (or Ctrl+C).

---

## The workers

| Worker | Runs | Gated by |
|---|---|---|
| `stats:start` | `stats_snapshot`, `stats_hourly/daily/weekly/monthly/yearly` | core (always) |
| `maintenance:start` | `cleanup` (expired bans/DM blocks, stale sessions, old messages) | core (always) |
| `tracker:start` | `track_poll` (30s, `track_poll_seconds` setting), `track_enrich` | a `radio_status_url` is configured |
| `bot:start` | the LLM reply/deliver queue + `llm_balance_snapshot`, `prune_llm_log` | `bot_replies_enabled` |

The periodic tasks live in one `Services\Scheduler`, each tagged with a group; a
worker runs only its group (`runDue($group)`). Each task records its own last run,
duration and error in `scheduled_tasks`, is retried on its next interval if it
fails, and never blocks its worker for long. The bot worker additionally drains the
Redis delayed reply queue between ticks (see [BOT_REPLIES.md](BOT_REPLIES.md)).

The stream is polled every 30 seconds because the current track has to be caught
while it is playing; recording de-duplicates atomically, so nothing is written
until the track actually changes. Tune it with the `track_poll_seconds` setting
(10–3600s) — no deploy needed; the tracker adopts it live.

---

## Production setup (systemd)

The **supervisor** is the only thing systemd runs — it starts and supervises the
workers itself. One unit per installation:

`/etc/systemd/system/radiochatbox-daemon@.service`

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

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now radiochatbox-daemon@radio.example.com
journalctl -u radiochatbox-daemon@radio.example.com -f
```

Do **not** add units for the individual workers — starting them is the supervisor's
job, and a second copy would just be refused by the lock. Stopping the supervisor
(`systemctl stop …`) exits it and releases its lock; a job in flight finishes first.

---

## What the supervisor does

Every cycle (10s by default, `--interval`) it compares the workers that should run
(from the feature settings) with what is running:

| It finds | It does |
|---|---|
| a needed worker not running | starts it |
| a lock left behind, pid gone (crash, `kill -9`, OOM) | starts a replacement |
| a worker alive but with no heartbeat for 5 minutes | asks it to restart |
| a worker running for a feature now disabled | asks it to stop |
| a newly deployed commit | asks every worker to come back on the new code |
| a healthy set | nothing |

**Stopping is always a request, never a kill.** It writes a `.stop` file next to the
worker's lock; the worker notices between jobs (its `shouldStop()`) and exits
cleanly. **A deployment is a new commit**, read straight from `.git/HEAD` — when it
changes, every worker is asked to restart on the new code, with no manual step.
Deploy detection runs only in the continuous loop; in the [cron-once model](#level-1--cron-drives-the-supervisor-recommended-without-systemd)
run the workers with `--watch-files`, or restart the service after deploying.

Why a supervisor at all: PHP cannot reload code into a running process, so a deploy
means *replacing* processes, and something has to do the replacing.

---

## Cron fallback

You do not need systemd, nor a permanently running process. Cron can drive the same
code, at the granularity of the cron interval. Pick **one** level; the lock and the
per-task last-run state stop anything running twice.

### Level 1 — cron drives the supervisor (recommended without systemd)

```cron
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php daemons:start --once >> logs/daemon.log 2>&1
```

Every minute the supervisor does one reconciliation pass: it starts any needed
worker that is missing and replaces a crashed one. The workers keep running between
passes, so replies stay second-accurate. Add `--watch-files` to the workers (not
possible per-worker from here — restart the service after a deploy instead).

### Level 2 — cron drives the scheduler directly (no daemons)

One line runs whatever periodic task is due (all groups), via the framework
scheduler over `app/schedule.php`:

```cron
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php schedule:run >> logs/schedule.log 2>&1
```

If the bots are enabled, drain the reply queue too (only prompt to the minute):

```cron
* * * * * cd /home/siteuser/domains/radio.example.com/public_html && php radiochatbox.php bot:start --once >> logs/bot.log 2>&1
```

Do **not** run `schedule:run` alongside the worker daemons — they do not share the
per-task last-run state, so together they would double-execute. Run the daemons, or
cron, not both.

### What always stays in cron

```cron
0 2 * * * cd /home/siteuser/domains/radio.example.com/public_html && pg_dump ... | gzip > backups/daily_$(date +\%Y\%m\%d).sql.gz
```

The database dump has to keep working when the application is broken.

---

## Checking on it

**Admin panel.** The dashboard's **Background Worker** card shows the bot worker:
running / stuck / stopped, uptime, heartbeat age, queue depth, and whether anything
is supervising it. Clicking it lists every periodic task with its last run.

**Shell.**

```bash
$ php radiochatbox.php daemons:start --once --dry-run   # which workers would run, and their state
$ php radiochatbox.php bot:status                       # bot worker health (exit 2 = wedged)
$ php radiochatbox.php bot:schedule                     # every task, cadence, last run, due now
```

`bot:status` exits **2** when the bot worker is wedged, so monitoring can alert on
it. For the supervisor, `systemctl status radiochatbox-daemon@radio.example.com`.

---

## Several installations on one server

Different directories, often the same code and sometimes the same database name.
Anything a *process* owns is named per installation **directory**, so each
installation's workers get their own locks and never collide:

```
logs/worker-public_html-3f9a1b2c.lock          # bot,          radio.example.com
logs/stats-public_html-3f9a1b2c.lock           # stats
logs/tracker-public_html-3f9a1b2c.lock         # tracker
logs/maintenance-public_html-3f9a1b2c.lock     # maintenance
var/DAEMON_ORCHESTRATOR-public_html-3f9a1b2c.lock
```

`public_html-3f9a1b2c` is the directory name plus a short hash of its absolute path
— nothing to configure, and distinct the moment a second installation exists.

Data is a different scope: the Redis prefix stays `radiochatbox:<database>:`, which
scopes sessions, caches and chat history — two installations pointed at one database
share them. Each installation runs its own supervisor and its own set of workers.

---

## When something is wrong

**No bot replies.** `php radiochatbox.php bot:status`. If the bot worker is stopped
and nothing supervises it, start the supervisor. Also check `bot_replies_enabled` —
with the feature off, the supervisor never starts the bot worker.

**"Already running".** Expected: the lock is doing its job. A genuinely stale lock is
taken over automatically after `--stale-after` seconds (120 by default).

**A task keeps failing.** `php radiochatbox.php bot:schedule` shows the last error per
task; run it by hand with `php radiochatbox.php bot:run-task <name>`.

**Track / stats not updating.** Confirm `radio_status_url` is set (the tracker only
runs when it is) and check the supervisor is up; `daemons:start --once --dry-run`
shows whether `tracker:start` / `stats:start` are in the plan.

**Settings changed but nothing happened.** Workers adopt settings between ticks
(within a second or two). A *code* change needs a process replacement — automatic on
the next deploy, or `--watch-files` while developing.

**Everything looks fine but nothing is written.** Look for a wedged worker (alive,
heartbeat old). The supervisor restarts it after five minutes; a single
`php radiochatbox.php daemons:start --once` does it now.
