---
date: 2026-08-21
categories: [Changelog]
---

# A throttled client that looked quiet

Client-event refusals are silent on the wire by design — answering each one would hand a browser
a cheap way to make the server talk. The consequence nobody had accounted for: a client throttled
for an hour is indistinguishable from one that is simply quiet.

<!-- more -->

## Added

Counters on the WebSocket server, exposed as `GET /apps/{appId}/metrics` and in-process as
`$server->stats()`:

| | |
|---|---|
| levels | `connections_current`, `channels_occupied`, `subscriptions_current`, `presence_channels` |
| counters | `connections_total`, `messages_sent`, `client_events_relayed`, `client_events_refused`, `webhook_events_queued` |
| | `uptime_seconds`, so a counter can be read as a rate |

Both kinds, because neither is enough alone. "Twelve connected" says nothing about whether four
thousand have come and gone in the last minute, and a counter with no uptime says nothing about
how fast.

`client_events_refused` is the metric this was written for, and it is worth reading in both
directions: rising alongside `client_events_relayed` means the rate limit is doing its job, and
rising while the feature is **off** means something is trying to whisper and nobody enabled it.

`messages_sent` counts **deliveries, not calls** — one broadcast to a room of three is three.
That is the number that matters when the question is where the process is spending its time. An
excluded connection is not counted, because nothing was sent to it.

Metrics require the same signature as every other API call. A connection count is a useful thing
for an outsider to know about a server.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Metrics** under The HTTP API, with a `use_cases:` entry.
