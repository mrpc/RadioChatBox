# RadioChatBox architecture notes

RadioChatBox runs on PramnosFramework as native infrastructure. This note records the
**deliberate, non-obvious** structural and schema decisions — why RadioChatBox looks
different from a classic MVC Pramnos app, and why certain framework migrations and
middleware stay off. (It is the durable rationale that outlived the one-off migration
plan.)

---

## 1. Application style: Services + API + SPA (not MVC + Models)

The framework supports two application styles (see the framework's
[Application Styles guide](../vendor/mrpc/pramnosframework/docs/Pramnos_Application_Styles_Guide.md)).
RadioChatBox is the **Services + API + SPA** style:

| Aspect | RadioChatBox | Classic MVC (e.g. UrbanWater) |
|---|---|---|
| Front end | static JS SPA (`public/spa.php` shell + `public/` assets) consuming JSON | server-rendered `src/Views` + themes |
| Dispatch | thin `public/index.php` → `bootstrap/http.php` (Router + middleware) | `Application::init()/exec()/render()` |
| Routing | `#[Route]` attributes on `src/Controllers` | `src/Api/routes.php` + `@api` comments |
| Domain | `src/*` **services** + QueryBuilder | `src/Models` ActiveRecord |
| CLI | `radiochatbox.php` + `src/Console.php` + `src/ConsoleCommands/` | same |

**These are not gaps to "fix":**

- **No `src/Views` / themes** — the UI is a static SPA; there is no server-rendered view layer.
- **No `src/Api` + routes.php** — RadioChatBox uses the framework's *newer* attribute-routing
  engine; adopting the older convention would be a regression.
- **No ActiveRecord Models** — a deliberate choice: the logic here (rate limiting, moderation,
  LLM bots, real-time fan-out, background jobs) is richer than CRUD, so it lives in cohesive,
  testable **services** over the QueryBuilder. Models remain available if a table ever wants
  ActiveRecord ergonomics.

The CLI does **not** call `Application::init()` either (see §3) — the console entry
(`radiochatbox.php`) does a console-safe bootstrap instead.

---

## 2. Middleware & security posture

RadioChatBox runs the framework middleware pipeline: `bootstrap/http.php` loads the `Router`
and applies global `CorsMiddleware` + `JsonResponseMiddleware`, with per-route
`AdminAuthMiddleware` via `#[Route(..., middleware: [...])]`.

Framework middleware intentionally **not** applied:

| Middleware | Why it does not apply |
|---|---|
| `CsrfMiddleware` | No ambient-cookie auth. Admin uses a Bearer `username:password` token (Authorization header); public state-changing calls carry an explicit `sessionId` in the body. Neither is auto-attached by a browser cross-origin, so CSRF is not a vector. |
| `SessionTrackingMiddleware` | RadioChatBox owns its `sessions` table and writes it directly from `ChatService` — a different schema from the framework's (see §3). This is also why the CLI/HTTP bootstrap avoids `Application::init()`, which would start session tracking against the wrong schema. |
| `RateLimitMiddleware` / `ThrottleMiddleware` | Rate limiting is app-level and semantic: `ChatService` sliding-window counters (via the Cache), `MessageFilter` spam tallies. |

The curated set (CORS + JSON + Bearer admin auth) plus the app-layer controls is correct and
complete for a token/JSON API.

---

## 3. Database schema — collisions with the framework

**The hazard.** Every framework migration guards with `if ($schema->hasTable('<name>')) return;`.
So if a table of the same name already exists in the RadioChatBox DB, the framework migration
**silently skips** — no error, no alter — and then framework *runtime* code runs against
RadioChatBox's incompatible table and fails with "column does not exist". That is worse than a
hard failure: it passes CI and the migrate step, then breaks in production.

**The rule that falls out of it:** the framework's colliding feature migrations
(`core`/`auth`/`messaging`) must stay **disabled** while those names are occupied. Concretely:

- `app/app.php` declares `'features' => []` (no framework feature migrations run).
- The bootstrap (`bootstrap/pramnos.php`, `radiochatbox.php`) does **not** call
  `Application::init()` — its session start / `SessionTrackingMiddleware` would write the
  framework's `sessions` schema over RadioChatBox's.

**The four `public`-schema collisions** (all incompatible — same name, different design):

| Table | Nature of the clash |
|---|---|
| `settings` | RCB `setting_key`/`setting_value` vs framework `setting`/`value` + `delete` flag |
| `users` | RCB `id SERIAL`/`role` enum vs framework `userid BIGINT`/`usertype` + ~25 profile cols |
| `sessions` | RCB heartbeat/presence model vs framework visitor-tracking model |
| `messages` | **Highest severity** — RCB public **chat feed** vs framework internal **PM/notifications** |

**Framework tables safe to ADD** (no name clash, pure capability): `schemaversion` (the migration
tracker), `queueitems`, `usertokens`, `notifications`, and everything in the `authserver.*` /
`applications.*` Postgres schemas (namespaced, cannot collide with `public`).

**Convergence is optional and separate.** The infrastructure does **not** depend on it — the
framework runs fine against RadioChatBox's tables as long as the colliding feature migrations
stay disabled. If ever pursued, converge add-first, and rename RCB `messages` → `chat_messages`
*before* enabling framework messaging (the one collision that fails silently and dangerously);
treat Settings and Users convergence as their own data-migration projects.

---

## 4. API documentation (OpenAPI from `#[Route]`)

The spec is generated from the `#[Route]` attributes on `src/Controllers` — no hand-written
route list. Regenerate it (inside the app container):

```
php radiochatbox.php api:docs --output=public/api/openapi.json \
    --title='RadioChatBox API' --api-version=1.0.0 --server=/
```

Schemas that cannot be inferred are added via a deep-merged `--overrides` document.
`tests/OpenApiDocsTest.php` guards that the whole API surface stays documentable.
