# Admin Panel Rewrite Plan — Svelte + DaisyUI

> **Status:** proposal for review.

## Two framings

This plan serves a double purpose:

1. **RadioChatBox now** — replace the monolithic `public/admin/index.html` with a
   modern, routed, componentised admin SPA.
2. **PramnosFramework scaffolding feature** — generalise the outcome so the
   framework can *generate* an admin SPA for **any** Pramnos app, with a chosen UX
   toolkit, the same way `pramnos make:app` / the scaffolding already creates apps
   and wires realtime (`pramnos-realtime.js`). RCB becomes the first consumer and
   the reference implementation.

See **[Framework feature: scaffolded admin SPA](#framework-feature-scaffolded-admin-spa)**
at the end for the framework angle; the body below is the concrete RCB rewrite it
is generalised from.

## Why

`public/admin/index.html` is a single ~11k-line HTML file with all markup, CSS and
JS inline. It works, but the UX and maintainability have hit a wall — the recent
bug batch is symptomatic:

- Navigation has no real routes → **no open-in-new-tab**, no deep links, no
  back/forward.
- State is scattered global variables; races (e.g. take-over not pinning the
  impersonation context → wrong-user sends).
- Rendering is string concatenation → inconsistent handling (photos shown in one
  view, `[attachment]` in another; scroll hijacking; pagination reset).
- One giant file → every change risks the whole panel; hard to test.

Goal: a **modern, componentised, routed, testable** admin SPA with a consistent
design system, without touching the public chat front-end or the backend API
contract (which the rewrite reuses as-is).

## Tech choices

- **SvelteKit** (SPA mode, `adapter-static`) — small runtime, great DX, file-based
  routing gives real URLs (open-in-new-tab, deep links) for free.
- **DaisyUI on Tailwind** — component classes + theming; fast, consistent UI.
- **TypeScript** — typed API client + models (kill the "wrong field" class of bugs).
- **Vite** build → static assets served under `public/admin/` (same origin, same
  Bearer auth). No server-side rendering needed.
- **@tanstack/svelte-query** (or a thin store) for server state: caching,
  background refetch that respects the current page (fixes the pagination-reset
  class), and request dedup.
- Realtime: a small typed wrapper over the existing `/api/realtime-config`
  (WebSocket with SSE fallback) — reuse the transport we already built.

## Architecture

```
admin-ui/                     # new SvelteKit project (built → public/admin/)
  src/
    lib/
      api/                    # typed fetch client (Bearer), one module per resource
      realtime/              # WS/SSE client from /api/realtime-config
      auth/                  # login, token storage, admin session
      stores/                # settings, current user, notifications
      components/            # DaisyUI-based building blocks (Table, Modal, Pager…)
    routes/
      +layout.svelte         # shell: sidebar nav (real <a href> links), topbar
      dashboard/
      messages/              # ?page= in the URL → survives refresh
      users/[username]/      # full history, photos, take-over from here
      fake-users/
      impersonate/[fake]/[peer]/
      settings/
      notifications/
```

Key principles that fix current bugs by construction:
- **URL is the source of truth** for page/filters (bug 6 gone: refetch keeps the
  page because the page IS the route).
- **Components own their scroll** and only auto-scroll when pinned to bottom (bug 2).
- **One `<Message>` component** renders text + attachments everywhere (bugs 3/7).
- **Typed API models** — the impersonation context is a typed store set on
  take-over (bug 1 impossible: the send reads the store, not stale globals).
- Sidebar items are `<a href>` → open-in-new-tab works (the anchor-links gap).

## Backend: mostly reuse, small additions

The rewrite consumes the existing attribute-routed API. Gaps to close (also useful
for the current panel):
- `/api/admin/messages` should return **attachment** data (bug 7).
- A consolidated **user detail** endpoint (all conversations + messages + photos +
  take-over affordance) for `users/[username]` (bugs 4/5).
- Everything else (auth Bearer, settings, notifications, impersonation, realtime
  config/auth) is already there.

## Migration strategy — incremental, not big-bang

1. **Stand up** the SvelteKit app building to `public/admin-next/` (parallel to the
   live panel). Shared auth (same Bearer/localStorage).
2. **Port tab-by-tab**, highest-pain first: Messages → Users/history → Impersonate
   → Fake users → Settings → Dashboard → Notifications. Each ported tab links back
   to the old panel for the not-yet-ported ones.
3. **Flip** `public/admin/` to the new app once parity is reached; keep the old
   file one release for rollback.
4. Delete the legacy file.

This keeps every step shippable and reversible.

## Build / deploy

- `admin-ui/` builds with Vite to static files copied into `public/admin/` (or
  `-next/`). Add an `npm run build:admin` step; commit the built assets (shared
  hosting has no Node) OR build in CI. Keep assets self-contained (same-origin CSP).
- No new runtime dependency on the server.

## Phases & rough effort

| Phase | Scope | Effort |
|---|---|---|
| 0 | Scaffold SvelteKit+Tailwind+DaisyUI, typed API client, auth, layout/nav, realtime wrapper | ~2–3 d |
| 1 | Messages (routed pagination, filters, photos), Users + full history + take-over | ~3–4 d |
| 2 | Impersonate, Fake users | ~3 d |
| 3 | Settings (incl. Realtime section), Notifications, Dashboard/widgets | ~3 d |
| 4 | Parity sweep, mobile-responsive pass, cutover + delete legacy | ~2 d |

## Risks

- **Auth/session parity** — the new app must reproduce the Bearer + admin-session
  flow exactly (including the rate-limit/lockout now in place).
- **Build pipeline on shared hosting** — commit built assets or add CI; document it.
- **Feature drift** — the legacy panel keeps changing; freeze non-critical admin
  changes during the port, or port onto a stable API only.
- **Scope** — impersonation + bot tooling is the richest area; budget accordingly.

## Not in scope

- Public chat front-end (separate; its own mobile-first pass is a different task).
- Backend API redesign (reused as-is, with the two additions above).

---

## Framework feature: scaffolded admin SPA

The framework already scaffolds apps and lets a project opt into pieces (realtime
transport, `pramnos-realtime.js`, auth). An **admin SPA generator** is the natural
next scaffolding capability: most Pramnos apps need an admin surface, and every one
currently hand-rolls it. RCB is the pilot; the reusable parts get promoted upstream.

### What the framework provides

- **A generator command** — e.g. `pramnos make:admin` (or a flag on `make:app`):
  ```
  pramnos make:admin --toolkit=svelte-daisyui
  pramnos make:admin --toolkit=react-shadcn
  ```
  Scaffolds an `admin-ui/` project wired to the app's API, auth and realtime, with
  a build step that emits static assets under the app's `public/admin/`.

- **A UX-toolkit choice** (like choosing a DB/cache driver): the generator picks a
  **template preset** but the generated code is plain app code the project owns.
  Candidate presets:
  - `svelte-daisyui` (recommended default — small, fast, great DX)
  - `react-shadcn`
  - `vue-daisyui`
  Each preset supplies the same **contract** (below) with toolkit-specific
  components, so the app-level route/store code is largely toolkit-agnostic.

- **A generated, typed API client + auth + realtime wrapper** derived from the
  framework's own conventions:
  - **API**: the framework already ships attribute routing + (optionally) OpenAPI;
    the generator reads the route/OpenAPI metadata to emit a **typed client** — so
    admin UIs stay in sync with the backend automatically.
  - **Auth**: a ready `AdminSession` module speaking the framework's Bearer /
    lockout / (future) LoginFlow — the same auth every scaffolded app has.
  - **Realtime**: a thin client over `RealtimeConfig::forClient()` (WS + SSE
    fallback) — reusing exactly what this repo just built.

- **Framework-provided admin building blocks** as a small, toolkit-neutral core
  the presets skin: `DataTable` (server pagination that lives in the URL),
  `ResourceForm`, `Modal`, `Pager`, `Toast`, `AuthGuard`, `RealtimeList`. These
  encode the fixes-by-construction (URL-as-state, one message/attachment renderer,
  pin-to-bottom scroll) so every app inherits them.

### The contract (toolkit-agnostic)

A preset is "valid" if it implements: routing with real URLs, a typed API client
factory, an auth guard + session store, a realtime subscription hook, and the
building-block components above. App code (routes, resource definitions) is written
once against the contract; swapping the toolkit swaps the preset, not the app.

### Why this shape

- **DRY across apps** — the admin surface, auth wiring and realtime client are the
  same problem in every Pramnos app; solve once.
- **Owned, not locked** — generated code lives in the app repo (like the current
  scaffolding output), so projects can diverge; the framework ships templates +
  the shared building-block package, not a runtime black box.
- **Upgrade path** — a `pramnos admin:upgrade` can regen the shared blocks while
  leaving app routes untouched.

### Phasing (framework)

1. Build the RCB admin SPA (body of this doc) directly, but factor the reusable
   parts (API client generator, auth/realtime wrappers, building blocks) behind
   clean seams.
2. Extract those into a framework package + the `svelte-daisyui` preset; make RCB
   consume the package (dogfood).
3. Add a second preset (`react-shadcn`) to prove the contract is toolkit-agnostic.
4. Wire the `make:admin` generator + docs.

RCB gets its modern admin either way; the framework gets a reusable generator as a
by-product of doing RCB's rewrite behind the right abstractions.
