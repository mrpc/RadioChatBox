# Framework proposal — a strong, pluggable real-time / socket backplane

> **Scope:** this is a **PramnosFramework** enhancement (lands in `../PramnosFramework`), not
> RadioChatBox-only. It generalises the framework's real-time layer so any Pramnos app can drive
> WebSockets/SSE over a **pluggable backplane** — Redis, Database, Kafka, or a custom adapter.
> RadioChatBox uses the Redis adapter; a Kafka-based deployment uses the Kafka adapter; a
> zero-infra app uses the Database adapter.
>
> This supersedes and expands improvement #2 in [`02`](02-framework-improvements.md). BC is
> preserved throughout: everything here is **additive**, and the existing
> `Null`/`Log`/`Pusher` drivers + `LocalBroadcastServer` log-tail behaviour keep working.

---

## 1. The problem with today's design (one concern, two missing halves)

The current `Broadcasting\Drivers\DriverInterface` has a single method — `broadcast()` — i.e. it
models **publish only** ("fire an event at an external service"). That's the right shape for
Pusher/Reverb (a *managed edge*, where someone else runs the sockets), but it can't express the
model RadioChatBox (and any app that runs its own socket server) actually needs:

1. **No backplane/consume side.** The app processes (Apache/PHP-FPM handling a POST) and the
   long-lived socket server are *different processes*. Something must carry an event from the
   publisher to the server. Today `LocalBroadcastServer` gets it by **tailing a JSONL log file** —
   a dev hack with no ordering guarantees, no horizontal scale, and unbounded file growth.
2. **No transport abstraction for the edge.** WebSocket vs SSE vs long-poll is hard-wired.

So we introduce a clean three-layer model and make the **backplane** pluggable.

---

## 2. The three layers

```
   App process (POST /send)                    Socket daemon(s)                 Browser
   ┌───────────────────────┐   publish   ┌──────────────────────────┐  ws/sse  ┌────────┐
   │ broadcast(ch,ev,data)  │ ─────────▶ │  BACKPLANE  ──▶ drain()   │ ───────▶ │ client │
   └───────────────────────┘             │        (Redis/DB/Kafka)   │          └────────┘
        (producer API)                    │   EDGE: WebSocket + SSE   │
                                          └──────────────────────────┘
                                          supervised by DaemonOrchestrator
```

| Layer | Responsibility | Pluggable via |
|---|---|---|
| **Producer API** | app code fires events | `broadcast($channel,$event,$payload)` (unchanged) |
| **Backplane** | carry events between app processes and socket daemon(s); fan-out to all daemon instances | **`Backplane\BackplaneInterface`** — Redis / Database / Kafka / Log / Null / custom |
| **Edge transport** | deliver to browsers | WebSocket (primary) + SSE (fallback), Pusher-wire compatible; or delegate to Pusher/Reverb |

---

## 3. New contracts (additive)

### 3.1 `Backplane\BackplaneInterface`
The backplane is both a **publisher** and a **non-blocking source** the event loop can pump each
tick (this matches `LocalBroadcastServer`'s existing 100 ms `stream_select` loop and works for
every backend — Redis non-blocking read, Kafka `poll(0)`, DB "rows since last id"):

```php
namespace Pramnos\Broadcasting\Backplane;

interface BackplaneInterface
{
    /** Producer side: hand an event to the bus. Called from app processes. */
    public function publish(string $channel, string $event, array $payload): void;

    /**
     * Consumer side, non-blocking: return every message available since the last
     * call. The socket daemon calls this each event-loop tick.
     * @return iterable<BroadcastMessage>
     */
    public function drain(): iterable;

    /**
     * Optional zero-latency hook: a stream resource the daemon can add to its
     * stream_select() read set so it wakes immediately on new data instead of
     * waiting for the next poll. Return null if the backend can't expose one.
     */
    public function selectableStream(): mixed;

    public function connect(): void;
    public function disconnect(): void;
    public function name(): string;
}
```

`BroadcastMessage` is a tiny value object `{channel, event, payload, id?}`.

### 3.2 Reuse of `DriverInterface` for the producer API (BC bridge)
A backplane that can publish **is** a broadcast driver. So each backplane also implements the
existing `Drivers\DriverInterface` (its `broadcast()` just calls `publish()`), which means:

- App code keeps calling `$manager->broadcast($channel,$event,$payload)` exactly as today.
- `BroadcastingManager` needs **no breaking change** — a backplane registers as the default driver.
- The socket daemon takes the **same** backplane instance and `drain()`s it.

One component, one config key, both sides.

### 3.3 Edge transport abstraction
Generalise `LocalBroadcastServer` into a `BroadcastServer` whose **source is a
`BackplaneInterface`** (injected) instead of a hard-coded log file, and whose **edge** is a set of
`EdgeTransportInterface` handlers:

- `WebSocketTransport` — the existing RFC 6455 + Pusher-v7 engine (handshake, framing, ping,
  `pusher:subscribe`), refactored out of `LocalBroadcastServer`.
- `SseTransport` — the `text/event-stream` + reconnect logic (the framework SSE helper), so an
  HTTP streaming action can serve SSE off the same backplane.

Client-side channel routing (`subscriptions[channel]`), presence, and the `private-`/`presence-`
auth hook live in `BroadcastServer`, transport-agnostic.

---

## 4. Backplane adapters that ship with the framework

| Adapter | `publish` | `drain` / source | `selectableStream` | Fan-out to N daemons | Best for |
|---|---|---|---|---|---|
| **`RedisBackplane`** | `PUBLISH {prefix}{channel}` | non-blocking read on a `PSUBSCRIBE {prefix}*` connection | the Redis subscribe socket | native — pub/sub delivers to **all** subscribers | RadioChatBox (matches today exactly), most apps |
| **`DatabaseBackplane`** | `INSERT INTO broadcast_events(...)` | `SELECT … WHERE id > :lastSeen` (+ optional Postgres `LISTEN/NOTIFY` for low latency) | the PG notify socket when `LISTEN` is used, else null (timer poll) | each daemon tracks its own `lastSeen` → all see every row | zero-infra apps; framework's DB-first philosophy |
| **`KafkaBackplane`** | produce to a topic (key = channel) | `rd_kafka` consumer `poll(0)` | rd_kafka queue fd | each daemon in its **own** consumer group → every daemon sees every message | Kafka-based deployments; cross-service event streaming, fan-in from other producers |
| **`LogBackplane`** | append JSONL | tail file (current behaviour) | null | file-tail per daemon | local dev (BC with today) |
| **`NullBackplane`** | no-op | empty | null | — | tests |

Adapters are registered by `BroadcastingServiceProvider` from `app.php`
`broadcasting.backplane` config, and third parties add their own with
`$manager->addDriver(new MyBackplane(...))` in an app service provider (NATS, MQTT, Google
Pub/Sub, AWS SNS/SQS, …) — the whole point of the interface.

> **DB-backed detail.** `broadcast_events(id BIGSERIAL PK, channel TEXT, event TEXT, payload
> JSONB, created_at TIMESTAMPTZ DEFAULT now())` with a periodic prune (a `Scheduling` task) and,
> on Postgres, a trigger issuing `NOTIFY pramnos_broadcast` so daemons wake instantly instead of
> polling. Adding `pg_get_notify`/`LISTEN` support to `Pramnos\Database\Database` is a small
> prerequisite ([`02`](02-framework-improvements.md) — new item, additive).

---

## 5. Running it — daemon + config

### `app.php`
```php
'features' => ['broadcasting'],
'broadcasting' => [
    'default'   => 'redis',          // producer driver = the backplane
    'backplane' => 'redis',          // 'redis' | 'database' | 'kafka' | 'log' | 'null'
    'redis'     => ['host' => envvar('REDIS_HOST'), 'port' => 6379, 'prefix' => 'rcb:bcast:'],
    // 'kafka'  => ['brokers' => envvar('KAFKA_BROKERS'), 'topic' => 'broadcasts', 'group' => 'rcb-ws'],
    // 'database' => ['table' => 'broadcast_events', 'use_listen_notify' => true],
    'edge'      => ['websocket' => ['port' => 6001], 'sse' => ['enabled' => true]],
    'auth'      => \App\Broadcasting\ChannelAuth::class,   // gate private-/presence-
],
```

### Command + supervision
`broadcast:serve --backplane=redis --ws-port=6001` starts a `BroadcastServer` bound to the chosen
backplane. It runs as a **`DaemonOrchestrator`-supervised daemon** (see
[`01`](01-native-integration-map.md) §5.3 / [`03`](03-integration-plan.md) Phase 3) — crash
respawn, heartbeat, graceful `.stop`, deploy-restart — exactly like the worker. This is the piece
the OS-level daemon model unlocks: a long-lived socket server that Apache never touches and
Cloudflare proxies as `wss://` (no 95 s reconnect).

### Horizontal scale
Run several `broadcast:serve` daemons behind a WS-aware load balancer. Each subscribes to the same
backplane and therefore sees every event:
- **Redis** — pub/sub fans out to all subscribers automatically.
- **Kafka** — put **each daemon in its own consumer group** (so it's a broadcast, not a
  work-queue split); document this loudly, it's the classic footgun.
- **Database** — each daemon keeps its own `lastSeen` cursor.
Client→daemon affinity is handled by the LB (sticky by connection, which WS is anyway).

---

## 6. TLS / Cloudflare

Terminate TLS at the edge (Cloudflare / nginx) and run the daemon as plain `ws://` on
`127.0.0.1:6001`; Cloudflare proxies `wss://chat.example/app` → origin `ws://`. The server stays
simple (no cert handling); this is also how Reverb is typically deployed. A direct-TLS option on
the server is possible later but not needed behind Cloudflare.

---

## 7. Client

`pramnos-echo.js` is already Pusher-wire compatible, so the WebSocket path needs no client rewrite.
Add an **SSE fallback transport** to it: try `wss://` first, fall back to the SSE endpoint (same
channel semantics, same backplane) when WS is blocked by a proxy/network. RadioChatBox's existing
SSE client behaviour is the reference implementation for that fallback.

---

## 8. What each project does with this

- **RadioChatBox** → `RedisBackplane`. App keeps `publish`ing to Redis as today; the WS daemon
  replaces the SSE-only model (SSE stays as fallback). Deletes bespoke pub/sub glue. No client
  rewrite (add SSE fallback to `pramnos-echo.js`).
- **A Kafka-based deployment** → `KafkaBackplane`, reusing an existing Kafka connection (see §9)
  so real-time client push and a cross-service event stream share one bus.
- **Any app** → `DatabaseBackplane`, zero extra infrastructure.

---

## 9. Kafka adapter — design

`KafkaBackplane` uses `ext-rdkafka` (PECL) directly — `RdKafka\{Producer,KafkaConsumer,Conf,
TopicConf,Message}` (requires `librdkafka-dev` + `pecl install rdkafka` in the image). No Composer
Kafka package needed. Config (brokers, optional SASL_SSL/PLAINTEXT) lives under
`broadcasting.kafka` in `app.php`, so a deployment already running Kafka can reuse its existing
broker/credential settings.

- **`publish()`** — produce JSON to a broadcast topic with `producev()`, key = **channel** (so
  librdkafka gives per-channel partition affinity), channel + event in the envelope. Use **async
  `produce()` + `poll(0)`** and flush on an interval / at shutdown — **never a per-message
  `flush()`**: broadcast is latency-sensitive and a multi-second synchronous flush on the hot path
  is unacceptable.
- **`drain()`** — non-blocking `consumer->consume(0)` / `poll(0)` each event-loop tick;
  `selectableStream()` exposes the rd_kafka queue fd for zero-latency wakeups.
- **Offsets** — a live WS daemon only cares about *now*, not replay, so `auto.offset.reset=latest`
  and either auto-commit or no-commit is fine.

### ⚠️ The one Kafka footgun for broadcast fan-out

Kafka consumer groups **split** partitions across members (work-queue semantics — each message is
handled by exactly one member of the group). That is the *opposite* of what broadcast needs: every
socket daemon must receive **every** message to push it to its own connected clients. Therefore:

> **Put each socket-daemon instance in its own unique `group.id`.** A shared group would silently
> deliver each broadcast to only one daemon, so clients on the other daemons miss messages.

(This differs from the typical Kafka *ingestion* pattern — shared group, durable auto-commit,
hand-off to the job queue — which is correct for consuming external events but wrong for
real-time fan-out. The same `KafkaBackplane` driver can do either; the group strategy is what
distinguishes the two.)

### The broader win: one transport abstraction, two roles
`BackplaneInterface` (`publish` + `drain`) is intentionally the same shape a general **message
bus** needs, so the same driver can serve **both**:

1. **Real-time broadcast** (this doc) — fan-out to WS/SSE clients (one group per daemon).
2. **Inter-service eventing / ingestion** — shared-group consumers handing off to the DB Queue.

Exposing this as a framework-level `KafkaBackplane` (implementing both `BackplaneInterface` and the
existing `DriverInterface`) means apps stop hand-rolling `RdKafka\*`-typed glue and instead work
against the transport-neutral `BroadcastMessage` value object — which is what makes the socket
infrastructure "strong and pluggable" rather than Redis-only.

> **Scope note.** For RadioChatBox, `RedisBackplane` is all that's needed. `KafkaBackplane` exists
> to (a) prove the interface is genuinely backend-neutral and (b) give Kafka-based deployments a
> framework-native path. Ship `Redis` + `Database` + `Log`/`Null` first; `Kafka` second.

---

## 10. Delivery plan (framework side, all additive)

1. Extract `WebSocketTransport` from `LocalBroadcastServer`; introduce `BroadcastServer(source:
   BackplaneInterface, transports: EdgeTransportInterface[])`. Keep `LocalBroadcastServer` as a
   thin BC shim (log backplane + WS transport).
2. Add `BackplaneInterface` + `BroadcastMessage` + `RedisBackplane`, `DatabaseBackplane`,
   `LogBackplane`, `NullBackplane` (each also implementing `DriverInterface`).
3. Add `SseTransport` + `Http\Response::eventStream()` helper; add SSE fallback to
   `pramnos-echo.js`.
4. Add `KafkaBackplane` (§9) using `ext-rdkafka`.
5. Add Postgres `LISTEN/NOTIFY` support to `Database` for the DB backplane's low-latency path.
6. Wire `BroadcastingServiceProvider` to build the configured backplane; extend `broadcast:serve`
   with `--backplane`; ship a `DaemonOrchestrator` recipe.
7. Tests per backend (publish→drain round-trip, N-daemon fan-out, reconnect, private-channel auth)
   and docs. **BC:** existing drivers, `LocalBroadcastServer`, and the log-tail path untouched.
