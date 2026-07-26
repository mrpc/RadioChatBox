# Framework Improvements — gaps to close in PramnosFramework

Things RadioChatBox needs that the framework does **not** support today (or supports differently
enough that a native migration needs a framework-side change first). Each is written as a concrete
enhancement to land in `../PramnosFramework` (the symlinked package), with a BC note — the
framework's own hard rule is *no public signature ever changes; additions only*, so every item
here is **additive**.

Priority key: **P1** = blocks a migration phase; **P2** = needed for full convergence; **P3** =
nice-to-have / quality.

---

## 1. (P3, optional) PDO-compat accessor on `Pramnos\Database\Database`

**Gap.** The framework DB layer is complete (QueryBuilder + ORM + raw SQL) but its driver is
native `pgsql`/`mysqli`, not the PHP `PDO` extension. RadioChatBox has ~25 services holding a
`\PDO` and using `PDO::PARAM_INT` / `PDO::FETCH_KEY_PAIR` / `\PDOException`. Converging them onto
the framework API is the plan (option A), but it's ~25 rewrites.

**Enhancement (optional accelerator).** Add an opt-in PDO handle so `\PDO`-typed code can be moved
under the framework before its SQL is rewritten:
```php
// Additive: only constructs a PDO when explicitly asked; native driver stays the default.
public function getPdo(bool $write = false): \PDO
```
built from the same `Settings->database` config (`pgsql:host=…;dbname=…`). This is a *migration
convenience*, not a new default path.

**Trade-off / recommendation.** It runs a **second** connection alongside the native one and
cuts against the framework's deliberate native-driver design. Prefer the incremental rewrite
(option A). Add this **only** if the rewrite pace blocks production timelines, and mark it
clearly as a transitional compat shim. **BC: additive, no signatures change.**

---

## 2. (P1) Redis pub/sub broadcasting driver + SSE support

**Gap.** RadioChatBox's real-time layer is **SSE + Redis pub/sub** (`public/api/stream.php`
subscribes to prefixed channels `chat:updates`, `chat:user_updates`, `chat:private_messages`).
The framework's `Broadcasting` subsystem is **Pusher-protocol / WebSocket** only —
`BroadcastingManager` + drivers `Null`/`Log`/`Pusher`, `LocalBroadcastServer` (WebSocket),
`broadcast:serve`, `pramnos-echo.js`. There is **no SSE endpoint helper and no Redis pub/sub
transport**. This is the single biggest divergence.

**Enhancement.** Two additive pieces so RadioChatBox can broadcast through the framework
abstraction while keeping SSE + Redis on the wire (no client rewrite):

1. **`Broadcasting\Drivers\RedisDriver implements DriverInterface`** — `broadcast($channel,
   $event, array $payload)` does `$redis->publish($prefix.$channel, json_encode([...]))`.
   Registered by `BroadcastingServiceProvider` when `broadcasting.driver = 'redis'` in `app.php`.
2. **An SSE helper** — e.g. `Broadcasting\SseStream` (or a `Http\Response::eventStream(callable)`
   factory) that encapsulates the `text/event-stream` headers, `ob_end_clean()`, the
   subscribe-loop, periodic ping, and the 95s force-reconnect that `stream.php` hand-rolls today,
   consuming a dedicated Redis subscribe connection.

**Why in the framework, not just the app.** Keeps the `broadcast($channel,$event,$payload)` call
sites framework-native and lets any Pramnos app choose SSE+Redis vs. Pusher by config. RadioChatBox
then deletes its bespoke publish/subscribe glue.

**BC:** additive — new driver + new class; existing Null/Log/Pusher drivers untouched.

---

## 3. (P2) Redis-backed queue driver

**Gap.** The framework queue (`Queue\QueueManager`, `Queue\Worker`, `queue:process`) is
**database-backed** (table `queueitems`, optimistic-lock claiming). RadioChatBox's `JobQueue` is a
**Redis delayed queue** (ZSET `jobs:delayed` + hash `jobs:data`, `claimDue()` via atomic `ZREM`),
chosen because bot-reply delivery is latency-sensitive and already Redis-resident.

**Enhancement.** Introduce a queue **driver abstraction** so the backend is pluggable:
- `Queue\QueueDriverInterface` (`push/claimDue/complete/fail/retry/size`),
- `Queue\Drivers\DatabaseQueueDriver` (refactor of the current `queueitems` logic — behaviour
  unchanged),
- `Queue\Drivers\RedisQueueDriver` (ZSET + hash, mirroring RadioChatBox's semantics: delay,
  `MAX_ATTEMPTS`, backoff, per-DB key prefix).
`QueueManager`/`Worker` select the driver from `app.php` `queue.driver`.

**Why.** Lets RadioChatBox's worker become a framework `ProcessQueue`/`Worker` (supervised by the
orchestrator, §5 of [`01`](01-native-integration-map.md)) **without** moving bot jobs off Redis.

**BC:** additive. Default driver stays `database`, so existing framework apps see no change; the
current `QueueManager` internals move behind `DatabaseQueueDriver` with identical behaviour.

---

## 4. (P1) Router: instantiate non-static `[Class, 'method']` controller actions

**Gap.** `Routing\Route::execute($container)` only handles `is_callable($this->action)`.
Attribute discovery (`RouteDiscovery`) produces `[$classString, $method]` actions, which are
**not** callable for non-static instance methods — the passed `$container` is not used to resolve
/ instantiate the controller. So attribute-routed controller classes with instance methods don't
dispatch. This blocks the clean "attribute-routed controllers" model we want in Phase 6.

**Enhancement.** In `Route::execute()`, when the action is `[Class, 'method']` and the method is
not static, resolve the class through the IoC `Container` (already passed in), instantiate it,
then invoke with the matched URI params (the existing reflection-based param injection is reused).
Fall back to current behaviour for closures/static.

**BC:** additive/behavioural-fix — closures and static actions keep working; the only change is
that a previously-broken case now works. No signature change.

---

## 5. (P1) Graceful degradation when optional extensions are absent

**Gap.** The framework declares `ext-mbstring` required and uses native `pgsql`. RadioChatBox's
current containers/hosts lack `mbstring` and native `pgsql` (only `pdo_pgsql`). A missing
extension currently surfaces as a hard fatal at an unhelpful point.

**Enhancement (defensive, small).**
- A startup **preflight** (in `Application::init()` or a `health:check` gate) that verifies
  required extensions and emits a single clear error (`"mbstring is required"`) instead of a
  deep fatal.
- Where the framework already guards `pcntl`/`posix` with `function_exists`, apply the same
  discipline to any `mb_*` used on non-critical paths, or document `mbstring` as a hard
  prerequisite in the app scaffold.

**BC:** additive — clearer failure, no behaviour change when extensions are present.

> Note: the app-side fix (adding `mbstring`/`pgsql` to the Dockerfile and hosts) is
> [`00`](00-overview-and-bc-strategy.md) §3 and is the actual unblocker. This framework item is
> about turning a cryptic fatal into an actionable message for the next adopter.

---

## 6. (P3) First-class per-installation identity + non-flock lock option

**Gap.** RadioChatBox runs **multiple installations on one host** and needs process-owned names
scoped per directory (`Installation::id()` = `basename-<md5[:8]>`). Its `WorkerLock` deliberately
**avoids `flock()`** because advisory locks silently fail on Docker/macOS bind mounts, using an
atomic `fopen($path,'x')` + JSON heartbeat instead. The framework's `DaemonOrchestrator` singleton
guard uses **`flock`** on `var/DAEMON_ORCHESTRATOR.lock`.

**Enhancement.**
- Add an installation-identity helper (e.g. `Framework\Installation::id()` derived from `ROOT`) so
  orchestrator/queue lock-file and state-file names are namespaced per install out of the box.
- Offer a lock strategy option (flock vs. atomic-create+heartbeat) on the orchestrator, so
  bind-mount deployments can opt into the create-exclusive strategy RadioChatBox proved out.

**BC:** additive — default stays `flock`; new option and helper are opt-in.

---

## 7. (P3) Scheduler persistence / observability parity

**Gap.** RadioChatBox's scheduler records per-task state in a `scheduled_tasks` table
(`last_run_at`, `last_status`, `last_duration_ms`, `runs`, `failures`) — valuable for the admin UI
and monitoring. The framework `Scheduling\Scheduler` is code-defined (`app/schedule.php`) and
stateless beyond `withoutOverlapping()` PID locks.

**Enhancement (optional).** An opt-in run-history recorder for `schedule:run` (write outcomes to a
`schedule_history` table or the existing log channel), so framework-scheduled tasks get the same
observability. Not required if RadioChatBox keeps its in-worker scheduler (see
[`01`](01-native-integration-map.md) §6) — offered for the case where we consolidate on the
framework scheduler.

**BC:** additive — off by default.

---

## 8. (P3) Config bridge helper for `.env`-driven `settings.php`

**Gap.** The framework reads DB/cache credentials from the `settings.php` `database`/`cache`
arrays, and separately supports `.env` via `envvar()`. RadioChatBox is `.env`-first. The wiring
"`.env` → `settings.php`" is app-side boilerplate every adopter repeats.

**Enhancement (minor DX).** A documented helper/recipe (or a `Settings::fromEnv()` convenience) to
assemble the `database`/`cache` settings from standard `DB_*`/`REDIS_*` env keys, so `.env`-first
apps like RadioChatBox have a one-liner. Pure convenience.

**BC:** additive.

---

## Summary

| # | Improvement | Priority | Blocks | BC |
|---|---|---|---|---|
| 1 | Optional PDO-compat accessor | P3 | (accelerates DB convergence) | additive |
| 2 | Redis broadcasting driver + SSE helper | **P1** | real-time migration | additive |
| 3 | Redis queue driver (driver abstraction) | P2 | worker/queue convergence | additive |
| 4 | Router instance-method controller dispatch | **P1** | attribute-routed controllers (Phase 6) | fix, additive |
| 5 | Graceful missing-extension diagnostics | **P1** | clean bootstrap on our hosts | additive |
| 6 | Per-install identity + non-flock lock option | P3 | multi-install on bind mounts | additive |
| 7 | Scheduler persistence/observability | P3 | scheduler consolidation | additive |
| 8 | `.env` → `settings.php` config bridge | P3 | DX | additive |

Only **#2, #4, #5** are on the critical path for the phases in
[`03`](03-integration-plan.md); the rest support full convergence.
