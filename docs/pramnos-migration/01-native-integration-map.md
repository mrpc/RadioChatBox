# Native Integration Map — RadioChatBox → PramnosFramework

A 1:1 mapping of every RadioChatBox subsystem onto its PramnosFramework counterpart, with the
concrete framework classes/methods to call. This is the "what maps to what" reference; the
"in what order and how safely" lives in [`00`](00-overview-and-bc-strategy.md) and
[`03`](03-integration-plan.md).

Legend: **Keep** = no framework equivalent in the BC path, stays as-is; **Adopt** = framework
version is better, switch to it; **Bridge** = keep our impl but feed it from the framework;
**Enhance** = needs a framework change first (see [`02`](02-framework-improvements.md)).

---

## Subsystem map at a glance

| RadioChatBox today | File(s) | PramnosFramework | Action |
|---|---|---|---|
| PDO data access | `src/Database.php::getPDO()` + ~25 services | `Pramnos\Database\Database` (QueryBuilder + ORM + raw SQL, native pgsql driver) | **Adopt** incrementally; **Bridge** in transition |
| Redis (cache + pub/sub + queue) | `src/Database.php::getRedis*()` | `Pramnos\Cache\*` (Redis adapter) | **Bridge**: cache→Adopt, pub/sub→Keep, queue→Enhance |
| `.env` infra config | `src/Config.php` | `envvar()` + `Pramnos\Application\Settings` | **Adopt** |
| DB `settings` table | `src/SettingsService.php` | `Settings::getSetting()` (DB-backed layer) | **Bridge** (keep service, optionally back it with Settings) |
| CORS handling | `src/CorsHandler.php` | `Http\Middleware\CorsMiddleware` | **Adopt** (Phase 6) |
| Admin auth (Bearer + Redis session) | `src/AdminAuth.php` | `Http\Middleware\UnifiedAuthMiddleware` / `Auth\*` | **Bridge**/Adopt (Phase 6) |
| Input filtering/validation | `src/MessageFilter.php` | `Pramnos\Validation\Validator` (+ keep domain filters) | **Adopt** for shape, **Keep** for moderation |
| Image upload/GD | `src/PhotoService.php`, `src/ArtworkService.php` | `Pramnos\Media\ResizeTools` / `MediaObject`, `Storage\StorageManager` | **Adopt** |
| Raw-SQL migrations | `database/migrations/*.sql` | `Pramnos\Database\Migration` + `schemaversion` | **Adopt** (baselined) |
| Migration runners (×4) | `radiochatbox migrate`, `deploy.sh`, docker initdb, lazy | `vendor/bin/pramnos migrate` | **Adopt** |
| CLI wrapper | `radiochatbox` (bash) | `Pramnos\Console\Application` (Symfony) + `bin/pramnos` | **Adopt** |
| Daemon supervisor | `daemon.php` + `src/DaemonSupervisor.php` | `Pramnos\Console\DaemonOrchestrator` (abstract) | **Adopt** |
| Worker process | `worker.php` | `Console\Commands\ProcessQueue` + `Queue\Worker` | **Adopt** (supervise as-is first) |
| Redis delayed queue | `src/JobQueue.php` | `Pramnos\Queue\QueueManager` (**DB-backed**) | **Enhance** (add Redis driver) or **Keep** |
| In-worker scheduler | `src/Scheduler.php` + `scheduled_tasks` | `Pramnos\Scheduling\Scheduler` + `app/schedule.php` | **Adopt** or **Keep** (§6) |
| Health endpoint | `public/api/health.php` | `Pramnos\Health\HealthRegistry` + `Controllers\Health` | **Adopt** |
| Logging | `error_log()` scattered | `Pramnos\Logs\Logger` (PSR-3 channels) | **Adopt** |
| SSE real-time | `public/api/stream.php` + Redis pub/sub | *(no equivalent)* | **Keep** + **Enhance** (Redis broadcast driver) |
| Deploy webhook | `public/webhook.php` | `create:webhook` / `Auth\WebhookService` | **Keep** (optional Adopt) |
| Per-install identity | `src/Installation.php` | *(no equivalent)* | **Keep** |
| Single-instance locks | `src/WorkerLock.php` | orchestrator's flock + lock-file heartbeat | **Adopt** (via orchestrator) |

---

## 1. Data access — PDO → framework `Database` layer (Adopt incrementally)

**Today:** `RadioChatBox\Database::getPDO()` returns one persistent `\PDO` (`ATTR_PERSISTENT`,
`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `SET timezone`). Services do
`$stmt = $pdo->prepare('… :x'); $stmt->execute(['x'=>…]); $stmt->fetchAll(PDO::FETCH_ASSOC);`
with heavy PG-specific SQL (`RETURNING`, `ON CONFLICT … DO UPDATE`, `DISTINCT ON`, error code
`23505`, `SELECT cleanup_inactive_sessions()`).

**The framework target is a full DB layer** — three levels, all on `Pramnos\Database\Database`
(connection via native `pgsql`, obtained with `Database::getInstance($settings)->connect()`, or
inside the app via `$this->application->database`):

**(1) Raw SQL — the low-risk drop-in for our existing PG SQL.**

| PDO idiom | Framework equivalent |
|---|---|
| `Database::getPDO()` | `Pramnos\Database\Database::getInstance($settings)` + `->connect()` |
| `$pdo->prepare($sql)->execute($params)` | `$db->execute($sql, ...$args)` (real prepared stmt, `%s %d %i %b`) or `$db->query($db->prepareQuery($sql, ...$args))` |
| `$stmt->fetchAll(FETCH_ASSOC)` | `$result->fetchAll()` / `foreach ($result as $row)` (row in `$result->fields`) |
| `$stmt->fetch()` | `$result->fetch()` |
| `$pdo->query($constSql)` | `$db->query($sql)` (built-in result caching) |
| `bindValue(':limit',$n,PARAM_INT)` | `%d` placeholder in `prepareQuery()`/`execute()` |
| `beginTransaction/commit/rollBack` | `startTransaction()/commitTransaction()/rollbackTransaction()` |
| `lastInsertId()` | `$db->getInsertId()` / `$result->getInsertId()` |
| DDL/DML bool | `$db->statement($sql)` |
| unique-violation `23505` | `$db->getError()` / driver error inspection |
| `SELECT stored_fn(...)` | unchanged — passed through `query()`/`statement()` verbatim |

**(2) Fluent QueryBuilder** (`$db->queryBuilder()`), for readable new/rewritten queries —
including the PG features we lean on:
```php
$db->queryBuilder()->from('messages')
   ->where('room','=',$room)->whereNull('deleted_at')
   ->orderByDesc('created_at')->limit($n)->get();          // SELECT

$id = $db->queryBuilder()->from('private_messages')
   ->returning(['id'])->insert([...]);                      // INSERT … RETURNING id

$db->queryBuilder()->from('sessions')->insertOrIgnore([...]); // ON CONFLICT DO NOTHING
$db->upsert('settings', $data, ['setting']);                  // ON CONFLICT DO UPDATE
```
`RETURNING` (`->returning()`/`getReturning()`), `insertOrIgnore` (ON CONFLICT DO NOTHING),
`upsert()` (ON CONFLICT DO UPDATE), CTEs, window functions and `lockForUpdate()` are all native —
so our `RETURNING`/`ON CONFLICT`/`DISTINCT ON` patterns have first-class builder support, not just
raw-SQL fallback.

**(3) ORM** (`Pramnos\Application\OrmModel` + `Orm\Concerns\*` + `Orm\Relations\*` +
`Orm\Collection`) for the entity-shaped domain (users, messages, bans, reactions): attributes,
`HasTimestamps`, `HasSoftDeletes`, scopes, events, and `HasOne/HasMany/BelongsTo/BelongsToMany`
relations. Optional and highest-value where a service is mostly CRUD.

**Sequencing (from [`00`](00-overview-and-bc-strategy.md) §1) — Adopt A, Bridge B:**

- **Bridge (Phases 1–6):** `RadioChatBox\Database::getPDO()` keeps working, but sources its
  connection parameters from `Pramnos\Application\Settings` so there is one config truth.
  `getRedisPrefix()`, `getInstanceName()`, and the test seams (`setPDO/setRedis/reset`) stay
  intact. This unblocks every other phase without touching service SQL.
- **Converge (Phase 7):** rewrite services onto the framework layer **one at a time**, each with a
  regression test asserting identical results vs. the PDO version. Start with the simplest
  read-only services; leave the 1,500-line `ChatService` and 2,600-line `BotService` last. Prefer
  raw `prepareQuery()`/`statement()` for verbatim SQL moves, QueryBuilder/`OrmModel` for genuinely
  refactored code. Only if the pace is too slow, add the optional PDO-compat accessor
  ([`02`](02-framework-improvements.md) §1) to move `\PDO`-typed call sites without rewriting SQL.

> PostgreSQL is a **first-class** framework dialect (`PostgreSQLGrammar`,
> `PostgreSQLSchemaGrammar`, `DatabaseInspector`, PostGIS/JSONB/array/FTS helpers), so nothing
> about staying on Postgres is a compromise — the driver is native `pgsql`, not `pdo_pgsql`.

---

## 2. Redis — split into three roles

RadioChatBox uses Redis for three distinct things; they map differently:

1. **Cache (read-through)** — `Database::getRedis()` + per-service `setex`/`get`.
   → **Adopt** `Pramnos\Cache\Cache` with `method => 'redis'`. Gives `remember($key,$ttl,$cb)`
   and PSR-16 via `Pramnos\Cache\SimpleCache`. Keep our per-DB key prefix by passing `prefix` in
   the cache config. Note `Cache::load()` returns `false` on miss — use `remember()` for the
   common get-or-compute path.
2. **Pub/sub (SSE transport)** — `Database::getRedisForSubscribe()` + `publish chat:updates`.
   → **Keep.** The framework has no pub/sub. This is the real-time backbone; it stays on the raw
   `\Redis` connection until we add a Redis broadcasting driver ([`02`](02-framework-improvements.md) §2).
3. **Job bus (delayed queue)** — `src/JobQueue.php` (ZSET + hash).
   → **Keep** in the BC path; the framework queue is DB-backed. Converge via a Redis queue driver
   ([`02`](02-framework-improvements.md) §3) if/when desired.

---

## 3. Configuration — two planes preserved

| RadioChatBox | Framework | Mapping |
|---|---|---|
| `RadioChatBox\Config::get('database.host')` (parses `.env`) | `envvar('DB_HOST')` + `Settings::getSetting()` | `app/settings/settings.php` returns `['database'=>['hostname'=>envvar('DB_HOST'), 'port'=>envvar('DB_PORT',5432), 'type'=>'postgresql', 'schema'=>'public', …], 'cache'=>['method'=>'redis','hostname'=>envvar('REDIS_HOST'),…]]` |
| `SettingsService::get('bot_enabled')` (DB `settings` table, Redis-cached) | `Settings::getSetting('bot_enabled')` (also DB-backed, cached 600s) | **Bridge**: keep `SettingsService` as the admin-facing API (whitelist, bounds, `settings:version` stamp that the worker watches); it may read through to `Settings` internally later. Do **not** replace it wholesale — its `VERSION_KEY` invalidation is load-bearing for the long-running worker. |

`.env` keys stay identical (`DB_*`, `REDIS_*`, `CHAT_*`, `DEEPSEEK_*`, `ALLOWED_ORIGINS`,
`WEBHOOK_SECRET`, `DEPLOY_BRANCH`, `TZ`, `CRON_TOKEN`). `Config` becomes a thin shim over
`envvar()` (or is retired once callers move to `Settings::getSetting()`).

---

## 4. HTTP layer (Phase 6, opt-in, endpoint-by-endpoint)

**Today:** no router/DI/middleware. Every `public/api/*.php` does
`require autoload; CorsHandler::handle(); header(json); method-guard; AdminAuth::authenticate();
json_decode(php://input); new Service(); echo json_encode(...)` with per-file try/catch.

**Framework building blocks:**

- **Request/Response:** `Pramnos\Http\Request` (superglobal-oriented, `get()/all()/only()/
  validate()`) and `Pramnos\Http\Response` (`Response::json($data,$status)`, `withHeader()`,
  `send()`). Controllers return a `Response`; the kernel emits it.
- **Middleware (native pipeline — this is what's wired, not PSR-15):** implement
  `Pramnos\Http\MiddlewareInterface::handle(Request $request, callable $next)`. Register globally
  (`Router::addGlobalMiddleware`), per-route (`->middleware(...)` or `#[Route(middleware: [...])]`),
  or per-action (`Controller::addMiddleware()`). Our hand-rolled cross-cutting concerns map to
  built-ins:

  | RadioChatBox hand-rolled | Framework middleware |
  |---|---|
  | `CorsHandler::handle()` | `CorsMiddleware::fromApplicationSettings()` (drives from `allowed_origins`) |
  | method guard + `header(json)` | `JsonResponseMiddleware` |
  | `AdminAuth::authenticate()` (Bearer/session) | `UnifiedAuthMiddleware` (Bearer JWT **or** session+CSRF) |
  | rate-limit in `AdminAuth`/`MessageFilter` | `RateLimitMiddleware` (sliding window over the **Cache** layer → Redis) |
  | *(none today)* | `CsrfMiddleware`, `MaintenanceModeMiddleware` |

- **Routing:** `Pramnos\Routing\Router` with attribute discovery
  (`#[Route(uri, methods, name, permissions, middleware)]` + `Router::loadFromDirectory()`), or
  programmatic `get/post(...)`. A tiny front controller (`public/index.php` or a new dispatcher)
  builds the `Request`, runs the global pipeline, and calls `Router::dispatch()`.

  ⚠️ **Blocker for controller classes:** `Route::execute()` only handles `is_callable($action)`
  and does **not** instantiate non-static `[Class,'method']` actions produced by attribute
  discovery. Fix in the framework first — [`02`](02-framework-improvements.md) §4. Until then,
  closures or static methods work.

**BC:** Apache keeps serving the legacy `public/api/*.php` files unchanged. We add the front
controller alongside them and migrate one endpoint at a time; each migrated endpoint gets a
request test asserting identical status/headers/body vs. the file it replaces. SSE endpoints
(`stream.php`) are migrated **last** or kept as raw streaming actions (see §7).

---

## 5. CLI, migrations, daemon, worker

### 5.1 Console (Adopt)
`radiochatbox` (bash, docker-compose wrapper) → `vendor/bin/pramnos` (Symfony Console via
`Pramnos\Console\Application`). Custom commands scaffolded with `create:command`; register in a
console-application subclass. Keep a thin `radiochatbox` shim that forwards to `pramnos` inside
the container so existing muscle-memory/docs keep working. Fixes the current broken
`radiochatbox bot → bot-worker.php` (file doesn't exist) along the way.

### 5.2 Migrations (Adopt, baselined)
Each `database/migrations/NNN_*.sql` → a `Pramnos\Database\Migration` subclass in
`app/migrations/YYYY_MM_DD_HHmmss_slug.php`:
```php
class AddReplyToMessages extends \Pramnos\Database\Migration {
    protected $description = 'Add reply_to to messages';
    protected $feature = 'core';
    public function up(): void {
        // Drop-in: existing idempotent SQL runs verbatim.
        $this->DB()->statement(file_get_contents(__DIR__.'/sql/001_add_reply_to_messages.sql'));
    }
    public function down(): void { /* best-effort reverse or no-op for baselined */ }
}
```
Two conversion styles: **(a)** wrap the existing SQL via `$this->DB()->statement()` (lowest risk,
keeps our idempotent PG SQL), or **(b)** refactor to the `SchemaBuilder`/`Blueprint` DSL
(portable, gives real `down()`). Use **(a)** for the 25 baseline files; use **(b)** for
*new* migrations going forward. Tracking → `schemaversion`; baselining → `migration_cutoff`
([`00`](00-overview-and-bc-strategy.md) §4). Filename gaps (002/003/011) are irrelevant once
timestamps drive ordering.

### 5.3 Daemon orchestrator (Adopt)
`daemon.php` + `DaemonSupervisor` → a concrete subclass of
`Pramnos\Console\DaemonOrchestrator` registered as e.g. `daemons:start`:
```php
protected function buildDesiredProcesses(): array {
    return [[
        'id' => 'bot_worker', 'daemon' => 'worker', 'workerId' => 1,
        'lockFile' => VAR_PATH.'/worker.lock',
        // Phase 3: supervise the EXISTING worker.php unchanged.
        'shellCommand' => PHP_BINARY.' '.ROOT.'/worker.php run --schedule',
    ]];
}
protected function getEntryPoint(): string { return ROOT.'/bin/radiochatbox'; }
protected function getDashboardTitle(): string { return 'RadioChatBox Daemons'; }
```
The base class provides what `DaemonSupervisor` hand-rolls: reconcile loop, crash respawn,
heartbeat-staleness restart (300s), `.stop`-sentinel graceful stop, `/proc` dedup, flock
singleton guard, and **git-HEAD redeploy detection** — a direct feature-for-feature match. Our
`WorkerLock`/`Installation` semantics are absorbed by the orchestrator's lock-file + flock model.

### 5.4 Worker (Adopt incrementally)
Phase 3 supervises `worker.php` **as-is** (via `shellCommand`) — zero worker changes, immediate
orchestrator benefits. Phase 7 optionally reshapes `worker.php` into a `ProcessQueue` subclass
(`queue:process --daemon --worker-id=N`) with task handlers on `Queue\Worker`. That step requires
deciding the queue backend: framework `queueitems` (DB) vs. keeping Redis `JobQueue` behind a new
Redis queue driver ([`02`](02-framework-improvements.md) §3). Bot replies are latency-sensitive
and already Redis-based → **keep Redis**, add the driver.

---

## 6. Scheduling — decide: keep in-worker vs. framework Scheduler

**Today:** `src/Scheduler.php` runs 11 tasks *inside the long-lived worker* (`--schedule`),
tracked in `scheduled_tasks`, with per-task cadence and a ≤2-tasks-per-cycle limit. This is
deliberately **not** a per-minute cron — it rides the worker loop.

**Framework:** `Pramnos\Scheduling\Scheduler` is code-defined in `app/schedule.php`
(`->everyFiveMinutes()`, `->cron()`, `->at()`, `->withoutOverlapping()`), driven by a single
`schedule:run` cron line every minute.

**Recommendation:** **Keep the in-worker scheduler** for the BC path (it's superior for this
workload — no per-minute process spawn, shares the worker's warm connections, and the
`scheduled_tasks` observability table is valuable). Optionally **also** define the same tasks in
`app/schedule.php` for admins who prefer cron-driven scheduling, mirroring the existing
"three overlapping scheduling layers" the app already supports. Do not remove `scheduled_tasks`.

---

## 7. Real-time (SSE) — Keep, then Enhance

The framework has **no SSE and no Redis pub/sub** — its real-time story is
Pusher/Reverb/WebSocket (`BroadcastingManager` + `broadcast:serve` + `pramnos-echo.js`).
Rewriting the SSE client/server to WebSockets is a large, user-visible change with no BC upside.

**Plan:**
- **Keep** `public/api/stream.php` as a raw long-lived streaming endpoint (Apache is already
  tuned for it: `output_buffering Off`, `Timeout 3600`). If it ever moves into a framework
  controller, it stays a streaming action (`exec()` echoes; a `Response` is not mandatory).
- **Enhance** the framework with a **Redis pub/sub broadcasting driver** implementing
  `Broadcasting\Drivers\DriverInterface`, so app code can `broadcast($channel,$event,$payload)`
  through the framework abstraction while Redis remains the transport and SSE remains the client
  delivery ([`02`](02-framework-improvements.md) §2). This unifies the API without touching the
  wire protocol.

---

## 8. Supporting subsystems (Phase 5, low risk)

| Concern | Adopt | Notes |
|---|---|---|
| **Artwork/photo upload + GD** | `Media\ResizeTools` (resize/crop), `Media\MediaObject` (`addImage/addRemoteImage/uploadImage`, MD5 dedup, thumbnails, usage tracking), `Storage\StorageManager` (`local` disk now, `s3` later via config) | Replaces bespoke GD in `PhotoService`/`ArtworkService`; adds dedup + optional S3 offload for `public/uploads/artwork`. |
| **Logging** | `Pramnos\Logs\Logger` (static, PSR-3 via `Logger::channel()`) | Replace scattered `error_log()` with channels (`worker`, `bot`, `http`, `deploy`). PSR-3 `PsrLogger` can be handed to `LlmService`/HTTP client. |
| **Health** | `HealthRegistry::register()` + `Controllers\Health::check()` (JSON, correct HTTP status) | Register a Redis check + SSE check alongside built-in `DatabaseConnectivityCheck`/`DiskSpace`/`Memory`. Replaces standalone `public/api/health.php`. |
| **Validation** | `Validation\Validator::validate($data,$rules)` / `FormRequest` | Use for endpoint input *shape*. **Keep** `MessageFilter` for domain moderation (URL whitelist, spam, auto-ban) — that's business logic, not generic validation. |
| **Events** | `Pramnos\Event\Event::listen/fire` | Optional: decouple e.g. `message.posted` → stats/bot triggers. In-process only (not cross-worker); cross-process fan-out stays on Redis. |
| **Auth** | `Auth\Auth`, `UnifiedAuthMiddleware`, `Auth\Permissions` (RBAC), 2FA/passkeys/OAuth2 available | Our `UserService` RBAC (`ROLE_LEVELS`/`PERMISSIONS`) can map onto `Auth\Permissions`; 2FA/passkeys are a future feature, not a migration requirement. Bridge `AdminAuth` behind `UnifiedAuthMiddleware` in Phase 6. |
