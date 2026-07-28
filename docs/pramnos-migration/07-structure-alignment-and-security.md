# Phase 8 addendum — structure alignment & security posture

This note records the deliberate structural choices that make RadioChatBox look
different from a classic MVC Pramnos app (e.g. UrbanWater), why those differences
are correct, and the alignment work that *was* worth doing.

## RadioChatBox is a "Services + API + SPA" app, not "MVC + Models"

The framework supports two application styles (see the framework's
[Application Styles guide](../../vendor/mrpc/pramnosframework/docs/Pramnos_Application_Styles_Guide.md)).
RadioChatBox is the second:

| Aspect | RadioChatBox | Classic MVC app (UrbanWater) |
|---|---|---|
| Front end | static JS SPA (`public/`) consuming JSON | server-rendered `src/Views` + themes |
| Dispatch | `public/_dispatch.php` → `Router` + middleware | `Application::init()/exec()/render()` |
| Routing | `#[Route]` attributes on `src/Controllers` | `src/Api/routes.php` + `@api` comments |
| Domain | `src/*` **services** + QueryBuilder | `src/Models` ActiveRecord |
| API docs | generated from `#[Route]` (see below) | apidoc.json → openapi |

**These are not gaps to "fix":**

- **No `src/Views` / themes** — correct: the UI is a static SPA; there is no
  server-rendered view layer.
- **No `src/Api` + routes.php** — RadioChatBox uses the framework's *newer*
  attribute-routing engine. Adopting the older `src/Api` convention would be a
  regression.
- **No ActiveRecord Models** — a deliberate Phase-7 choice: the logic here (rate
  limiting, moderation, LLM bots, real-time fan-out, background jobs) is richer
  than CRUD, so it lives in cohesive, testable **services** over the QueryBuilder.
  Models remain available if a table ever wants ActiveRecord ergonomics.

## Middleware & security posture (verified)

RadioChatBox **does** run the framework middleware pipeline — `public/_dispatch.php`
loads the `Router` and applies global `CorsMiddleware` + `JsonResponseMiddleware`,
with per-route `AdminAuthMiddleware` via `#[Route(..., middleware: [...])]`.

The framework middleware that RadioChatBox intentionally does **not** apply:

| Middleware | Why it does not apply |
|---|---|
| `CsrfMiddleware` | **N/A** — no ambient-cookie auth. Admin uses a Bearer `username:password` token (Authorization header); public state-changing calls carry an explicit `sessionId` in the body. Neither is auto-attached by a browser cross-origin, so CSRF is not a vector. |
| `SessionTrackingMiddleware` | RadioChatBox owns its `sessions` table and writes it directly from `ChatService` (schema differs from the framework's — see [05](05-schema-convergence.md)). |
| `RateLimitMiddleware` / `ThrottleMiddleware` | Rate limiting is app-level and semantic: `ChatService` sliding-window counters (via `Cache::increment`), `MessageFilter` spam tallies. |

**Verdict:** the curated middleware set (CORS + JSON + Bearer admin auth) plus the
app-layer controls is correct and complete for a token/JSON API. No security
middleware gap.

## What was aligned: OpenAPI from `#[Route]`

The one genuine gap — auto-generated API docs — is closed by the framework's new
attribute-native generator (`Pramnos\Routing\OpenApiGenerator` / `api:docs`).

Regenerate the spec (inside the app container):

```
php bin/rcb api:docs --output=public/api/openapi.json \
    --title='RadioChatBox API' --api-version=1.0.0 --server=/
```

This reflects the `#[Route]` attributes on `src/Controllers` and writes
`public/api/openapi.json` (served directly by the strangler docroot). Request /
response schemas that cannot be inferred are added via a deep-merged
`--overrides` document. `tests/OpenApiDocsTest.php` guards that the whole API
surface stays documentable.
