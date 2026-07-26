# PramnosFramework Integration — documentation set

Plan for migrating RadioChatBox onto PramnosFramework as native infrastructure, safely, while
the app stays in production. Branch: `feature/pramnos-framework-integration`.

Read in order:

1. **[00 — Overview & BC strategy](00-overview-and-bc-strategy.md)** — start here. What's already
   done on the branch, the one real driver-level nuance, guiding principles, runtime
   prerequisites, migration-history baselining, the phase roadmap, and rollback/verification.
2. **[01 — Native integration map](01-native-integration-map.md)** — 1:1 mapping of every
   RadioChatBox subsystem onto its framework counterpart, with the concrete APIs (DB layer:
   QueryBuilder + ORM + raw SQL; middleware; migrations; daemon orchestrator; scheduler; cache;
   logs; storage/media; health; validation; SSE).
3. **[02 — Framework improvements](02-framework-improvements.md)** — what the framework does **not**
   support yet and must gain (all additive/BC): Redis broadcasting driver + SSE, Redis queue
   driver, Router instance-method dispatch, graceful missing-extension diagnostics, and more.
4. **[03 — Integration plan](03-integration-plan.md)** — the step-by-step, phase-by-phase execution
   plan (files, steps, acceptance test, rollback per phase) for adopting the framework's better
   implementations.

## TL;DR

- The framework is loaded via a Composer **path repository** (symlink to `../PramnosFramework`),
  `mrpc/pramnosframework: @dev`. Only `composer.json` changes (lock/vendor are gitignored).
- Strategy is **strangler + phased**: framework infra is introduced *underneath* the running app;
  the HTTP request path and data layer stay identical until late phases; every phase ships and
  reverts independently.
- The framework has a **full native DB layer** (fluent QueryBuilder + Eloquent-style ORM + raw
  SQL, PostgreSQL first-class). Its driver is native `pgsql`/`mysqli`, **not** the PHP `PDO`
  extension — which only means (a) install `pgsql`, and (b) rewrite `\PDO`-typed call sites onto
  the framework API, done incrementally per service.
- Hard prerequisite: add **`mbstring`** (and `pgsql`) to the Dockerfile/hosts before the framework
  is on any live code path.
- Biggest divergence: real-time is **SSE + Redis** here vs. **Pusher/WebSocket** in the framework —
  resolved by keeping SSE and adding a Redis broadcasting driver (improvement #2), not by rewriting
  the client.
