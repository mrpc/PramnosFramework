---
date: 2026-07-27
categories:
  - Changelog
  - Features
tags:
  - broadcasting
  - websockets
  - sse
  - redis
  - realtime
---

# Realtime: pluggable SSE + WebSocket backplane

The broadcasting subsystem grows from publish-only into a full realtime stack.
An application now chooses **how** live events reach the browser — Server-Sent
Events on shared hosting, the built-in WebSocket server on a custom box, or
Pusher/Reverb — by flipping one config key, with SSE and WebSocket sharing the
same Redis (or database) backplane underneath.

<!-- more -->

See the new **[Realtime Guide](../../Pramnos_Realtime_Guide.md)** for the full
walkthrough with examples.

## Added

**Subscribable backplane.** `SubscribableDriverInterface` adds
`subscribe(channels, onEvent, options)` on top of `DriverInterface`, with a
symmetric `{event, payload, timestamp}` envelope and a legacy raw-message
fallback for incremental migration. `SubscriptionOptions` carries
transport-agnostic loop tuning (`readTimeout`, `maxRuntime`, `onIdle`,
`onError`).

- **`RedisDriver`** — `\Redis::publish` / `subscribe` with read-timeout idle
  ticks, reconnect and channel prefixing.
- **`DatabaseDriver`** (+ `BroadcastEventStore` / `DatabaseEventStore`) — a
  polling backplane for hosts without Redis; ships a `broadcast_events` migration.
- `BroadcastingServiceProvider` now registers `redis` / `database` / `pusher`
  from `app.php['broadcasting']`.

**SSE transport.** `Pramnos\Http\StreamedResponse` (callback body, incremental
flush) and `Pramnos\Http\Sse\SseWriter` (event/comment/ping/retry + a `stream()`
pump that forwards a backplane into the response, pings while idle, and emits a
reconnect event before a Cloudflare-style edge timeout).

**WebSocket hardening.** `LocalBroadcastServer` gains a pluggable
`ConnectionAuthorizer` (`PusherAuthorizer` enforces the app key + Pusher HMAC
signatures on `private-`/`presence-` channels; `AllowAllAuthorizer` is the dev
default) and a non-blocking Redis ingest (`RedisSubscriberSocket`, raw RESP over
`stream_select` — no blocking client, no fork). `broadcast:serve` wires both from
config via a new `--channels` option.

**Transport selection.** `RealtimeConfig::forClient()` produces a client-safe
config per transport (never leaking `app_secret`), and `pramnos-realtime.js`
connects the right way — `EventSource` for `sse`, `pramnos-echo.js` for
`websocket` / `pusher`.

## Notes

`transport` (client edge) and `default` (backplane driver) are independent, so
e.g. `default: redis` + `transport: sse` publishes to Redis and serves browsers
over SSE. Kafka remains a one-class seam: implement `SubscribableDriverInterface`.
