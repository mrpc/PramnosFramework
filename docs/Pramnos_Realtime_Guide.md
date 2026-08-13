---
use_cases:
  - Pushing an event to a connected browser
  - Choosing between SSE and WebSockets
  - Configuring the broadcasting backplane or driver
---

# Pramnos Realtime Guide (SSE & WebSockets)

The **Realtime** stack lets an application push live events to browsers and pick
*how* they are delivered per deployment — **Server-Sent Events (SSE)** on shared
hosting, a self-hosted **WebSocket** server on a custom box, or a managed
**Pusher/Reverb** server — without changing application code.

It is built in three cleanly separated layers:

| Layer | What it does | Classes |
|-------|--------------|---------|
| **Backplane** | server-side publish/subscribe fan-out | `RedisDriver`, `DatabaseDriver`, `LogDriver`, `PusherDriver` |
| **Transport (edge)** | how events reach the browser | `StreamedResponse` + `SseWriter` (SSE); `LocalBroadcastServer` (WebSocket); Pusher |
| **Selection** | tells the client which transport to use | `RealtimeConfig`, `pramnos-realtime.js` |

!!! tip "SSE and WebSocket share the backplane"
    Both edges sit on the **same** Redis (or database) backplane and consume the
    **same** channels. They differ only at the browser edge: SSE is a one-way
    HTTP event stream (works through Apache/Cloudflare with no special server);
    WebSocket is a full-duplex `ws://` connection served by a long-running daemon.

---

## Configuration

All realtime configuration lives in `app/app.php` under `broadcasting`:

```php
return [
    'name'      => 'MyApp',
    'namespace' => 'MyApp',
    'features'  => ['broadcasting'],
    'broadcasting' => [
        // Server-side backplane used for publish()/subscribe():
        'default'   => 'redis',      // null | log | redis | database | pusher

        // Client edge the browser should use:
        'transport' => 'sse',        // sse | websocket | pusher

        'redis' => [
            'host' => '127.0.0.1', 'port' => 6379,
            'database' => 0, 'password' => null, 'prefix' => 'myapp:',
        ],
        'sse' => [
            'url' => '/api/stream',  // your SSE endpoint
        ],
        'websocket' => [
            'scheme' => 'ws', 'host' => 'localhost', 'port' => 6001,
            'app_key' => 'myapp-local',
        ],
        'pusher' => [
            'app_id' => '...', 'app_key' => '...', 'app_secret' => '...',
            'cluster' => 'eu',       // or host/port/scheme for self-hosted Reverb
        ],
    ],
];
```

`default` selects the **backplane** (where events are published/consumed);
`transport` selects the **edge** the browser connects through. They are
independent: e.g. `default = redis`, `transport = sse` publishes to Redis and
serves browsers over SSE.

---

## The backplane

### Publishing

Resolve the manager from the container and publish:

```php
$broadcasting = $app->getContainer()->get('broadcasting'); // Pramnos\Broadcasting\BroadcastingManager

$broadcasting->broadcast(
    channel: 'chat.updates',
    event:   'message.created',
    payload: ['id' => 42, 'body' => 'Hello!']
);
```

Models can broadcast their own lifecycle events with the `Broadcastable` trait:

```php
use Pramnos\Broadcasting\Broadcastable;

class Message extends \Pramnos\Database\OrmModel
{
    use Broadcastable;
    protected string $broadcastChannel = 'chat.updates';
}
// $message->save();  // broadcasts "Message.created" / "Message.updated"
```

### Subscribing (SubscribableDriverInterface)

The Redis and Database drivers implement `SubscribableDriverInterface`, so the
same backplane can be **consumed**, not just published to:

```php
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\SubscriptionOptions;

$driver = new RedisDriver(['prefix' => 'myapp:']);

$driver->subscribe(
    channels: ['chat.updates'],
    onEvent: function (string $channel, string $event, array $payload): bool {
        echo "$channel/$event: " . json_encode($payload) . "\n";
        return true;               // return false to stop the loop
    },
    options: new SubscriptionOptions(
        readTimeout: 20,           // surface an idle tick every 20s
        maxRuntime:  95,           // stop after 95s (e.g. Cloudflare edge limit)
        onIdle: fn () => !connection_aborted(),  // ping/liveness; false = stop
    ),
);
```

Events are published and consumed as a symmetric envelope
`{event, payload, timestamp}`. Non-enveloped (legacy) messages are still
delivered — with an empty event name and the decoded body as the payload — so a
migration can move publishers and consumers over incrementally.

!!! note "Drivers"
    - **redis** — `\Redis::publish` / `subscribe` with reconnect. Primary backplane.
      Pub/sub, so it keeps **no history**: an event published while nobody is
      subscribed is not delivered later.
    - **redis-stream** — the same envelope on a Redis **Stream**, which can be
      replayed. Use this one for SSE, where every reconnect opens a gap; see
      [Reconnecting, and the events published during it](#reconnecting-and-the-events-published-during-it).
    - **database** — appends to a `broadcast_events` table and polls it (for hosts
      without Redis). Run the shipped `broadcasting` migration to create the table.
    - **log** — writes JSONL; the WebSocket server can tail it.
    - **pusher** — publishes to Pusher / self-hosted Reverb.
    - **Kafka** — not shipped, but implementing `SubscribableDriverInterface` is all
      a `KafkaDriver` needs.

---

## SSE transport

`StreamedResponse` sends status + headers, tears down output buffering, then runs
a producer that streams for the life of the request. `StreamedResponse::sse()`
presets the event-stream headers and hands the producer an `SseWriter`.

### A complete SSE endpoint

```php
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;
use Pramnos\Routing\Attributes\Route;

class StreamController
{
    #[Route('/api/stream', methods: 'GET', name: 'stream')]
    public function index(): StreamedResponse
    {
        $driver = new RedisDriver(['prefix' => 'myapp:']);

        return StreamedResponse::sse(function (SseWriter $sse) use ($driver) {
            $sse->comment('connection established');   // ": ..." keeps it warm

            // Initial snapshot the client renders on connect:
            $sse->event('history', $this->recentMessages());

            // Then live events, with keep-alive pings and reconnect handling:
            $sse->stream(
                driver:   $driver,
                channels: ['chat.updates'],
                onEvent:  function (string $channel, string $event, array $payload, SseWriter $w) {
                    $w->event($event, $payload);       // forward to the browser
                },
                maxRuntime:   95,   // ask the client to reconnect before the edge cuts it off
                pingInterval: 20,   // ": ping" comment every 20 idle seconds
            );
        });
    }
}
```

`SseWriter` frame helpers: `event($name, $data)`, `data($data)`,
`comment($text)`, `ping()`, `retry($ms)`. A non-string `$data` is JSON-encoded;
multi-line payloads are split into multiple `data:` lines per the SSE spec.

#### Periodic server-side work with `onTick`

`stream()` accepts an optional `onTick` callback, invoked on every idle tick
(roughly each `pingInterval` seconds) just before the keep-alive ping — for
periodic side effects while the client stays connected. Return `false` to end the
stream. The canonical use is **server-driven presence**: because the live SSE
connection is itself proof the user is online, refresh their presence each tick
instead of trusting only a client heartbeat.

```php
$sse->stream(
    driver:       $driver,
    channels:     ['chat.updates'],
    onEvent:      $onEvent,
    maxRuntime:   95,
    pingInterval: 20,
    onTick:       function (SseWriter $w) use ($presence, $userId) {
        $presence->touch($userId);   // ~every 20s, for as long as the client is connected
    },
);
```

Omit `onTick` for the historical ping-only idle behaviour — it is a trailing
optional parameter, so existing callers are unaffected.

Return the `StreamedResponse` from your dispatcher exactly like a `Response`
(both expose `send()`).

#### Reconnecting, and the events published during it

`EventSource` reconnects on its own, and `maxRuntime: 95` makes the server end
the stream deliberately — so every client reconnects on a schedule, roughly
every 95 seconds. Whatever is published in the window between the close and the
new subscription has to come from somewhere, or it is simply lost. Nothing
errors; the client never sees those events. Two applications lost data this way
before it was noticed.

**SSE specifies the answer, and the framework now implements it.** Every event
that has a backplane id is written with an `id:` frame; the browser remembers
the last one and sends it back as `Last-Event-ID` on reconnect; `stream()` reads
that header and resumes from it. No client code, and no application code:

```php
$sse->stream(
    driver:   new RedisStreamDriver(['prefix' => 'myapp:']),
    channels: ['chat.updates'],
    onEvent:  fn ($channel, $event, $payload, $w) => $w->event($event, $payload),
    maxRuntime: 95,
);
```

That works because the driver can replay. **Which driver you choose decides
whether any of this happens:**

| Driver | Replay | Notes |
|---|---|---|
| `RedisStreamDriver` | ✅ | Redis Streams. `XADD MAXLEN ~` caps history per channel (`maxLength`, default 1000). |
| `DatabaseDriver` | ✅ | Already durable; resumes from the row id. |
| `RedisDriver` | ❌ | Pub/sub keeps nothing. Right for a WebSocket daemon that stays connected; loses the gap for SSE. |

`RedisDriver` is unchanged and still the default — switching a deployment to
streams is a decision about retention, not a bug fix applied behind your back.
Both write the same envelope, so consumers do not change.

##### Choosing a resume point yourself

`stream()` takes `sinceId` when the endpoint knows better than the client:

```php
$sse->stream(..., sinceId: (string) $user->lastSeenEventId);   // your own cursor
$sse->stream(..., sinceId: '');                                // opt out; start live
```

With neither, the resume point comes from `Last-Event-ID`, then from `?since=`
(for clients that keep their own cursor — a native app, a polyfill), and
otherwise a first connection starts live rather than replaying whatever history
happens to exist.

##### A note on `$`, if you write your own driver

Redis's `$` cursor means "whatever is newest **at the moment this read is
issued**". A consume loop that re-reads after every timeout and passes `$` again
skips anything published between the two reads — the same gap this whole section
is about, reopened once per read timeout. `RedisStreamDriver` therefore resolves
"start from now" to a concrete entry id before its first read, and advances it
from there. If you implement `SubscribableDriverInterface` over another log,
the same rule applies: a cursor has to be a fixed point, not a moving one.

##### What replay does not solve

Retention is finite and deliberately so. A stream capped at 1000 entries covers
a reconnect; it does not cover a laptop that was closed for an hour. Send a
snapshot on connect for that case — the `history` event in the example above —
and give events stable ids so the client can discard what it has already seen. An
event published *during* the snapshot query arrives both ways, and that is the
safe direction to err in.

### On the browser

```js
const es = new EventSource('/api/stream', { withCredentials: true });
es.addEventListener('history', (e) => render(JSON.parse(e.data)));
es.addEventListener('message.created', (e) => append(JSON.parse(e.data)));
```

---

## WebSocket transport

`LocalBroadcastServer` is a dependency-free PHP WebSocket server implementing
RFC 6455 + the Pusher wire protocol, so `pusher-js` / `pramnos-echo.js` clients
connect unchanged. Run it as a daemon:

```bash
# Tail the LogDriver file (simplest):
php ./bin/pramnos broadcast:serve --port=6001

# Or consume Redis directly (non-blocking, no file hop) and enforce auth:
php ./bin/pramnos broadcast:serve --port=6001 --channels="chat.updates,presence-room"
```

With `--channels`, the server opens a **non-blocking** RESP socket to Redis and
joins it to its own `stream_select` loop — no blocking client, no fork. Channels
are prefixed from `broadcasting.redis.prefix`.

### The ingest has to match the driver

There are two ingests, and **which one is right follows from which driver
publishes**. Getting the pair wrong is silent, not loud:

| The application publishes with | The daemon reads with | Redis command |
| --- | --- | --- |
| `RedisDriver` | `RedisSubscriberSocket` | `SUBSCRIBE` |
| `RedisStreamDriver` | `RedisStreamSocket` | `XREAD BLOCK 0` |

`SUBSCRIBE` on a key that only ever receives `XADD` is a **perfectly healthy
subscription that is never delivered anything** — no error, no warning, no events.
An application that wanted SSE replay (which needs the stream driver) and a
WebSocket daemon therefore had to publish every event twice, once with `PUBLISH`
and once with `XADD`, putting two representations of one event on the backplane.
That is what `RedisStreamSocket` removes.

```php
use Pramnos\Broadcasting\RedisStreamSocket;

$server->useRedisIngest(new RedisStreamSocket(
    $redisConfig,                       // host, port, password, database, count
    ['app:chat', 'app:presence'],       // fully-qualified stream keys
    $lastIds                            // optional: key => last id handled
));
```

`useRedisIngest()` accepts either implementation — it takes the
`RedisIngestInterface` they share.

**A stream reader also survives a restart of the daemon.** Its position is a
cursor, not a subscription: a worker restarted mid-deploy with `SUBSCRIBE` misses
everything published while it was down, while one reading from its last id is
given the gap. `cursors()` returns the position per stream — persist it, hand it
back as the constructor's third argument, and a redeploy costs nothing. With no
cursor supplied, reading starts at `$`: new entries only.

### Authentication

By default the server is permissive (`AllowAllAuthorizer`) for local dev. In
production, configure `broadcasting.pusher.app_secret` and `broadcast:serve`
enforces the app key at handshake and Pusher HMAC signatures on
`private-`/`presence-` channels via `PusherAuthorizer`:

```php
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;

$server = new LocalBroadcastServer('my-key', logFile: null,
    authorizer: new PusherAuthorizer('my-key', 'my-secret'));
```

Implement `ConnectionAuthorizer` yourself to plug in a custom policy.

### Reacting to who is connected (`onTick` + `subscribedChannels()`)

`LocalBroadcastServer::onTick(fn(int $clients, int $subs))` fires after each
event-loop iteration. Paired with `subscribedChannels()` — a snapshot of the
channel names that currently have at least one subscriber — it lets the app act
on the live audience without patching the server. The typical use mirrors the SSE
`onTick`: **server-driven presence for WS-transport clients**, who use WebSockets
*instead of* SSE and so never trigger the SSE presence refresh.

```php
$lastAt = 0;
$server->onTick(function (int $clients, int $subs) use (&$lastAt, $server, $presence) {
    if (time() - $lastAt < 20) { return; }   // throttle: onTick fires every loop
    $lastAt = time();
    foreach ($server->subscribedChannels() as $channel) {
        if (str_starts_with($channel, 'private-user-')) {
            $presence->touch(substr($channel, strlen('private-user-')));
        }
    }
});
```

Note the WS layer authenticates channels with an HMAC signature, not your app
token, so it does not see a session id — derive whatever identity you need from
the channel name (e.g. a per-user `private-user-<id>` convention).

### Supervising the daemon

Run `broadcast:serve` under the `DaemonOrchestrator` (crash respawn, graceful
stop, redeploy) by returning a process spec whose tokens invoke it — see the
[Console Commands guide](Pramnos_Console_Guide.md).

---

## Choosing the transport at runtime

`RealtimeConfig::forClient()` turns the server config into a **client-safe**
config (never leaks `app_secret` or Redis password). Serve it to the page:

```php
use Pramnos\Broadcasting\RealtimeConfig;

$clientConfig = RealtimeConfig::forClient($app->applicationInfo['broadcasting']);
// e.g. {"transport":"sse","url":"/api/stream"}
```

Then let `pramnos-realtime.js` connect the right way:

```html
<script>window.__realtime = <?= json_encode($clientConfig) ?>;</script>
<script src="/vendor/pramnos-realtime/pramnos-realtime.js"></script>
<script>
  const rt = PramnosRealtime.connect(window.__realtime);
  if (rt.transport === 'sse') {
      rt.on('message.created', (data) => append(data));   // named events
  } else {
      rt.channel('chat.updates').listen('message.created', append); // channel + event
  }
</script>
```

!!! warning "Two event models"
    SSE is a single stream of **named events** (`rt.on(event, cb)`). WebSocket /
    Pusher is **channel + event** (`rt.channel(name).listen(event, cb)`). Branch on
    `rt.transport` if your frontend must support both. For `websocket`/`pusher`,
    load `pramnos-echo.js` (and the Pusher SDK) first.

Flip `broadcasting.transport` between `sse`, `websocket`, and `pusher` to move a
deployment across edges with **no code change**.

---

## Choosing between SSE and WebSocket

| | SSE | WebSocket |
|-|-----|-----------|
| Direction | server → client | full-duplex |
| Protocol | plain HTTP (long-lived response) | `ws://` upgrade |
| Behind Apache/Cloudflare | works out of the box | needs a WS-aware proxy path |
| Server process | normal request lifecycle (shared hosting OK) | long-running daemon |
| Browser reconnect | automatic (`EventSource`), with `Last-Event-ID` replay on a replayable driver | manual / via client lib |

Use **SSE** for one-way feeds on constrained/shared hosting; use **WebSocket**
when you control the host and need bidirectional messaging.

## Default broadcasting manager from the ConnectionManager

`BroadcastingManager::instance()` returns a lazy, process-default manager pre-wired
with a `RedisDriver` on the shared `Pramnos\Redis\ConnectionManager` (its
per-install prefix + pooled connection), with `redis` as the active driver — so an
app broadcasts through the capability without registering the driver itself:

```php
\Pramnos\Broadcasting\BroadcastingManager::instance()
    ->broadcast('chat:updates', 'message', $payload); // channel prefixed by the driver
```

`BroadcastingManager::setInstance(?self)` overrides/resets it (bootstrap + test
seam). It is named `instance()`/`setInstance()` (not `default()`) to avoid clashing
with the existing `setDefault(string $driver)` instance method that selects the
active driver.
