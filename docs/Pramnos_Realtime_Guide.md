---
use_cases:
  - Pushing an event to a connected browser
  - Choosing between SSE and WebSockets
  - Configuring the broadcasting backplane or driver
  - Consuming a third party's WebSocket or push feed instead of polling it
  - Reading WebSocket frames inside a worker that already has its own loop
  - Deciding who may subscribe to a private or presence channel
  - Serving the /broadcasting/auth endpoint a Pusher or Echo client needs
  - Using AuthServer applications as realtime app keys instead of config values
  - Showing who is currently in a room, and reacting when they join or leave
  - Sending a typing indicator or cursor from one browser to another
  - Stopping an optimistic UI from rendering its own change twice
  - Publishing an event from a deploy script or a service in another language
  - Finding out which channels are occupied, or who is in one, from outside the daemon
  - Reacting when a room becomes empty, or when a user's last connection goes
  - Serving wss:// without putting a proxy in front
  - Defining an event once instead of repeating channel and payload at every call site
  - Monitoring a running WebSocket daemon, or telling a throttled client from a quiet one
  - Sending a payload the relay itself must not be able to read
  - Running more than one WebSocket daemon behind a load balancer
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

class Message extends \Pramnos\Application\OrmModel
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
the stream deliberately — so every client reconnects on a schedule, every 95
seconds. Whatever is published in the window between the close and the
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

#### `maxRuntime` is when the stream ends — and the client is told when to hand over

**`maxRuntime` is a deadline, and it used to be a floor.** A driver checks it at the top of
its loop and then blocks for `readTimeout` seconds (`readTimeout = max(1, pingInterval)`), so
a deadline falling *during* a read was not noticed until that read returned. The stream ended
somewhere in **`[maxRuntime, maxRuntime + pingInterval]`**:

| Channel | Deadline noticed | Close landed at |
|---|---|---|
| Busy — an event arrives just after the deadline | on that event | ≈ `maxRuntime` |
| Idle — nothing arrives | at the next read timeout | up to `maxRuntime + pingInterval` |

That range mattered because of who has to act on it. A client doing an **overlapping
reconnect** — open the replacement, retire the old one once the replacement proves itself —
must hand over before the server closes, and it had only `maxRuntime` to go on. Its own clock
starts at `open`, strictly after the server started its own, so at equal periods the server
leads by however long the connection took to establish. It wins **exactly on the busy
installs**, where the close lands at the bottom of the range. And the failure is quiet: the
scheduled close arrives as a transport error, the client backs off, everything recovers, and it
looks like an occasional network blip that gets worse under load.

Two things changed:

**The last read is clamped.** Drivers now block for `min(readTimeout, deadline - now)`, so the
stream ends **at** `maxRuntime` regardless of traffic. `RedisStreamDriver`, `RedisDriver` and
`DatabaseDriver` all do this — the clamp lives in `SubscriptionOptions::blockingWindow()`, so
there is one implementation rather than three.

**The stream says when to hand over.** A client should still leave itself a margin, and now it
does not have to guess: the stream opens with a `stream-info` event.

```js
source.addEventListener('stream-info', (e) => {
    const { max_runtime, ping_interval, handover_after } = JSON.parse(e.data);
    setTimeout(() => beginHandover(), handover_after * 1000);
});
```

`handover_after` is `maxRuntime` minus a margin — a tenth of the runtime, bounded to 2–10
seconds — so a client can use it directly instead of hard-coding a period that has to be kept
in sync with a server constant it cannot see. It is sent as its own event, so a client that has
never heard of `stream-info` is unaffected: `EventSource` dispatches by name.

An unlimited stream (`maxRuntime: 0`) sends no `stream-info`, because there is no handover to
schedule.

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

**A stream reader can survive a restart of the daemon** — its position is a cursor, not a
subscription. A worker restarted mid-deploy with `SUBSCRIBE` misses everything published while
it was down, while one reading from its last id is given the gap. `cursors()` returns the
position per stream: persist it, hand it back as the constructor's third argument. With no
cursor supplied, reading starts at `$` — new entries only.

**But work out who the gap is for before persisting anything.** Replay is worth it when the
*subscribers outlive the ingest*. It is worth nothing when they do not, and the second case is
easy to walk into:

| The ingest is | On restart | Replay delivers to |
| --- | --- | --- |
| An SSE endpoint, one process per client | that client reconnects and resumes from `Last-Event-ID` | **that client** — worth it |
| A WebSocket worker owning the listening socket | **every client is dropped with it** | an empty room — worth nothing |

A consumer measured exactly this rather than taking the paragraph above at face value, and came
back with the negative result: their worker owns the socket, so a restart takes every client
with it, there is nobody left to catch up, and their clients re-read their state on reconnect
anyway because WebSocket carries no initial snapshot. Persisting cursors would have added
supervisor state, a stale-cursor failure mode and a backlog to filter, to deliver events to
nobody. **This guide had claimed the benefit without naming the condition it depends on; that
is the correction, and it was theirs.**

If you do persist, filter what comes back: see the ephemeral-event case below.

#### The ingest router gets the entry id, and an ephemeral event needs it

`useIngestRouter()` maps one ingested message to zero or more WebSocket deliveries. Its
callback receives **four** arguments:

```php
$server->useIngestRouter(
    function (string $channel, string $event, $payload, ?string $id = null): array {
        // $id is the backplane entry id — null for a pub/sub ingest, which has none
        return [[$channel, $event, $payload]];
    }
);
```

The id is passed last and defaulted, so a router written with three parameters keeps working
unchanged.

**Declare it when an event's meaning depends on when it was published.** The case that makes
this concrete is an *ephemeral* event — a typing indicator, a transient presence cue. It carries
no timestamp of its own, and a consumer naturally sets state from receipt time, so a **replayed**
cue announces that somebody is typing who stopped minutes ago:

```php
function (string $channel, string $event, $payload, ?string $id = null): array {
    // Redis Stream ids are "<milliseconds>-<sequence>"
    if ($event === 'typing' && $id !== null) {
        $publishedAt = (int) explode('-', $id)[0];
        if ($publishedAt < (int) (microtime(true) * 1000) - 10_000) {
            return [];   // too old to mean anything
        }
    }

    return [[$channel, $event, $payload]];
}
```

Until 2026-08-14 the ingest consumed the id to advance its cursor and dropped it, so a WebSocket
worker could not do this while an SSE stream could — `SseWriter::stream()` has always passed the
id to `onEvent`. The asymmetry stayed invisible because **a worker starting at `$` never
replays**: persisting `cursors()`, which is the whole advantage described above, is exactly the
change that would have surfaced it — as stale cues for every WebSocket client at once, after a
deploy. Cursor persistence and correct ephemeral handling were mutually exclusive; they are not
now.

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

#### If a channel's safety rests on who may subscribe, write that down beside it

The convention above puts the scope **in the name** — `private-user-42` is one user's channel
because of what it is called. The alternative is a single name that everybody entitled to it
shares, with the authorizer doing the separating:

```php
// Safe only because the authorizer admits nobody who should not see all of it
$server->broadcast('private-admin-notifications', 'report', $payload);
```

That is a legitimate design and not a leak — while the authorizer's rule stays as narrow as the
channel is broad. **It becomes one silently the day somebody widens the rule**, because the two
facts live in different files: whoever relaxes an authorizer to admit station owners is reading
the authorizer, not the worker that named the channel.

A consumer hit exactly this fork. Their `private-admin-notifications` is a bare literal where
every public channel beside it rebuilds its name with a station id; they checked before
reporting it, confirmed it is not a leak today because the authorizer requires a platform admin,
and then noted that their own roadmap direction — letting station owners in — would put every
station's reports in every station's panel. They joined the two with a test.

So: **when a channel is shared rather than named, say so where it is broadcast**, and pair it
with the authorizer in a test. A comment on the `broadcast()` call naming the rule it depends on
costs a line and is the only thing that will be in front of the person who widens it.

The general form is worth carrying further than channels. Their words for it, from a related
find in the same layer: *where a transport cannot carry something, look for every mechanism that
assumed it could.* `EventSource` cannot send headers, so a header-based scope silently did
nothing — and applying that rule immediately found a second stream with the same gap.

### Supervising the daemon

Run `broadcast:serve` under the `DaemonOrchestrator` (crash respawn, graceful
stop, redeploy) by returning a process spec whose tokens invoke it — see the
[Console Commands guide](Pramnos_Console_Guide.md).

---

## Channel authorization

A `private-` or `presence-` channel is only private because a client must present a
signed token to join it. The framework could always **verify** that token
({@see PusherAuthorizer}) but never **produce** one, so every application wrote its
own `/broadcasting/auth` endpoint and its own HMAC — production security code,
rewritten per project, the same code every time.

Three pieces now ship instead.

### 1. Rules: `ChannelRegistry`

Register them once, in your own service provider. Patterns are written **without**
the protocol prefix, and placeholders in braces arrive as callback arguments:

```php
use Pramnos\Broadcasting\Auth\ChannelRegistry;

$channels = $app->getContainer()->get('broadcasting.channels');

// private-order.42  →  ('42')
$channels->channel('order.{id}', function (?object $user, string $id): bool {
    return $user !== null && Order::load((int) $id)?->userid === $user->userid;
});

// presence-room.lobby  →  member data, not a boolean
$channels->channel('room.{room}', function (?object $user, string $room): array|bool {
    if ($user === null) {
        return false;
    }

    return [
        'user_id'   => (string) $user->userid,
        'user_info' => ['name' => $user->name],
    ];
});
```

!!! warning "An unmatched channel is denied"
    A channel no pattern matches is refused. A missing rule is not an open channel —
    otherwise every misspelled pattern would be a hole, and the misspelled rule
    would still look registered.

A placeholder matches one segment and never a dot, so `order.{id}` does not cover
`order.42.items`. A pattern that matched more than it names would hand one rule's
decision to a channel it was never written for.

### 2. The endpoint

`Pramnos\Broadcasting\Controllers\Broadcasting` serves the path every Pusher-protocol
client calls by default. Applications reach it by scaffolding a thin wrapper in
their own `Controllers` namespace, the same opt-in pattern as the framework's auth
controllers:

```php
namespace MyApp\Controllers;

use Pramnos\Broadcasting\Controllers\Broadcasting as FrameworkBroadcasting;

class Broadcasting extends FrameworkBroadcasting
{
}
```

```
POST /broadcasting/auth      socket_id, channel_name[, app_key]
→ 200 {"auth": "key:hmac"}                          private
→ 200 {"auth": "key:hmac", "channel_data": "{...}"}  presence
→ 400 malformed request
→ 403 rule said no, no rule matched, public channel, or unknown app key
→ 500 the server has no secret to sign with
```

!!! note "The action is `postAuth`, not `auth`"
    `Controller::auth($action)` is the framework's per-action authorization gate,
    called by `exec()` on every dispatch, so an action named `auth` cannot exist on
    a controller. The name is also what the dispatcher wants: for a non-GET request
    `exec()` resolves `strtolower(METHOD . ucfirst($action))`, so `POST` on the
    `auth` segment lands on `postAuth` with no route entry.

**403 is deliberately one answer for four causes.** A rejected rule, a channel with
no rule, a public channel and an unknown app key return the same body. Telling them
apart would let a caller enumerate which channels have rules and which keys are
real. A missing secret is the one exception and returns **500**, because that is the
operator's misconfiguration rather than the user's lack of permission — reporting it
as "forbidden" sends whoever debugs it to the wrong file.

### 3. Where app keys come from

`broadcasting.apps.source` selects the registry:

```php
'broadcasting' => [
    'apps' => ['source' => 'auto'],   // auto (default) | config | authserver
],
```

| Source | Keys come from | When |
|---|---|---|
| `config` | `broadcasting.pusher.app_key` / `app_secret` | the historical single-app setup |
| `authserver` | the `applications` table | the `authserver` feature is enabled |
| `auto` | whichever of the two the feature list implies | default |

`auto` means the two features travel together: with the `authserver` feature on,
realtime app keys are AuthServer applications; without it, the simple config
implementation runs, byte-identical to before. Naming `authserver` **explicitly**
while the feature is off is an error rather than a silent fallback — falling back
would authorize channels against a different secret than the operator asked for.

#### Marrying the AuthServer

The `applications` table already stores what a realtime edge needs, so there is no
second table and no second admin screen:

| Pusher / Reverb | AuthServer |
|---|---|
| `app_key` | `applications.apikey` (already UNIQUE) |
| `app_secret` | `applications.broadcast_secret`, falling back to `apisecret` |
| `app_id` | `applications.appid` |
| enabled / disabled | `applications.status` |
| secret rotation | `ApplicationsController::rotate()` |

What that buys over a config file is the rest of the row: an `owner`, a `scope`, a
`trusted` flag, the audit log, and `user_app_authorizations` — so a user can revoke
one application's realtime access without touching the others. See the
[AuthServer Integration Guide](Pramnos_AuthServer_Integration_Guide.md).

**`broadcast_secret` is a separate, nullable column on purpose.** A WebSocket daemon
is a long-running process holding every connected app's secret in memory for the
life of the connection; an OAuth2 token exchange reads one and exits. Sharing a
secret between them means a core dump from the daemon leaks OAuth2 client
credentials too. The column is nullable and `apisecret` is the fallback, so an
installation that has not run the migration keeps working — and an operator can
rotate the realtime key without invalidating OAuth2 clients.

#### More than one app, at the edge

`PusherAuthorizer` holds one key and one secret. That is correct for a single-app
deployment and it was also the ceiling: the `applications` table could describe fifty
apps while the daemon only ever verified against the pair in `app.php`.

`Auth\AppRegistryAuthorizer` resolves the app per connection instead, and
`broadcast:serve` wires it automatically when the app source is `authserver`:

```
$ php bin/pramnos broadcast:serve
  Auth: Pusher signatures enforced, app keys from the AuthServer applications table
```

**The app key comes out of the token.** A channel token is `"<appKey>:<hmac>"`, so no
per-connection bookkeeping is needed to know which secret to verify against — the
client says, in the one field it cannot lie about, because naming the wrong app
produces an HMAC that does not verify. That is why the protocol puts the key there.

An unknown key, a disabled application, an app with no secret and a bad signature all
return the same refusal. A caller has no use for the difference, and telling them
apart would let somebody probing keys learn which ones exist.

The daemon's registry carries a **60-second TTL**, unlike the web binding's zero. Two
consequences worth knowing: a query per handshake would block the whole select loop,
and revoking an application takes effect within one TTL rather than at the next
restart.

A misconfigured `apps.source` **stops the daemon** rather than starting it. Falling
back would authorize channels against a different secret than the operator asked for,
with the daemon reporting itself as healthy.

### The daemon never makes this decision

Everything above runs in a normal request, where a session and a database are cheap.
The WebSocket daemon is a single-threaded `stream_select()` loop: one permission
lookup per subscribe — `Gate` reaching an effective-permissions view — would block
every other connection on the process, and after a deploy every client reconnects at
once. So the endpoint decides and signs; the daemon only verifies an HMAC.

That is not a compromise made for this framework. It is why the Pusher protocol has
an auth endpoint at all.

---

## Encrypted channels

A `private-` channel is private because the **server** checks who may subscribe. A
`private-encrypted-` channel adds the thing that check cannot give you: the payload
is unreadable to everything between publisher and subscriber — including the
WebSocket daemon, which relays ciphertext it cannot open, and including a managed
Pusher or Reverb server you do not operate.

That is the whole reason to reach for it: **it moves the trust boundary off the
relay.** If you run the daemon yourself and trust it, `private-` is already enough.

```php
'broadcasting' => [
    // base64_encode(random_bytes(32))
    'encryption_key' => 'PU1FbXBsZUtleUV4YW1wbGVLZXlFeGFtcGxlS2U=',
],
```

Nothing else changes. Name the channel with the prefix, and publish as usual:

```php
$broadcasting->broadcast('private-encrypted-patient-notes.17', 'note.added', $payload);
```

`pusher-js` decrypts these natively, so there is **no client-side code** — the wire
format is Pusher's: a per-channel key of `sha256(channel_name || master_key)`, the
payload sealed with NaCl secretbox, sent as `{nonce, ciphertext}`. The auth endpoint
hands the subscriber the same per-channel key as `shared_secret`, which is why the
derivation is a pure function of the channel name: both ends compute it
independently and it never travels over the socket.

!!! danger "Without a key configured, the prefix does nothing"
    A broadcast to a `private-encrypted-` channel with no `encryption_key` set goes
    out **in the clear**. The prefix is a contract with the client, and only the key
    makes the server keep its half — so a deployment that names channels this way and
    never sets a key has encryption in the name only. There is a test pinning exactly
    that behaviour, so it cannot change quietly.

    Authorizing such a channel with no key **throws**, because the alternative is
    worse: a token without `shared_secret` produces a client that subscribes
    successfully and then silently drops every message it cannot decrypt — a channel
    that looks connected and delivers nothing.

### What it does not protect

**The channel name travels in the clear, and so does the event name.** Only the
payload is encrypted. `private-encrypted-patient.4417` still tells a relay operator
that patient 4417 exists and that something happened to them. Put nothing in a
channel name that the payload is being encrypted to hide.

Rotating the master key changes every channel's key, so anything in flight becomes
undecryptable. Treat it as a one-time secret, not a rotating credential.

---

## Presence channels

A presence channel is a channel that knows who is in it. Subscribe with `join()` and
the three membership callbacks:

```js
PramnosEcho.join('room.lobby')
    .here(function (members)   { render(members); })     // once, on subscribe
    .joining(function (member) { add(member); })         // somebody arrived
    .leaving(function (member) { remove(member); })      // somebody left
    .listen('message.created', append);                  // ordinary events too
```

Each member is `{ id, info }`. **`id` is always a string** — the server casts it and
so does the client, because a client comparing a numeric id against its own gets
`7 !== "7"`, which presents as a member who is in the room but is never recognised
as anybody, including as yourself.

### Membership counts people, not sockets

The server keys membership by connection but reports it by user, and that
distinction is the whole correctness of the feature:

| | |
|---|---|
| One user, three tabs | **one** member, count of 1 |
| Their second tab connects | no `joining` — they were already here |
| They close two of three tabs | no `leaving` — they are still here |
| Their last tab closes | `leaving` fires once |

Getting this wrong in either direction is visible: counting connections shows a room
of one person as a room of three, and announcing a departure per connection makes
members flicker out of the list.

### What the server needs from you

Membership comes from the `channel_data` your auth endpoint signed, so the presence
rule in your `ChannelRegistry` must return member data rather than `true`:

```php
$channels->channel('room.{room}', fn (?object $user, string $room): array|bool
    => $user === null ? false : [
        'user_id'   => (string) $user->userid,
        'user_info' => ['name' => $user->name],
    ]);
```

A `presence-` subscription that arrives with **no** member data still succeeds — it
just stays unlisted. That is deliberate: a client that only wants the channel's
events is legitimate, and inventing an identity for it would put an anonymous entry
in everybody's member list.

!!! note "A custom authorizer opts in"
    Membership is read through `Auth\PresenceAuthorizer`, which extends
    `ConnectionAuthorizer` rather than replacing a method on it. The guide has always
    invited applications to implement `ConnectionAuthorizer` themselves, and adding a
    method to it would have broken every one of those on upgrade. A deployment with a
    custom authorizer keeps working with no membership until it implements the new
    interface. The shipped `PusherAuthorizer` and `AllowAllAuthorizer` both do.

Server-side, `presenceMembers($channel)` returns `user_id → user_info` for an
application that wants to act on the live audience — the counterpart of
`subscribedChannels()`.

---

## Client events (whisper)

Browser-to-browser messages: typing indicators, cursors, transient cues. This is the
one direction SSE cannot carry at all, and the main reason to run a WebSocket.

```js
var room = PramnosEcho.join('room.lobby');

room.whisper('typing', { user: 'Ada' });
room.listenForWhisper('typing', function (payload) { showTyping(payload); });
```

!!! danger "Off by default, and enabling it is a trust decision"
    ```php
    'broadcasting' => [
        'websocket' => [
            'client_events'            => true,   // default false
            'client_events_per_second' => 10,
        ],
    ],
    ```

    Enabling this grants **every connected browser a write path onto the channel**: a
    client event is relayed to the other subscribers without the server inspecting
    it, which is what makes it cheap and also what makes it a trust decision. Nothing
    a client must not be able to assert about another user may travel this way — that
    has to go through your application.

    It stayed off by default for a reason beyond caution: until this existed a
    `client-` event was silently dropped, so no deployment has ever had this write
    path. Enabling it by default would have opened one on every installation that
    merely updated the framework.

Three guards, and each refusal is **silent** — a client event is fire-and-forget, and
answering every rejection would hand a browser a cheap way to make the server talk:

- **Private and presence channels only.** A public channel has no membership test, so
  relaying on one would be an open publish endpoint.
- **The sender must be subscribed.** The subscription is the only proof of
  authorization the daemon holds; without this check a connection could publish into
  any channel it can *name*, having never been authorized for it.
- **A per-connection budget**, 10/s by default. The fan-out is per subscriber, so the
  cost of an unthrottled sender is multiplied by the size of the room.

The sender never receives its own whisper. `broadcast:serve` reports the setting in
its startup banner either way, because silence reads the same as "enabled" to
somebody debugging a whisper that never arrives.

---

## Not echoing to the originator (`toOthers`)

An application that renders a change optimistically does not want the broadcast of
that change back — it would render it twice.

```php
use Pramnos\Broadcasting\BroadcastingManager;

$broadcasting
    ->except(BroadcastingManager::socketIdFromRequest())
    ->broadcast('chat.updates', 'message.created', $payload);
```

On the client, send the socket id with the write that causes the broadcast:

```js
fetch('/messages', {
    method:  'POST',
    headers: Object.assign({ 'Content-Type': 'application/json' }, PramnosEcho.headers()),
    body:    JSON.stringify(message)
});
```

`PramnosEcho.headers()` adds `X-Socket-ID`, and is empty before the connection is up
— the honest answer, since there is no connection to exclude yet. `socketIdFromRequest()`
also reads a `socket_id` body or query field, for a form post that cannot set a
header.

### Why the odd shape

Two constraints, both from BC:

**`except()` returns a clone and no method grew a parameter.** Adding a trailing
optional parameter to a public method is source-compatible for callers and **fatal
for a subclass that overrides it** — and this framework's own test suite subclasses
`LocalBroadcastServer` and overrides `broadcast()` with its exact three-argument
signature. The pattern demonstrably exists in the wild, so `broadcast()` kept its
signature and the server gained `broadcastExcept()` beside it.

**The exclusion travels inside the envelope.** The process that publishes and the
daemon that fans out to browsers are different processes, so anything held in PHP
memory is gone by the time the edge sees the event. A driver that supports exclusion
adds an `except` key to the `{event, payload, timestamp}` envelope it already writes;
consumers that predate the key ignore it, because envelope decoding reads by key.

A driver that does not implement `Drivers\ExcludesSocketInterface` — a third-party one
— broadcasts to everyone and the manager **logs it**. Degrading is better than
dropping the event, but the only visible symptom is one user seeing a duplicate of
something they just did, which reads as an application bug rather than a driver
capability gap.

---

## Running more than one daemon

Two daemons behind a load balancer both receive application events from the
backplane, so **ordinary broadcasts already fan out correctly** with no
configuration. What does not, without this, is anything the daemon holds in its own
memory: **presence membership and client events are per-process.** A user connected
to node A does not appear in the member list node B serves, and a whisper on A never
reaches B. Neither failure says anything — the counts are simply wrong.

```php
'broadcasting' => [
    'cluster' => [
        'enabled'  => true,
        'channel'  => '__pramnos_cluster',   // gossip channel
        'interval' => 30,                    // seconds between full-state messages
        'node_id'  => null,                  // generated when absent
    ],
],
```

```
$ php bin/pramnos broadcast:serve
  Cluster: node 4f2a9c1b7e03, gossip on app:__pramnos_cluster every 30s
           presence is eventually consistent; member webhooks are per-node
  Redis ingest: app:chat.updates, app:__pramnos_cluster
```

### How it works, and why in two mechanisms

Nodes gossip over the same backplane the application uses, in two kinds of message —
and the split is the design, not an optimisation:

**Deltas** (`join`, `leave`, `client_event`) carry one change and arrive immediately,
so a member appears on the other nodes as fast as the backplane moves a message.
They are the latency mechanism.

**Full state**, republished every `interval`, *replaces* a node's entry wholesale.
That is the correctness mechanism: whatever a node missed — restarted mid-gossip, a
dropped pub/sub message, a subscription that reconnected — it is right again within
one interval. **No individual delta has to be reliable**, which is what makes this
safe to build on pub/sub at all.

A node silent for **three intervals** is written off and its members are dropped,
with the departures announced. Otherwise a killed node leaves a room full of people
who are not there: a member list that only ever grows. Three, not one, so a single
late message cannot evict a healthy peer. A node with only empty channels sends a
heartbeat instead of a state message, or it would look dead, be pruned, and reappear
on its next join — churning the member list of every channel it does serve.

A late-arriving delta cannot resurrect a departed member: every message carries the
sending node's clock, and anything older than that node's last accepted message is
dropped.

### What to know before turning it on

!!! warning "Presence becomes eventually consistent"
    A join reaches the other nodes as fast as the backplane moves a message, which is
    fast — but the guarantee is "correct within one interval", not "correct
    instantly". If a room's count must never be transiently low, this is not the
    mechanism for it.

**Member webhooks are per-node.** Each node reports only the members whose
connections it owns, so exactly one node reports each member: no coordination, no
double-reporting. **`channel_occupied` / `channel_vacated` are also per-node** —
each reports its own occupancy — so a receiver counting them across a cluster is
counting nodes, not channels.

**A relayed client event is re-checked locally.** A peer's enforcement is not taken
on trust: a compromised or misconfigured node cannot publish onto a public channel
here, or inject an application event name.

**The pairing rule applies to gossip.** It travels on the backplane, so the
primitive that publishes it must be the one the ingest reads. `broadcast:serve` wires
both together — a `RedisDriver` (`PUBLISH`) against `RedisSubscriberSocket`
(`SUBSCRIBE`) — but if you wire a cluster yourself, mixing them gives you a cluster
where every node believes it is alone, with nothing in any log.

**Nothing changes for a single daemon.** With clustering off there is no gossip and
no per-presence-change work at all.

---

## Serving `wss://` directly

```php
'broadcasting' => [
    'websocket' => [
        'tls' => [
            'local_cert' => '/etc/ssl/realtime/fullchain.pem',
            'local_pk'   => '/etc/ssl/realtime/privkey.pem',
        ],
    ],
],
```

`broadcast:serve` then reports `Transport: wss:// (TLS terminated here)`. Without a
`local_cert` it stays on plain TCP and says so — an operator needs to know which
scheme the port speaks, and getting it wrong presents as a network fault rather than
a scheme mismatch.

!!! warning "The TLS handshake is synchronous, and this loop is single-threaded"
    A client slow to complete its handshake holds up **every other connection** for
    the duration, and a handshake is dramatically more expensive than a TCP accept.
    On a deploy, when every client reconnects at once, that cost arrives together.

    So this is right for a small deployment that would rather not run a proxy, and
    wrong for high connection churn. There, terminate TLS in front (nginx, Caddy, a
    load balancer) and leave this server on plain TCP behind it: the proxy has a
    thread pool and this does not.

    Said plainly because "the framework supports `wss://`" reads like a
    recommendation, and for a busy install it is not one.

### An unreadable certificate stops the daemon

**PHP does not load the certificate when the listener is created — it loads it per
accepted connection.** So a wrong `local_cert` path would otherwise bind
successfully, report itself healthy, and fail every single handshake, with the
operator staring at a port that is definitely open.

The paths are therefore checked at startup, and TLS configured without a
`local_cert` is refused outright:

```
TLS is configured but local_cert "/etc/ssl/realtime/fullchain.pem" is not readable;
refusing to start a wss:// listener that would fail every handshake.
```

---

## Events that describe themselves

`broadcast('private-order.' . $id, 'order.paid', [...])` repeats three decisions at
every call site — which channel, what the event is called, what the payload looks
like — and they drift. The channel name is the dangerous one: one place builds
`private-order.42`, another `private-order-42`, and the subscriber that guessed
wrong receives nothing with no error anywhere.

```php
use Pramnos\Broadcasting\BroadcastableEvent;

final class OrderPaid implements BroadcastableEvent
{
    public function __construct(private Order $order)
    {
    }

    public function broadcastOn(): array
    {
        return ['private-order.' . $this->order->id, 'ops'];
    }

    public function broadcastAs(): string
    {
        return 'order.paid';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->order->id, 'total' => $this->order->total];
    }
}

$broadcasting->event(new OrderPaid($order));
```

The payload is resolved **once** per dispatch, not once per channel —
`broadcastWith()` may be loading relations, and calling it per channel multiplies
that by the size of the audience. An event naming no channels publishes nothing
rather than failing: a conditional audience legitimately resolves to an empty list.

`except()` composes: `$broadcasting->except($socketId)->event(new OrderPaid($order))`.

!!! note "Related, but different from the `Broadcastable` trait"
    The trait broadcasts a model's own lifecycle (`created`/`updated`/`deleted`)
    automatically. `BroadcastableEvent` is for a *named* thing that happened, whose
    audience and payload are its own business. Both, neither, or one of each.

### Deferring one to a worker

Implement `QueuedBroadcastableEvent` instead — a marker, nothing else changes:

```php
final class OrderPaid implements QueuedBroadcastableEvent { /* … */ }

$broadcasting->useQueue(null, 'broadcasting');   // or inject a DelayedQueue
$broadcasting->event(new OrderPaid($order));     // pushed, not published
```

Worth it when the publish is slow or unreliable relative to the request — a managed
Pusher endpoint over HTTP, a fan-out across many channels. **Not** worth it for a
local Redis `PUBLISH`, which is faster than the queue push that would defer it.

**What is queued is the payload, not the event object.** The resolved channel list,
event name and payload are serialised; the object never is. That removes a class of
failure with it — an event holding a model cannot reach a worker after the row was
deleted, cannot rebuild a stale copy, and cannot fail to unserialise because a class
moved.

The cost is the mirror image, and it is the thing to know: **`broadcastWith()` runs
now, in the request.** An event whose payload is meant to describe the state at
delivery time cannot express that, and should not be queued.

A queued event whose queue is unreachable **throws**. It is not published inline
instead — that would turn a deliberate "get this out of the request" into the slow
request somebody was avoiding, on a path that only misbehaves under load and so
would be found in production.

---

## Testing a broadcast

`Broadcasting\Testing\FakeDriver::swap()` makes the process default record instead
of publish, so a test can assert that an action broadcast what it should:

```php
$fake = FakeDriver::swap();
$order->markPaid();
$fake->assertBroadcast('private-order.42', 'order.paid');
```

Full reference — including `assertBroadcastExcept()` for `toOthers()` — in the
[Testing guide](Pramnos_Testing_Guide.md#asserting-that-something-was-broadcast).

---

## The HTTP API

Until now the only way into the server was the backplane: an event had to be
published to Redis and ingested. That is right for the application and wrong for
everything else — a deploy script announcing a release, a service in another
language, a check asking "is anybody in room 12" all had to speak Redis and know
the envelope format, or do nothing. Occupancy in particular was unobservable from
outside the process.

```php
'broadcasting' => [
    'http_api' => ['enabled' => true],   // default false
],
```

```
POST /apps/{appId}/events            {name, channel|channels, data, socket_id?}
POST /apps/{appId}/batch_events      {batch: [...]}
GET  /apps/{appId}/channels          ?info=user_count&filter_by_prefix=presence-
GET  /apps/{appId}/channels/{name}   ?info=user_count,subscription_count
GET  /apps/{appId}/channels/{name}/users
GET  /apps/{appId}/metrics
```

It is **opt-in**, for the same reason client events are: a signed request can
broadcast to any channel, so a publish path must not appear on a port because the
framework was updated.

**It shares the WebSocket port.** A second listener would need its own address, its
own firewall rule and its own supervisor entry to carry requests the process is
already able to answer — and it would have to reach into the same in-memory
occupancy state anyway. Requests that are not an upgrade and not under `/apps/` get
the same `400` they always did.

### Signing

Pusher's REST scheme, unchanged, so **every Pusher server SDK already speaks it**:
`auth_key`, `auth_timestamp`, `auth_version`, `body_md5`, `auth_signature` as query
parameters. A bespoke scheme would mean a bespoke client in every language that
wants in, which is the problem this exists to remove.

The signature covers the **method**, the **path** and every query parameter except
itself, sorted. `body_md5` is what binds the body to it, and a request that has a
body without one is **refused** rather than tolerated — a signature over an unbound
body authenticates who sent the request and says nothing about what they sent.

Requests are rejected outside a **ten-minute** window (`ServerApi::MAX_CLOCK_SKEW`).
That is a replay window rather than a nonce store: a daemon has nowhere durable to
remember nonces, and a shorter window turns ordinary clock drift into intermittent
401s that look like a signing bug.

An unknown key, a stale timestamp, a wrong signature, an unbound body and a key
acting on another app's path all return the same `401`.

### Two answers that look similar and are not

| | |
|---|---|
| `subscription_count` | **connections** |
| `user_count` | **distinct users**, presence channels only |

`user_count` is refused on a non-presence channel rather than answered with the
subscription count: a caller reading it would believe it had deduplicated people,
and it would not have.

A batch is validated in full before anything is published. A batch that failed
half-way would have delivered some of its events and reported an error, leaving the
caller unable to retry safely.

### Metrics

`GET /apps/{appId}/metrics` returns levels and counters together:

| | |
|---|---|
| `connections_current`, `channels_occupied`, `subscriptions_current`, `presence_channels` | levels — what is true now |
| `connections_total`, `messages_sent`, `client_events_relayed`, `client_events_refused`, `webhook_events_queued` | counters, since start |
| `uptime_seconds` | so a counter can be read as a rate |

Both kinds, because neither is enough alone. "Twelve connected" says nothing about
whether four thousand have come and gone in the last minute; a counter with no
uptime says nothing about how fast.

**`client_events_refused` is the one to watch.** Client-event refusals are silent on
the wire by design — answering each one would hand a browser a cheap way to make the
server talk — so without this counter a client that has been throttled for an hour is
indistinguishable from one that is simply quiet. Rising refusals with the feature
*off* is also worth knowing: something is trying to whisper and nobody enabled it.

`messages_sent` counts **deliveries, not calls**: one broadcast to a room of three is
three. That is the number that matters when the question is where the process is
spending its time — and an excluded connection is not counted, because nothing was
sent to it.

Metrics need the same signature as everything else. A connection count is a useful
thing for an outsider to know about a server.

Also available in-process as `$server->stats()`, for an `onTick` callback that wants
to log or export them.

---

## Webhooks

How an application learns things it cannot otherwise see: that a room is empty and
its state can be torn down, that a user's last connection went away, that somebody
is typing. The only previous route was polling from an `onTick` callback, which
counts channels rather than observing transitions and fires on a timer rather than
on the event.

```php
'broadcasting' => [
    'webhooks' => [
        'url'   => 'https://your-app.test/hooks/realtime',
        'queue' => 'broadcasting',
    ],
],
```

Five events, in Pusher's shape:

| Event | When | Carries |
|---|---|---|
| `channel_occupied` | first subscriber | `channel` |
| `channel_vacated` | last unsubscriber | `channel` |
| `member_added` | a user's **first** connection to a presence channel | `channel`, `user_id` |
| `member_removed` | a user's **last** connection goes | `channel`, `user_id` |
| `client_event` | a whisper was relayed | `channel`, `event`, `data`, `socket_id` |

The member events follow the same people-not-sockets rule as the wire
announcements, and that matters more here: an application tearing down state on
`member_removed` must not be told somebody left because they closed one of two tabs.
A refused client event is **not** reported — reporting one would claim a whisper
happened when nothing was relayed, and a rate-limited sender would generate webhook
traffic exactly when the point was to stop generating traffic.

### The daemon does not make the HTTP call

`WebhookDispatcherInterface` exists for one reason: the server is a single-threaded
`stream_select()` loop, and **an outbound HTTP request inside it stalls every
connected client for the duration of that request**. A slow webhook endpoint would
present as a realtime outage and an unreachable one as a hang.

The shipped `QueueWebhookDispatcher` pushes onto a Redis queue and returns; a worker
delivers. The job payload carries the URL, the signed body and the headers, so the
worker does no signing and holds no secret. Retry policy, backoff and dead-lettering
are a deployment's opinions and none of them belong in a fan-out loop.

If you write your own dispatcher, it must not block. One that calls `curl`
synchronously works in development and takes the server down in production.

### Verifying a delivery

`Webhooks\WebhookSigner::verify()` takes the **raw** body:

```php
$signer = new WebhookSigner($app);

if (!$signer->verify($rawBody, $_SERVER['HTTP_X_PUSHER_SIGNATURE'] ?? '')) {
    // reject
}
```

Re-encoding a decoded payload before checking it changes key order and escaping, so
a delivery nobody tampered with stops verifying — the same canonicalisation trap as
presence `channel_data`, which is why `verify()` refuses to take an array.

`broadcast:serve` refuses to send webhooks when no app secret is available to sign
them, and says so. Unsigned webhooks are worse than none: a receiver cannot tell
them from anybody else's POST, so it either trusts every caller or rejects yours.

!!! warning "Channels are process-global, not per-app"
    Multi-app support resolves **credentials** per connection — the right secret for
    the right key. It does not partition the **channel namespace**: two apps
    connected to the same daemon share `presence-room`, and a webhook batch is signed
    with one app's secret because the server does not track which app a channel
    belongs to.

    For separate tenants that must not see each other's channels, run a daemon per
    tenant, or namespace the channel names themselves (`tenant-42-room`). Stated here
    because it is the kind of limit that is invisible until two tenants pick the same
    room name.

    This is about **apps**, not nodes. Several daemons serving the *same* app are
    supported — see [Running more than one daemon](#running-more-than-one-daemon).

---

## Consuming somebody else's WebSocket

Everything above is the framework talking: `LocalBroadcastServer` **is** a WebSocket
server, `PusherDriver` **publishes** to one. The other direction — reading a feed
somebody else pushes — is `\Pramnos\Http\WebSocketClient`.

It is **transport only**. The handshake, masking, the three payload-length forms,
fragment reassembly and ping/pong are handled; the protocol on top is not. Pusher's
`pusher:subscribe` exchange, its `activity_timeout`, its channel auth belong to
whoever is speaking Pusher, exactly as `Pramnos\Http\Client` knows HTTP and not the
APIs called over it. A `PusherClient` in the framework would be a guess about one
provider; a WebSocket client is what every provider needs.

### The caller keeps its own loop

This is the property that decides the API. A worker multiplexing several SSE reads,
one WebSocket and the `.stop` sentinel every daemon here must honour cannot use a
client that owns the loop or blocks in `read()`. So `WebSocketClient` is shaped like
`RedisSubscriberSocket`: it hands you its stream and you select on it.

```php
use Pramnos\Http\WebSocketClient;

$socket = new WebSocketClient('wss://reverb.example.test/app/api-key?protocol=7');
$socket->connect();                     // handshake; throws on non-101 or bad Accept

while ($running) {
    $read  = [$socket->stream(), ...$otherStreams];
    $write = $except = [];

    stream_select($read, $write, $except, 1);

    foreach ($socket->read() as $message) {   // whole messages, [] when none
        handle(json_decode($message, true));
    }

    if (!$socket->isConnected()) {
        break;                          // peer closed, or EOF
    }
}
```

Only `connect()` blocks, and only for the handshake.

### What it guarantees

| | |
|---|---|
| `stream()` | the resource for `stream_select()`; null before connect and after close |
| `read()` | complete messages only — never a fragment, never a partial frame |
| ping | answered with a matching pong and **not** surfaced as a message |
| close | surfaces as `isConnected() === false`, not as a message |
| client frames | always masked, per RFC 6455 §5.3 — not an option |
| `permessage-deflate` | declined by never offering it |
| `wss://` | TLS with SNI and the framework's verification defaults |
| read limit | per-frame **and** per-reassembled-message ceiling |

Both ceilings are needed, and the second is the one that is easy to miss: unlimited
fragments that never set FIN grow one buffer without any single frame looking
suspicious.

```php
$socket = new WebSocketClient(
    url:        'wss://example.test/socket',
    headers:    ['Origin' => 'https://app.test'],   // an upgrade request gets one chance
    timeout:    10.0,
    maxMessage: 1024 * 1024,
);
```

### Handshake failures are refusals, not warnings

`connect()` throws rather than returning a degraded connection, on each of:

- a response that is not `101 Switching Protocols`
- a `Sec-WebSocket-Accept` that does not match the key sent — **the check that
  separates a WebSocket server from anything that can be talked into returning 101**
- a server negotiating an extension that was never offered, since receiving
  compressed frames with no inflate path is silent corruption rather than a visible
  failure

A framing violation mid-stream throws too, and closes the connection first: once
frame boundaries are lost every later frame is misread, so continuing would feed
arbitrary slices of the stream to the application.

### Framing is shared with the server

`Pramnos\Http\WebSocket\FrameCodec` and `MessageAssembler` are used by both this
client and `LocalBroadcastServer`. One implementation of the length forms rather than
one per direction — the single asymmetry RFC 6455 imposes is *who* masks (a client
must, a server must not), which is one boolean.

That extraction fixed two things in the server on the way through. It **never read
the FIN bit**, so a fragmented text message reached the Pusher handler as separate
halves, each invalid JSON, from a client that had done nothing wrong. And completing
the handshake cleared the whole read buffer, discarding any frame a client had
pipelined into the same segment as its request — a loss that depended on how the
kernel happened to split two writes.

### What this makes possible next

`PusherDriver` publishes over HTTP and is the one driver in the table above that
cannot be *subscribed* to. With a client, a Pusher/Reverb implementation of
`SubscribableDriverInterface` becomes possible, so an application on a managed Reverb
server could receive on the backplane it already publishes to.

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
