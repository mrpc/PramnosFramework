---
date: 2026-08-25
categories: [Changelog]
---

# A socket that received nothing

`broadcast:serve` is the process that turns a published event into a frame in a
browser, and every application had to remember to supervise it. Now the framework
does, when the configuration says WebSocket.

<!-- more -->

## Changed

- **The orchestrator supervises `broadcast:serve`** when
  `broadcasting.transport` is `websocket`. An application declaring its own entry
  keeps it; `includeBroadcastServer()` turns the framework's off.
- **`broadcast:serve` warns when `--channels` cannot deliver anything** — a Redis
  ingest against a `log`, `pusher` or `null` backplane.
- The command no longer describes itself as "local-dev".

## The same accident, in a second place

`DaemonOrchestrator` already declares the framework's schedule worker, and its
docblock records why: an installation supervising three application daemons and no
scheduler ran none of the framework's periodic work, and every report reading a
drained table showed "no data" for ever.

The WebSocket daemon was in exactly that position. Turn realtime on, forget the
daemon, and every subscription is a healthy socket that never receives anything —
the publish succeeded, the channel exists, the client connected. There is no error
to find because nothing failed.

Only when the transport is `websocket`. An application on SSE needs no daemon, and
one it never asked for would sit failing to bind a port and reporting itself
unhealthy for ever, which is worse than useless in a dashboard.

## The ingest mismatch that is left

The pairing rule — `RedisDriver` reads with `SUBSCRIBE`, `RedisStreamDriver` with
`XREAD` — is already enforced by deriving the ingest from the driver rather than
letting them be chosen separately.

What that cannot catch is an application publishing somewhere else entirely.
`--channels` with a `log` backplane opens a healthy subscription to a Redis nobody
writes to, and the symptom is identical to both the mismatch and to a working daemon
with no traffic. It is now said at startup, where somebody is looking, rather than
discovered from an empty browser hours later.

## A docblock that was actively misleading

The command described itself as a *"Local-dev WebSocket broadcasting server"*. It
serves `wss://` directly, clusters across nodes, authorises private and presence
channels against AuthServer app keys, dispatches webhooks, and wires the
orchestrator's cooperative stop.

Wording like that is how somebody concludes the framework does not ship a production
WebSocket server and stands a second one up beside the working one. That has already
happened once in this project with a debug panel, which is why the rule about guides
describing current state exists at all.

## Documentation

- [Workers & Daemons Guide](../../Pramnos_Workers_And_Daemons_Guide.md) — a new
  section on the supervised daemon and how to take it over.
