# Phase 8 — Redis behind capabilities (Cache / Event bus / Queue)

**Goal.** Remove every direct dependency on Redis (`RadioChatBox\Database::getRedis()` /
`getRedisForSubscribe()` and raw `\Redis` calls) from the application. Redis is an
*implementation detail* — a **driver** behind a capability, never a thing the app
talks to. After this phase no application class holds a `\Redis`; each depends on one
of three capabilities, and Redis is one interchangeable driver of each, alongside
Database, Pusher/Reverb and (future) Kafka.

This is the Redis analogue of [Phase 7](03-integration-plan.md#phase-7)'s DB
convergence. **Full BC throughout**: no endpoint's response contract, timing, or
behaviour changes; the backing driver is chosen by config; legacy and converged code
coexist during the transition.

---

## 1. Three distinct infrastructures — not one "Redis"

The single most important framing: the app's Redis usage is **three unrelated
capabilities** that merely happen to share Redis as one possible backend. They must
each be their own abstraction — a pub/sub is **not** a cache.

| Capability | Semantics | Framework abstraction | Drivers (Redis = one of many) |
|---|---|---|---|
| **Cache** | key/value + TTL; "remember this, may evaporate" | `Pramnos\Cache\Cache` / `FlatCache` (already adopted app-side as `RadioChatBox\Cache`) | Redis, APCu, file, in-memory, … |
| **Event bus** (broadcast / pub-sub) | publish + subscribe, fan-out, fire-and-forget; "send to whoever is listening now" | `Pramnos\Broadcasting\BroadcastingManager` + `DriverInterface` / `SubscribableDriverInterface` | **Redis** (pub/sub), **Database** (outbox/poll), **Pusher/Reverb** (WS), **Log** (dev), **Null**, **Kafka** (future) |
| **Queue** | work items, delayed/scheduled, at-least-once; "someone processes this later, don't lose it" | `Pramnos\Queue\QueueManager` + `Worker` + `QueueItem` | **Database** (`queueitems`), **Redis** (ZSET — to add), Kafka (topic, future) |

Redis-for-cache, Redis-for-pubsub and Redis-for-queue are **three different
subsystems**. The app declares intent — *"publish this event"*, *"enqueue this job"*,
*"remember this value"* — never *"use Redis"*.

---

## 2. Current application coupling (what to move)

Direct `\Redis` operations in `src/` today, grouped by the capability they really are:

| Ops (call count) | Capability | Notes |
|---|---|---|
| `get` / `set` / `setex` / `del` / `expire` / `ttl` / `exists` (~90) | **Cache** | Mostly already flows through `RadioChatBox\Cache`; the rest is a mechanical route-through. |
| `publish` / `subscribe` (14) | **Event bus** | `StreamController` / `AdminStreamController` already run the SSE edge on the framework `RedisDriver`, but publishers still call `getRedis()->publish()` directly. |
| `incr` / `getSet` (6) | **Cache (atomic)** | Rate-limit counters + the radio "last track" dedup pointer. Needs a Cache `increment()` / atomic op. |
| `zAdd` / `zRangeByScore` / `zRem` / `zRange` / `zCard` (JobQueue) | **Queue** | The delayed-job ZSET — the canonical `QueueManager` use case. |
| `hSet` / `hGet` / `hDel` (16), `lPush` / `lTrim` / `lRange` (5) | *Redis data structures* | See §4 — genuine leaks; re-model or map to a capability. |

---

## 3. What the framework already provides vs. the additions needed

**Already present (no framework work):**
- **Event bus.** `BroadcastingManager::broadcast($channel,$event,$payload)` and
  `via($driver,…)`, with `RedisDriver`, `DatabaseDriver` (+ `BroadcastEventStore` /
  `DatabaseEventStore` poll/outbox), `PusherDriver`, `LogDriver`, `NullDriver`, all
  behind `DriverInterface` / `SubscribableDriverInterface`. `RedisDriver` self-connects
  from `broadcasting.redis` config (host/port/auth/db/prefix) — the app's injected
  connection factory is unnecessary. SSE and WebSocket edges both sit on this one bus.
  See [04](04-broadcasting-backplane-architecture.md).
- **Cache.** `Cache` / `FlatCache` with `remember()`, `has()`, get/set/delete — adopted
  as `RadioChatBox\Cache` in Phase 5.
- **Queue.** `QueueManager` + `Worker` + `QueueItem` + `AbstractTask`, DB-backed.

**Small additive framework improvements required (each with tests, pushed):**
1. **Cache `increment()` / `decrement()`** (atomic, TTL-aware) — so rate-limit counters
   and the dedup pointer leave raw `incr`/`getSet`. (See [02](02-framework-improvements.md).)
2. **Redis queue driver** for `QueueManager` — so `JobQueue`'s ZSET delayed-queue moves
   behind the Queue capability *keeping Redis as the backend* (latency parity for bot
   replies), with the DB driver available as an alternative. ([02](02-framework-improvements.md) §3.)
3. **(optional) A thin `Broadcaster` accessor / `broadcast()` helper** the app resolves
   from the container, so services depend on the capability, not on constructing a driver.
4. **Kafka driver (future, seam only now):** implement `DriverInterface` for the event
   bus and/or a `QueueManager` driver. No app change when it lands — that is the point.

All additive: no existing framework signature/behaviour changes (the Phase-7 rule).

---

## 4. The genuine leaks: hashes and lists

Not every Redis use is a cache. Two are real data-structure choices and need a decision,
not a blind "route through Cache":

- **Lists** (`lPush`/`lTrim`/`lRange`) — the recent-message history cache. This is a
  *cache of PostgreSQL* (`ChatService::loadHistoryFromDB()` already rebuilds it), so it
  is not load-bearing: model it as a Cache value (a bounded array under one key) or drop
  it to a pure DB read with a short Cache wrapper. Either way it becomes driver-agnostic.
- **Hashes** (`hSet`/`hGet`/`hDel`) — inspect each call site; most are a small struct that
  serialises fine as a single Cache value. Where a hash is truly needed, either add a
  first-class capability to the framework or accept it as an explicit, documented Redis
  dependency (a deliberate *"I want Redis"*, isolated in one adapter, not scattered).

Principle: if a use maps to a capability (queue), map it; if it is a cache, make it a
cache; only what is irreducibly a Redis data structure stays Redis — and then it lives
behind one named adapter, never `getRedis()` sprinkled across services.

---

## 5. BC strategy (non-negotiable)

- **Endpoint contracts unchanged.** Same status/keys/messages/timing. SSE clients keep
  receiving the same events; the bus driver is invisible to them.
- **Config-selected driver.** `broadcasting.transport` / driver picks redis|database|
  pusher|kafka; `queue` driver picks database|redis. Flipping config changes backend with
  zero code change — and defaults stay on Redis so production is unchanged.
- **Coexistence during transition.** The framework `RedisDriver` already has a
  *raw-message fallback*, so converted subscribers understand messages from not-yet-
  converted legacy `->publish()` publishers and vice versa. Convert publisher and
  subscriber independently; never a flag-day.
- **Per-capability, per-service, test-backed** (Phase-7 discipline): convert one call
  site/service at a time, keep the suite green, commit per unit, push both repos.
- **`getRedis()` / `getRedisForSubscribe()` stay until the very end** — deleted only when
  zero app code calls them, exactly as `getPDO()` is being retired.
- **Latency parity.** Bot replies are latency-sensitive → the Redis queue/broadcast
  drivers must match current Redis timing; benchmark before switching any default.

---

## 6. Sequencing (low → high risk)

1. **Event bus — publishers.** Route every `getRedis()->publish($chan,$json)` through
   `BroadcastingManager::broadcast()`; the SSE/WS edges already consume the bus. Delete
   the injected connection factory from the stream controllers (the `RedisDriver`
   self-connects from config). → `getRedisForSubscribe()` retires.
2. **Cache + atomic counters.** Route remaining `get/setex/del/expire` through
   `RadioChatBox\Cache`; add framework `increment()`/`decrement()`; move rate-limit
   counters and the dedup pointer onto it.
3. **Queue.** Add the framework Redis queue driver; reshape `JobQueue` into a
   `QueueManager` client (Redis backend); the worker consumes via `Worker`.
4. **Leaks (§4).** Re-model the message-history list and the hashes.
5. **Retire.** Once no app class references `\Redis`, delete `getRedis()`/
   `getRedisForSubscribe()`/`setRedis()`; `RadioChatBox\Database` becomes purely the
   framework-DB seam (then rename/dissolve — see below).

**Acceptance.** Full suite green per step; SSE/WS clients unaffected; bot-reply latency
unchanged; a driver-swap smoke test (e.g. flip broadcast driver to `database` in a test
and confirm the same events arrive).

**Rollback.** Per-site reverts; every new driver is additive framework code toggled off
via config back to the previous path.

---

## 7. End state

The app depends on **three capabilities** — `Cache`, `Broadcaster`, `Queue` — and holds
no `\Redis`. Redis is one driver of each, beside Database / Pusher / Kafka.
`RadioChatBox\Database` then retains only `getDb()` + the per-install prefix/instance
helpers (Phase 7), at which point it is a small framework-DB seam and can be renamed
(e.g. `Connections`) or dissolved. See [00](00-overview-and-bc-strategy.md),
[02](02-framework-improvements.md), [04](04-broadcasting-backplane-architecture.md).

---

## 8. Verified execution status & findings (2026-07-28)

**Prerequisite done.** Phase 7 is complete: all services *and* all 10 controllers use
`Database::getDb()`; no `src/` code calls `getPDO()` (tests still do, for seeding). Full
suite green (761). Phase 8 has **not** started — no app code changed yet.

### 8.1 Verified BC-risk analysis of the event bus (the Step-1 blocker)

Who actually consumes the Redis pub/sub channels was audited before planning:

- **Only two consumers**, both server-side: `StreamController` and `AdminStreamController`,
  each via the framework `RedisDriver`, which **already decodes both** the framework
  envelope *and* raw legacy JSON (raw-message fallback). Their callbacks `switch` on the
  **channel** and use the payload array — the event name is irrelevant to them.
- **Frontend is insulated.** The three `EventSource` clients (`chat.js` → `/api/stream`,
  admin `/api/admin/stream`, impersonate) consume **SSE named events emitted by the
  controllers**, never raw Redis. The on-wire Redis envelope never reaches the browser.
- **`ChatService::subscribe()` is dead code** — zero callers anywhere in the repo (no
  daemon reads raw pub/sub). Safe to delete in Step 1.
- **No legacy raw-Redis SSE `.php`** remains (`public/api` is empty; all migrated).

**Conclusion:** routing publishers through `BroadcastingManager::broadcast()` is BC-safe —
no change reaches the frontend, and the sole consumers already handle the new envelope.

### 8.2 ⚠️ Prefix inconsistency (a latent bug the migration will normalize)

The Redis connection sets only `OPT_READ_TIMEOUT`, **not** `OPT_PREFIX` — phpredis does
**not** auto-prefix. Publishers prefix the channel *manually*, and **two do not**:

- `ChatService.php:1485` → `publish(self::USER_UPDATE_CHANNEL, …)` — **unprefixed**
- `AdminModerationController.php:216` → `publish('chat:user_updates', …)` — **unprefixed**

Subscribers (`RedisDriver`) listen on `prefix . 'chat:user_updates'`. So on any install
with a non-empty prefix (multi-install by DB name), those two events (a display-name
change and the kick notification) are published to the wrong channel and **never reach the
SSE edge today**. It "works" only where the prefix is empty (single install).

`BroadcastingManager::broadcast($channel, …)` always applies the *configured* prefix
consistently, so converting these normalizes them. **Decision for Step 1:** treat this as
a latent bugfix (recommended) — after conversion all channels are prefixed uniformly;
verify the production prefix value first and call out the behavioural change explicitly,
since on a prefixed install it starts delivering events that previously vanished.

### 8.3 Exact publisher call sites (Step-1 checklist — 13 sites)

Channels are shown as intended (all should end up `broadcast('<channel>', '<event>', $payload)`):

| File:line | Channel | Prefixed today? |
|---|---|---|
| `ReactionService.php:259` | `chat:updates` | yes (`$this->prefix`) |
| `ChatService.php:116` | `chat:updates` | yes (`prefixKey`) |
| `ChatService.php:1485` | `chat:user_updates` | **NO** ⚠️ |
| `BotService.php:889` | `chat:private_messages` | yes |
| `MessageActionController.php:241` | `chat:updates` | yes |
| `MessageActionController.php:561` | `chat:private_messages` | yes |
| `MessageActionController.php:596` | `chat:admin_notifications` | yes |
| `ProfileController.php:160` | `chat:updates` | yes |
| `ProfileController.php:166` | `chat:user_updates` | yes |
| `AdminModerationController.php:216` | `chat:user_updates` | **NO** ⚠️ |
| `AdminModerationController.php:300` | `chat:updates` | yes |
| `AdminModerationController.php:363` | `chat:updates` | yes |
| `AdminImpersonationController.php:308` | `chat:private_messages` | yes |

Each payload already carries a `type` field (`refresh_history`, `display_name_changed`,
`user_kicked`, `private`, `fake_user_dm`, `message_edited`, …) — reuse it as the
broadcast **event name** so the envelope is self-describing (the current subscribers
ignore it, but future WS/Pusher consumers can filter on it).

### 8.4 Remaining raw-Redis coupling (later steps)

Approximate current counts (`$redis->` / `$this->redis->` in `src/`): `getRedis()` app
refs **40**, `getRedisForSubscribe()` **2** (the two SSE controllers). Cache-shaped ops
(`get/set/setex/del/expire/ttl/exists/keys/scan`) dominate → Step 2. `incr` ×2 (counters)
→ Step 2 once framework `increment()` lands. `hSet/hGet/hDel` (active-users hash) and the
message-history `lRange`/list on `$this->redis` in `ChatService` → Step 4 leaks.

### 8.5 Framework gaps confirmed (both additive, need building + pushing to `origin/main`)

- **`Cache::increment()` / `decrement()`** — **absent** in `Pramnos\Cache`. Blocks Step 2
  counters.
- **Redis queue driver** — **absent**; `Pramnos\Queue` is DB-backed only
  (`QueueManager`/`Worker`/`QueueItem`). Blocks Step 3.
- Everything else (`BroadcastingManager`, `RedisDriver` with raw fallback, `QueueManager`)
  already exists — Step 1 needs **no framework change**, only app + a `config/broadcasting.php`.
