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
$broadcasting = $app->container->get('broadcasting'); // Pramnos\Broadcasting\BroadcastingManager

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

Return the `StreamedResponse` from your dispatcher exactly like a `Response`
(both expose `send()`).

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

With `--channels`, the server opens a **non-blocking** RESP pub/sub socket to
Redis (`RedisSubscriberSocket`) and joins it to its own `stream_select` loop — no
blocking client, no fork. Channels are prefixed from `broadcasting.redis.prefix`.

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
| Browser reconnect | automatic (`EventSource`) | manual / via client lib |

Use **SSE** for one-way feeds on constrained/shared hosting; use **WebSocket**
when you control the host and need bidirectional messaging.
