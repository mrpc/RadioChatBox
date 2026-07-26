# PramnosFramework Integration — Overview & Backward-Compatibility Strategy

> **Status:** planning / branch `feature/pramnos-framework-integration`.
> **Audience:** RadioChatBox maintainers. RadioChatBox is **live in production**, so every
> phase below is designed to ship independently and to be reversible.

This is the master document. Read it first, then:

- [`01-native-integration-map.md`](01-native-integration-map.md) — exact 1:1 mapping of each RadioChatBox subsystem onto its PramnosFramework counterpart, with the concrete framework APIs.
- [`02-framework-improvements.md`](02-framework-improvements.md) — things the framework does **not** support yet (or supports differently) that we must add/enhance in PramnosFramework itself.
- [`03-integration-plan.md`](03-integration-plan.md) — the step-by-step, phase-by-phase execution plan for the pieces where the framework's implementation is clearly better than ours.

---

## 0. What has already been done on this branch

1. Branch `feature/pramnos-framework-integration` created off `main`.
2. `composer.json` now declares a **path repository** pointing at `../PramnosFramework` with
   `"symlink": true`, and requires `mrpc/pramnosframework: "@dev"`.
3. `composer update` resolved and installed the framework + its transitive dependencies
   (Symfony 5.4 console/routing/finder/dotenv/mailer, `nyholm/psr7`, `league/oauth2-server`,
   `web-token/jwt-framework`, `web-auth/webauthn-lib`, `chillerlan/php-qrcode`, `psr/*`).
   `vendor/mrpc/pramnosframework` is a **symlink** to `../PramnosFramework`, so framework edits
   are picked up live.
4. Verified: both `RadioChatBox\*` and `Pramnos\*` classes autoload together, and the
   framework's global `helpers.php` (`env`, `envvar`, `e`, `l`) does **not** collide with any
   RadioChatBox global — i.e. **BC holds at the autoload level today**.

> ⚠️ `composer.lock` and `vendor/` are `.gitignore`d in this project, so the branch only
> changes `composer.json`. Each environment runs `composer install` itself.

---

## 1. The one real driver-level nuance (and why it is *only* a nuance)

The task brief says *"use the framework natively … **with its PDO**, its middleware, …"*.

To be precise about what the framework's data layer actually is:

**PramnosFramework has a full, advanced native database layer** — this is a strength, not a gap:
- **Fluent QueryBuilder** (`Pramnos\Database\QueryBuilder`, v1.2): `select/from/where/join/
  groupBy/orderBy/limit/union`, CTEs, window functions, locks, and writes `insert/update/delete/
  insertOrIgnore` — **with native `RETURNING` support** (`getReturning()`).
- **An Eloquent-style ORM**: `Pramnos\Application\OrmModel` + concern traits
  (`HasAttributes/HasRelationships/HasScopes/HasSoftDeletes/HasTimestamps/HasEvents`) +
  relations (`HasOne/HasMany/BelongsTo/BelongsToMany`) + `Orm\Collection`.
- **Raw SQL** via `prepareQuery()` (WordPress-style `%s/%d` binding) + `query()`, and
  `statement()` for DDL/DML — so our existing PG-specific SQL runs verbatim.
- PostgreSQL is **first-class**: dedicated grammars/inspector, plus PostGIS, JSONB (`->>`, `@>`),
  array (`ANY()`), full-text search.

The **only** driver-level fact to keep in mind: the connection is opened with the **native
`pgsql`/`mysqli` extensions** (`connectPostgresql()` → `pg_connect`, `connectMysql()` →
`mysqli_connect`), **not the PHP `PDO` extension**. RadioChatBox today uses `\PDO` (`pdo_pgsql`):
`RadioChatBox\Database::getPDO()` hands a `\PDO` to ~25 services that use `PDO::PARAM_INT`,
`PDO::FETCH_KEY_PAIR`, and catch `\PDOException` (`23505`).

That distinction has exactly **two** practical consequences — nothing more:
1. The **`pgsql`** extension must be installed (in addition to `pdo_pgsql`) — see §3.
2. Code literally typed against `\PDO` / `PDO::*` constants / `\PDOException` cannot be handed the
   framework connection object as-is; those call sites are rewritten to the framework API
   (`query()`/`prepareQuery()`/`execute()`/`queryBuilder()`/`OrmModel`).

**The native target is the framework's DB layer**, and the decision is only about *sequencing*
the ~25 services safely, not about whether to adopt it:

| Option | What it means | Role |
|---|---|---|
| **A. Converge onto the framework DB layer** | Rewrite services onto `query()/prepareQuery()/statement()` (raw SQL drop-in) and `queryBuilder()`/`OrmModel` for richer code. `RETURNING`/`ON CONFLICT`/stored functions survive as raw SQL. | ✅ **The destination.** Done **incrementally, per service**, each behind its own tests — never big-bang. |
| **B. PDO bridge during transition** | Until a service is converged, `RadioChatBox\Database::getPDO()` keeps working, but its connection config is sourced from `Pramnos\Application\Settings` (one config truth). | ✅ **Transitional.** Lets Phases 1–6 land without blocking on the data-layer rewrite. |
| **C. Optional PDO-compat accessor on the framework** | *Optional* convenience: expose a `\PDO` handle so `\PDO`-typed code migrates without immediate rewrite. Trades off against the framework's deliberate native-driver design. | ⚪ Optional; see [`02`](02-framework-improvements.md) §1 — adopt only if the incremental rewrite proves too slow. |

We converge onto the framework DB layer via **A**, using **B** as the transitional scaffold so
other phases aren't blocked, and treat **C** as an optional accelerator.

---

## 2. Guiding principles (non-negotiable, because production)

1. **Strangler pattern, not big-bang.** The framework is introduced *underneath* the running app
   as a shared bootstrap. Existing `public/api/*.php` file-per-endpoint routing keeps working
   unchanged (Apache still serves the files). Endpoints move to framework controllers one at a
   time, only when there's a reason to.
2. **Every phase ships on its own and is reversible.** No phase depends on a later one. A phase
   can be reverted by a single git revert + `composer install` without data loss.
3. **The database schema is the contract.** We never let the framework's migration runner
   *recreate* existing tables. We baseline the current production schema into `schemaversion`
   (§4) and keep all converted migrations idempotent.
4. **Redis stays load-bearing.** SSE fan-out and the bot-reply job bus run on Redis pub/sub +
   ZSET. The framework has no SSE and a DB-backed queue, so Redis is **not** replaced in the BC
   path — it's wrapped behind framework abstractions only when we add the drivers in [`02`](02-framework-improvements.md).
5. **Two config planes are preserved.** `.env` (infra) and the DB `settings` table (runtime,
   admin-editable) both survive; they map cleanly onto `envvar()` and `Settings::getSetting()`
   respectively (see [`01`](01-native-integration-map.md) §3).

---

## 3. Runtime prerequisites (must land before anything else)

The production `Dockerfile` and bare-metal hosts are **missing PHP extensions the framework needs**:

| Extension | Needed by | Present in RadioChatBox today? |
|---|---|---|
| `mbstring` | framework core (`composer.json` `ext-mbstring: *`), helpers, validation | ❌ **No** — `Dockerfile` installs only `pdo pdo_pgsql gd` (+ redis, pcov). Also absent from the local CLI. |
| `pgsql` (native, **not** `pdo_pgsql`) | `Pramnos\Database\Database` for PostgreSQL — only needed if/when we adopt option A/C | ❌ **No** — only `pdo_pgsql` is installed. |

**Action (Phase 0):** add to `Dockerfile`:
```dockerfile
RUN docker-php-ext-install mbstring pgsql   # pgsql only required for framework Database (option C)
```
`mbstring` is required immediately (any framework code path may use it). `pgsql` is only required
when we start using `Pramnos\Database\Database`; it is harmless to install now and de-risks C.
Bare-metal hosts: `apt-get install php8.3-mbstring php8.3-pgsql` + restart PHP-FPM.

> This is the one hard prerequisite. Until `mbstring` is present, the framework must not be on a
> live code path. See [`02`](02-framework-improvements.md) §7 for making the framework degrade
> gracefully when optional extensions are missing.

---

## 4. Migration-history baselining (the crux of DB backward-compatibility)

RadioChatBox has **no migration tracking table today** — 25 raw-SQL files made idempotent
(`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `ON CONFLICT DO NOTHING`), run four
different ways. The framework tracks applied migrations in a **`schemaversion`** table and
auto-runs pending ones on boot.

If we convert our SQL files to `Pramnos\Database\Migration` classes and point the runner at a
**production DB that already has every table**, we must guarantee it does **not** try to recreate
them. Three layers of defence, applied together:

1. **`migration_cutoff` in `app/app.php`.** Set it to a timestamp *after* all baseline
   migrations. The framework filters out any migration whose filename timestamp is `<= cutoff`
   *before* it even loads the file (verified in `Application::runAutoMigrations()` /
   `normalizeMigrationCutoff()`). Our converted baseline migrations get timestamps below the
   cutoff → the framework treats the whole existing schema as pre-applied and never runs them.
   Only *new* migrations (timestamp above cutoff) run.
2. **Seed `schemaversion`.** For completeness/observability, a one-off script inserts a row per
   baselined migration slug (with `result=1`) so `migrate:status` reports the true state. (This
   is a run-once maintenance script — kept out of git per project convention.)
3. **Keep converted migrations idempotent.** Even if a baseline migration were ever run, the
   `IF NOT EXISTS` / `ON CONFLICT` guards make it a no-op. Defence in depth.

This is exactly the mechanism the framework itself uses for its own legacy installs (the
`2020_01_01_*` baseline epoch skipped via `migration_cutoff`), so we are on a well-trodden path.

---

## 5. Phase roadmap (each phase = one or more PRs, independently shippable)

| Phase | Goal | Risk | Touches production data path? |
|---|---|---|---|
| **0. Prerequisites** | Add `mbstring`/`pgsql` to Dockerfile + hosts; commit composer changes. | Low | No (infra only) |
| **1. Bootstrap coexistence** | Introduce a framework bootstrap (`app/app.php`, `app/settings/settings.php` sourced from `.env`) loaded alongside the existing autoload. `RadioChatBox\Config`/`Database` keep working; `Settings::getSetting()` becomes available. | Low | No |
| **2. Console + migrations** | Adopt `vendor/bin/pramnos`; convert the 25 SQL files to `Migration` classes (raw-SQL bodies via `$this->DB()->statement()`); baseline via `migration_cutoff`. Replace the 4 ad-hoc runners with `pramnos migrate`. | Medium | Schema only (guarded, idempotent) |
| **3. Daemon orchestrator** | Replace `DaemonSupervisor` with a `DaemonOrchestrator` subclass supervising the **existing** `worker.php` as a child. Keep worker internals unchanged. | Medium | No (process supervision only) |
| **4. Scheduler** | Move the in-worker `Scheduler` task list into `app/schedule.php` (or keep in-worker; decide per [`01`](01-native-integration-map.md) §6). | Low | No |
| **5. Cross-cutting infra** | Adopt framework Cache (PSR-16 over Redis), Logs (PSR-3 channels), Storage/Media for artwork, Health checks, Validation — behind our existing seams. | Low–Med | Read/rewrite, per subsystem |
| **6. HTTP/middleware (opt-in)** | Add a front controller + Router with attribute routes and the framework middleware pipeline (CORS/CSRF/RateLimit/Auth). Migrate endpoints one at a time; file-per-endpoint stays until each is moved. | Medium | Per endpoint |
| **7. Framework enhancements + DB convergence** | Land the framework improvements from [`02`](02-framework-improvements.md): Redis broadcasting driver + SSE, Redis queue driver, `Route::execute()` container resolution, graceful optional-ext handling (+ optional PDO-compat accessor). Converge the ~25 services onto the framework DB layer (option A) **one service at a time**, and optionally the queue. | Med–High | Yes (per service, tested) |

**Ordering rationale:** everything through Phase 5 leaves the HTTP request path and the data
layer byte-for-byte identical to today; only *infrastructure underneath* changes. HTTP migration
(Phase 6) and data-layer convergence (Phase 7) are the genuinely invasive steps and come last,
endpoint-by-endpoint, each behind its own revert.

---

## 6. Rollback & verification per phase

- **Rollback:** each phase is a git revert of its PR + `composer install`. Because
  `composer.lock`/`vendor` are gitignored, reverting `composer.json` (Phase 0) fully removes the
  framework. Phases 1–5 add files/bootstrap but do not delete RadioChatBox code, so reverting is
  clean. Schema changes (Phase 2) are additive + idempotent and never destructive.
- **Verification gates (per project testing discipline — high coverage on new code, a regression
  test per behaviour):**
  - Phase 0: framework classes instantiate under the container image (`mbstring` present).
  - Phase 2: `pramnos migrate:status` on a **restored production DB dump** shows *zero pending*
    and recreates nothing (the acceptance test for baselining).
  - Phase 3: orchestrator survives a simulated worker crash, a `.stop` request, and a deploy
    (git HEAD change) — the same behaviours `DaemonSupervisor` guarantees today.
  - Phase 6: each migrated endpoint has a request test asserting identical status/headers/body
    vs. the legacy file it replaces.

---

## 7. Divergence summary (where the framework and RadioChatBox disagree)

These are the points where a naive "just use the framework" fails; each has a concrete resolution
in the linked docs:

1. **DB access:** framework has a full native DB layer (QueryBuilder + ORM + raw SQL) on the
   `pgsql`/`mysqli` extensions, not PDO. Converge incrementally onto it (option A), PDO bridge in
   the meantime (option B) — §1, [`02`](02-framework-improvements.md) §1.
2. **Job queue:** framework is **DB-backed** (`queueitems`); RadioChatBox is **Redis ZSET** →
   keep Redis; add a Redis queue driver ([`02`](02-framework-improvements.md) §3).
3. **Real-time:** framework is **Pusher/WebSocket**, no SSE, no Redis pub/sub; RadioChatBox is
   **SSE + Redis pub/sub** → keep SSE; add a Redis broadcasting driver ([`02`](02-framework-improvements.md) §2).
4. **Routing:** framework Router's `Route::execute()` doesn't instantiate non-static
   `[Class,'method']` controller actions → fix in framework before Phase 6
   ([`02`](02-framework-improvements.md) §4).
5. **PSR-15:** framework ships a real PSR-15 `Pipeline` but the kernel doesn't use it; middleware
   use the native `MiddlewareInterface` → we adopt the native pipeline (it's what's wired).
6. **Migrations:** no tracking table today → baseline via `migration_cutoff` (§4).
