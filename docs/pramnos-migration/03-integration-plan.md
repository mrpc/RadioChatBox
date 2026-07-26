# Integration Plan — adopting the framework's better implementations

Concrete, ordered, per-phase execution plan. Each phase lists: **goal**, **files touched**,
**steps**, **acceptance test**, **rollback**. Phases map to the roadmap in
[`00`](00-overview-and-bc-strategy.md) §5 and are independently shippable. Per the project's
testing discipline: every new code path gets high coverage, every converted behaviour gets a
regression test, and every test carries a what/why docblock.

Where a phase depends on a framework change, the [`02`](02-framework-improvements.md) item is
named as a prerequisite.

---

## Phase 0 — Prerequisites (infra only, no app logic)

**Goal.** Make the framework loadable in every environment.

**Files.** `Dockerfile`, `composer.json` (already done), host provisioning
(`setup-production.sh` / docs).

**Steps.**
1. `Dockerfile`: `RUN docker-php-ext-install mbstring pgsql` (add to the existing
   `docker-php-ext-install` line). `mbstring` required now; `pgsql` for later DB convergence.
2. Bare-metal: `apt-get install php8.3-mbstring php8.3-pgsql`; restart PHP-FPM. Add to
   `setup-production.sh` prereq checks.
3. Commit the `composer.json` path-repo + require change already made on this branch.

**Acceptance.** In the built container: `php -m | grep -E 'mbstring|pgsql'` shows both;
`php -r 'require "vendor/autoload.php"; new Pramnos\Cache\Cache();'` runs without fatal.

**Rollback.** Revert `composer.json`; `composer install` removes the framework entirely
(`vendor`/`composer.lock` are gitignored).

---

## Phase 1 — Bootstrap coexistence

**Goal.** Framework config/bootstrap available; nothing on the request path changes yet.

**Files (new).** `app/app.php`, `app/settings/settings.php`, `bin/radiochatbox` (thin
`pramnos` forwarder), a small bootstrap include.

**Steps.**
1. `app/settings/settings.php` returns `['database' => [...], 'cache' => [...]]` sourced from
   `.env` via `envvar()`:
   ```php
   return [
     'database' => [
       'hostname' => envvar('DB_HOST','postgres'), 'port' => (int) envvar('DB_PORT',5432),
       'database' => envvar('DB_NAME'), 'user' => envvar('DB_USER'),
       'password' => envvar('DB_PASSWORD'), 'type' => 'postgresql', 'schema' => 'public',
     ],
     'cache' => [
       'method' => 'redis', 'hostname' => envvar('REDIS_HOST','redis'),
       'port' => (int) envvar('REDIS_PORT',6379), 'caching' => true,
       'prefix' => 'radiochatbox:'.envvar('DB_NAME').':',
     ],
   ];
   ```
2. `app/app.php` returns app info + `migration_cutoff` (set in Phase 2) + `features` (start empty)
   + later `middleware`/`storage`/`broadcasting`/`queue` sections.
3. **Bridge the data layer config:** `RadioChatBox\Database::getPDO()` reads its DSN from
   `Pramnos\Application\Settings::getSetting('database')` (falling back to `Config` if Settings
   isn't loaded) — one config truth, PDO still in charge.
4. `Config` becomes a thin shim over `envvar()` (or is left as-is; no rush).

**Acceptance.** Existing endpoints and worker behave identically (full test suite green);
`Settings::getSetting('database')->hostname` returns the right host; the app still connects via
PDO.

**Rollback.** Delete `app/` + bootstrap include; `Database` falls back to `Config`.

---

## Phase 2 — Console + tracked migrations (framework is clearly better)

**Goal.** Replace 4 ad-hoc migration runners + `radiochatbox` bash wrapper with
`vendor/bin/pramnos` and the tracked `schemaversion` runner — **without recreating any table**.

**Files (new).** `app/migrations/YYYY_MM_DD_HHmmss_<slug>.php` (×25), optional
`app/migrations/sql/*.sql` (verbatim bodies), console-app subclass registering custom commands.
**Files (changed).** `radiochatbox` → forwards `migrate`/`stats`/`daemon` to `pramnos`;
`deploy.sh` (migrations step → `pramnos migrate`); `docker-compose.yml` (drop initdb migration
mount once the runner owns it). **Legacy SQL** in `database/migrations/*.sql` is retained for
history but no longer the runner.

**Steps.**
1. Convert each `NNN_*.sql` to a `Pramnos\Database\Migration` subclass. Lowest-risk style keeps
   the idempotent SQL verbatim:
   ```php
   class AddReplyToMessages extends \Pramnos\Database\Migration {
     protected $description = 'Add reply_to to messages'; protected $feature = 'core';
     public function up(): void {
       $this->DB()->statement(file_get_contents(__DIR__.'/sql/001_add_reply_to_messages.sql'));
     }
     public function down(): void { /* baselined: no-op or best-effort reverse */ }
   }
   ```
   Assign filename timestamps in the original `NNN` order (e.g. `2024_01_01_000001_…` ascending),
   all **below** the cutoff.
2. **Baseline:** set `app.php` `'migration_cutoff' => '2024_12_31_235959'` (after all 25). The
   framework filters pre-cutoff files before loading them → the existing schema is treated as
   applied; only *new* migrations (timestamp above cutoff) ever run.
3. One-off (kept out of git per convention): seed `schemaversion` with a `result=1` row per
   baselined slug so `migrate:status` reports truthfully.
4. Point all migration entry points at `pramnos migrate`; fix the broken
   `radiochatbox bot → bot-worker.php` reference while touching the wrapper.

**Acceptance (the critical BC test).** Restore a **production DB dump**, run
`vendor/bin/pramnos migrate:status` → **zero pending**, and `pramnos migrate` **creates/alters
nothing**. Add an automated test that runs the full migration set against a dump-seeded DB and
asserts schema is unchanged. Then add one genuinely new migration and confirm it (and only it)
runs.

**Rollback.** Keep the legacy `*.sql` + `radiochatbox migrate` path available; revert the wrapper
change. `schemaversion` is inert if unused.

---

## Phase 3 — Daemon orchestrator (framework replaces `DaemonSupervisor`)

**Goal.** Supervise the worker with `Pramnos\Console\DaemonOrchestrator` — gaining crash respawn,
heartbeat-staleness restart, `/proc` dedup, graceful `.stop`, singleton guard, git-HEAD redeploy
detection — **without changing worker internals**.

**Files (new).** `src/Console/RadioChatBoxDaemons.php` (a `DaemonOrchestrator` subclass),
registration in the console app. **Files (changed/retired).** `daemon.php` becomes a thin shim to
`pramnos daemons:start` (kept for BC); `docs/DAEMONS.md`; the systemd unit
(`ExecStart=… pramnos daemons:start`).

**Steps.**
1. Subclass `DaemonOrchestrator`:
   ```php
   protected function buildDesiredProcesses(): array {
     return [[
       'id'=>'bot_worker','daemon'=>'worker','workerId'=>1,
       'lockFile'=>VAR_PATH.'/worker.lock',
       'shellCommand'=>PHP_BINARY.' '.ROOT.'/worker.php run --schedule', // existing worker, unchanged
     ]];
   }
   protected function getEntryPoint(): string { return ROOT.'/bin/radiochatbox'; }
   protected function getDashboardTitle(): string { return 'RadioChatBox Daemons'; }
   ```
2. Map the orchestrator's `.stop` sentinel + heartbeat-staleness to what `worker.php` already
   honours (it watches a `.stop` file and heartbeats a lock). If lock paths differ, point the
   orchestrator's `lockFile` at the worker's existing lock so the two agree.
3. Keep `DEPLOY_CHECK`/git-HEAD detection: the orchestrator already parses `.git/HEAD` every 60s —
   drop the equivalent code from `DaemonSupervisor`.
4. Switch systemd `ExecStart`/`ExecStop` to the orchestrator command; keep one instance per
   installation (see [`02`](02-framework-improvements.md) §6 for the per-install identity /
   non-flock lock option if bind-mount `flock` is unreliable).

**Acceptance.** On a staging box: kill the worker → orchestrator respawns it; touch the `.stop`
sentinel → worker exits cleanly and is refilled; change `.git/HEAD` → graceful restart; start two
orchestrators → second refuses (singleton). Each is a test mirroring a `DaemonSupervisor`
guarantee.

**Rollback.** Revert systemd to `daemon.php run`; the `daemon.php` shim still supervises via the
old code path.

---

## Phase 4 — Scheduling (decision documented, low-risk)

**Goal.** Bring scheduling under the framework where beneficial, without losing the in-worker
model's advantages.

**Decision.** **Keep** the in-worker `Scheduler` + `scheduled_tasks` (superior for this workload —
warm connections, no per-minute spawn, admin-visible history). **Optionally** mirror the task list
in `app/schedule.php` for admins who prefer a `schedule:run` cron. See
[`01`](01-native-integration-map.md) §6.

**Steps (only if mirroring).** Define each of the 11 tasks in `app/schedule.php`
(`->everyFiveMinutes()`, `->cron()`, `->at()`, `->withoutOverlapping()`); add the single
`* * * * * php pramnos schedule:run` cron line; for parity of observability, land
[`02`](02-framework-improvements.md) §7.

**Acceptance.** In mirror mode, tasks fire at the same cadence and `scheduled_tasks` is still
updated (no double-runs — `withoutOverlapping()` + the existing per-task last-run guard).

**Rollback.** Remove the cron line; in-worker scheduler is untouched.

---

## Phase 5 — Cross-cutting infrastructure (framework is better; low risk)

**Goal.** Replace hand-rolled infra with framework equivalents behind existing seams.

### 5a. Cache (PSR-16 over Redis)
Replace per-service `setex`/`get` with `Pramnos\Cache\Cache` (`method=>'redis'`) and `remember()`;
expose PSR-16 via `SimpleCache` where a library wants it. Preserve the per-DB key prefix.
**Acceptance:** cache hit/miss parity test; `remember()` computes once. **Watch:** `load()`
returns `false` on miss — use `remember()`.

### 5b. Logging (PSR-3 channels)
Replace scattered `error_log()` with `Pramnos\Logs\Logger` channels (`worker`, `bot`, `http`,
`deploy`). Hand `PsrLogger` to `LlmService`/HTTP client. **Acceptance:** entries land in
`LOG_PATH/logs/<channel>.log`.

### 5c. Media + Storage (artwork/photos)
Move GD resize/crop in `PhotoService`/`ArtworkService` to `Media\ResizeTools`; use
`Media\MediaObject` (dedup + thumbnails + usage tracking) and `Storage\StorageManager` with a
`local` disk for `public/uploads/artwork` (config-switch to `s3` later). **Acceptance:** uploaded
artwork produces identical thumbnail sizes; MD5 dedup verified.

### 5d. Health
Replace standalone `public/api/health.php` with `HealthRegistry::register()` (built-in
`DatabaseConnectivityCheck` + custom Redis and SSE checks) exposed via `Controllers\Health::check()`
(JSON, correct HTTP status). **Acceptance:** 200 when healthy, 503 when Redis down.

### 5e. Validation (shape only)
Use `Validation\Validator::validate($data, $rules)` / `FormRequest` for endpoint input shape;
**keep** `MessageFilter` for moderation (URL whitelist, spam, auto-ban) — business logic, not
generic validation. **Acceptance:** invalid payloads rejected with structured errors; moderation
unchanged.

**Rollback.** Each 5x is an isolated seam swap — revert independently.

---

## Phase 6 — HTTP layer & middleware (opt-in, endpoint-by-endpoint)

**Goal.** Introduce a front controller + Router (attribute routes) + the native middleware
pipeline, migrating `public/api/*.php` one endpoint at a time. File-per-endpoint keeps serving
until each is moved.

**Prerequisite.** [`02`](02-framework-improvements.md) §4 (Router instance-method dispatch) must
land first, or use closures/static actions.

**Files (new).** a front controller/dispatcher; `src/Http/Controllers/*` (framework controllers);
`app/app.php` `middleware` section. **Files (retired incrementally).** individual
`public/api/*.php` as each is migrated.

**Steps.**
1. Front controller builds a `Http\Request`, runs the **global** pipeline, and calls
   `Router::dispatch()`. Global middleware (from `app.php`): `CorsMiddleware::fromApplicationSettings()`,
   `JsonResponseMiddleware`, `MaintenanceModeMiddleware`.
2. Map hand-rolled concerns to middleware:
   `CorsHandler::handle()` → `CorsMiddleware`; `AdminAuth::authenticate()` →
   `UnifiedAuthMiddleware` (Bearer JWT **or** session+CSRF); rate limits → `RateLimitMiddleware`
   (sliding window over the Cache/Redis layer); add `CsrfMiddleware` for admin POSTs.
3. Migrate endpoints in dependency order — simplest read-only GETs first (e.g.
   `history`, `users`), then writes (`send`, `login`), admin endpoints, and **SSE last**
   (`stream.php` becomes a streaming controller action, or stays raw — see §7 of
   [`01`](01-native-integration-map.md)).
4. Each migrated controller returns `Response::json(...)`.

**Acceptance (per endpoint).** A request test asserting identical status code, headers, and JSON
body between the new controller and the legacy file it replaces (capture golden responses from the
current app first). CORS/auth/rate-limit behaviours covered explicitly.

**Rollback.** Remove the route; the legacy `public/api/<x>.php` file (kept until the route is
proven) resumes serving. Front controller and migrated endpoints coexist throughout.

---

## Phase 7 — DB convergence + remaining framework enhancements

**Goal.** Retire the PDO bridge (option A) and land the non-critical framework improvements.

**Steps.**
1. **DB convergence, one service at a time** (simplest → hardest; `ChatService`/`BotService`
   last): rewrite PDO calls onto `prepareQuery()`/`query()`/`statement()` (verbatim SQL) and
   `queryBuilder()`/`OrmModel` (refactored code). `RETURNING` → `->returning()`; `ON CONFLICT
   DO NOTHING` → `insertOrIgnore()`; `ON CONFLICT DO UPDATE` → `upsert()`; stored-function calls
   unchanged. Each service ships with a regression test (identical results vs. PDO).
2. **Real-time:** land [`02`](02-framework-improvements.md) §2 (Redis broadcasting driver + SSE
   helper); switch app broadcasts to `broadcast($channel,$event,$payload)`; delete bespoke Redis
   pub/sub glue. Wire remains SSE+Redis.
3. **Queue/worker:** land [`02`](02-framework-improvements.md) §3 (Redis queue driver); reshape
   `worker.php` into a `ProcessQueue`/`Worker` subclass with task handlers, backed by the Redis
   driver; orchestrator supervises `queue:process --daemon` instead of raw `worker.php`.
4. **Retire the PDO bridge** once no service uses `getPDO()`; drop the optional accessor if it was
   added.

**Acceptance.** Full suite green across all conversions; SSE clients unaffected; bot-reply latency
unchanged; a soak test of the orchestrator+worker under load.

**Rollback.** Per-service reverts (each is isolated); the Redis broadcasting/queue drivers are
additive framework code that can be toggled off via `app.php` config back to the previous glue.

---

## Cross-phase test strategy

- **Golden-response capture:** before Phase 6, snapshot current responses for every endpoint
  (status/headers/body) to diff migrated controllers against.
- **DB dump fixture:** a sanitised production dump drives the Phase 2 baselining test and every
  Phase 7 service regression test (real PG semantics, all the `RETURNING`/`ON CONFLICT`/
  stored-function paths).
- **Daemon chaos tests:** crash/stop/deploy/duplicate scenarios (Phase 3).
- **Coverage:** >90% on new code per subsystem; one regression test per converted behaviour; each
  test documents what it checks and why (project convention).
