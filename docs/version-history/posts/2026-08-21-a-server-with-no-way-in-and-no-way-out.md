---
date: 2026-08-21
categories: [Changelog]
---

# A server with no way in, and no way out

The only route into the WebSocket server was the backplane: publish to Redis, be ingested. The
only route out was a client's own socket. So a deploy script announcing a release, a service in
another language, and a check asking "is anybody in room 12" all had to speak Redis and know the
envelope format — and nothing could learn that a room had just become empty.

<!-- more -->

## Added

### The HTTP API

`broadcasting.http_api.enabled` (default false) serves Pusher's REST surface on the WebSocket
port:

```
POST /apps/{appId}/events            {name, channel|channels, data, socket_id?}
POST /apps/{appId}/batch_events      {batch: [...]}
GET  /apps/{appId}/channels          ?info=user_count&filter_by_prefix=…
GET  /apps/{appId}/channels/{name}   ?info=user_count,subscription_count
GET  /apps/{appId}/channels/{name}/users
```

Occupancy in particular was previously unobservable from outside the process — `subscribedChannels()`
is in-memory and the daemon had no way to answer a question about it.

**Opt-in**, for the same reason client events are: a signed request can broadcast to any channel,
so a publish path must not appear on a port because the framework was updated. Requests that are
neither an upgrade nor under `/apps/` get the same 400 they always did.

**The port is shared on purpose.** A second listener would need its own address, its own firewall
rule and its own supervisor entry to carry requests the process is already able to answer — and it
would have to reach into the same in-memory occupancy state anyway.

Signing is Pusher's REST scheme unchanged, so every Pusher server SDK already speaks it. The
signature covers the method, the path and every query parameter except itself, sorted; `body_md5`
binds the body. **A request with a body and no `body_md5` is refused** rather than tolerated: a
signature over an unbound body authenticates who sent the request and says nothing about what
they sent. Requests outside a ten-minute window are rejected — a replay window rather than a nonce
store, because a daemon has nowhere durable to remember nonces and a shorter window turns
ordinary clock drift into intermittent 401s that look like a signing bug.

Unknown key, stale timestamp, wrong signature, unbound body, and a valid key acting on another
app's path all return the same 401. The last one matters: without that check the signature would
verify and the *path* would choose the target, so any tenant could publish into any other's
channels.

`user_count` is refused on a non-presence channel rather than answered with the subscription
count. A caller reading it would believe it had deduplicated people, and it would have counted
connections. A batch is validated in full before anything is published, because a batch that
failed half-way has delivered some of its events and reported an error, which leaves the caller
unable to retry safely.

### Webhooks

Five events in Pusher's shape — `channel_occupied`, `channel_vacated`, `member_added`,
`member_removed`, `client_event` — configured with `broadcasting.webhooks.url`.

The member events follow the same people-not-sockets rule as the wire announcements, and it
matters more here: an application tearing down state on `member_removed` must not be told
somebody left because they closed one of two tabs. A **refused** client event is not reported —
that would claim a whisper happened when nothing was relayed, and a rate-limited sender would
generate webhook traffic exactly when the point was to stop generating traffic.

**The daemon does not make the HTTP call, and `WebhookDispatcherInterface` exists for that one
reason.** The server is a single-threaded `stream_select()` loop: an outbound request inside it
stalls every connected client until that request returns, so a slow endpoint would present as a
realtime outage and an unreachable one as a hang. `QueueWebhookDispatcher` pushes onto a Redis
queue and returns; a worker delivers. The job payload carries the URL, the signed body and the
headers, so the worker does no signing and holds no secret.

Events are batched per loop iteration, because one action produces several — a client
disconnecting from three channels vacates up to three. The buffer is cleared *before* dispatching,
so one unreachable endpoint cannot turn into an unbounded resend loop growing by every subsequent
event.

`broadcast:serve` refuses to send webhooks when no app secret is available to sign them, and says
so. Unsigned webhooks are worse than none: a receiver cannot tell them from anybody else's POST,
so it either trusts every caller or rejects yours.

## Known limit, stated rather than discovered

**Channels are process-global, not per-app.** Multi-app support resolves *credentials* per
connection; it does not partition the *channel namespace*. Two apps on the same daemon share
`presence-room`, and a webhook batch is signed with one app's secret because the server does not
track which app a channel belongs to. For tenants that must not see each other's channels, run a
daemon per tenant or namespace the names. Written down because it is the kind of limit that is
invisible until two tenants pick the same room name.

## Documentation

`Pramnos_Realtime_Guide.md` gains **The HTTP API** and **Webhooks**, with three `use_cases:`
entries and the multi-tenancy caveat above.
