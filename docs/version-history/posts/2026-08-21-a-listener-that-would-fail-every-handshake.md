---
date: 2026-08-21
categories: [Changelog]
---

# A listener that would fail every handshake

The built-in WebSocket server could only speak plain TCP; `wss://` needed a proxy in front. It
can now terminate TLS itself — and while wiring that up, PHP turned out not to load the
certificate when the listener is created.

<!-- more -->

## Added

`LocalBroadcastServer::useTls()`, wired from `broadcasting.websocket.tls`:

```php
'websocket' => ['tls' => [
    'local_cert' => '/etc/ssl/realtime/fullchain.pem',
    'local_pk'   => '/etc/ssl/realtime/privkey.pem',
]],
```

A setter rather than a third parameter on `run()`. The parameter would be source-compatible for
callers and fatal for any subclass overriding `run()`, and this codebase subclasses this class
in its own tests.

`broadcast:serve` reports which scheme the port speaks either way. An operator needs to know,
and getting it wrong presents as a network fault rather than a scheme mismatch.

**The tradeoff, stated rather than implied.** The TLS handshake happens synchronously in
`accept()`, and this is a single-threaded loop — so a client slow to complete its handshake
holds up every other connection, and a handshake costs dramatically more than a TCP accept. On
a deploy, when every client reconnects at once, that arrives together. Right for a small
deployment that would rather not run a proxy; wrong for high connection churn, where the proxy
has a thread pool and this does not. "The framework supports wss://" reads like a
recommendation, and for a busy install it is not one.

## Fixed

**PHP does not load the certificate when the listener is created — it loads it per accepted
connection.** A test written on the assumption that a bad path fails the bind found the
opposite: the bind succeeded.

That is the worse failure of the two. A wrong `local_cert` would bind, report itself healthy,
and then fail every single handshake, with the operator looking at a port that is definitely
open and clients reporting a connection error. So the paths are now checked at startup, and TLS
configured without a `local_cert` at all is refused:

```
TLS is configured but local_cert "…/fullchain.pem" is not readable;
refusing to start a wss:// listener that would fail every handshake.
```

Also extracts `createServerSocket()` from `run()`. `run()` blocks in its event loop, so there was
no way to assert what had been bound — the first version of the test for this bound port 1,
succeeded because the container runs as root, and hung the suite for ten minutes.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Serving `wss://` directly**, with the synchronous-handshake
warning and the certificate check, and a `use_cases:` entry.
