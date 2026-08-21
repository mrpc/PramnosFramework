---
date: 2026-08-21
categories: [Changelog]
---

# Two daemons that each thought they were alone

Two WebSocket daemons behind a load balancer both received application events from the
backplane, so ordinary broadcasts fanned out correctly. Everything the daemon held in its own
memory did not: a user connected to node A never appeared in the member list node B served, and
a whisper on A never reached B. Neither failure said anything — the counts were simply wrong.

<!-- more -->

## Added

Clustering, via `broadcasting.cluster.enabled`. Nodes gossip presence membership and relay client
events over the same backplane the application uses.

```php
'cluster' => ['enabled' => true, 'interval' => 30],
```

### Two mechanisms, and the split is the design

**Deltas** (`join`, `leave`, `client_event`) carry one change and arrive immediately, so a member
appears on the other nodes as fast as the backplane moves a message. That is the latency
mechanism.

**Full state**, republished every interval, *replaces* a node's entry wholesale. That is the
correctness mechanism — whatever a node missed, it is right again within one interval. **No
individual delta has to be reliable**, which is what makes this safe to build on pub/sub at all,
and it removes the whole category of ordering bugs with it: a state message is not applied
relative to anything.

A node silent for **three** intervals is written off and its members dropped, with the departures
announced. Otherwise a killed node leaves a room full of people who are not there — a member list
that only ever grows. Three rather than one, so a single late message cannot evict a healthy peer.
A node whose channels are all empty sends a heartbeat instead of a state message, or it would look
dead, be pruned, and reappear on its next join, churning the member list of every channel it does
serve.

A late-arriving delta cannot resurrect a departed member: every message carries the sending node's
clock, and anything older than that node's last accepted message is dropped. That is the only
ordering hazard the design has, and there is a test for it.

### The rules that carried over unchanged

**People, not sockets — one level up.** A user with a tab on two nodes is one member, and only
leaves when the last connection anywhere goes. Getting this wrong shows one person as two members
and then removes them while they are still connected somewhere.

**A relayed client event is re-checked locally.** A peer's enforcement is not taken on trust: a
compromised or misconfigured node cannot publish onto a public channel here, or inject an
application event name.

**The pairing rule applies to gossip.** It travels on the backplane, so the primitive that
publishes must be the one the ingest reads. `broadcast:serve` wires both together — `RedisDriver`
(`PUBLISH`) against `RedisSubscriberSocket` (`SUBSCRIBE`) — because mixing them gives a cluster
where every node believes it is alone, with nothing in any log. That failure has already cost this
project twice at the application layer; it is the same one.

## What to know before turning it on

**Presence becomes eventually consistent.** A join propagates as fast as the backplane moves a
message, but the guarantee is "correct within one interval", not "correct instantly". If a room's
count must never be transiently low, this is not the mechanism for it.

**Member webhooks stay per-node** — each node reports only the members whose connections it owns,
so exactly one node reports each member and no coordination is needed.
`channel_occupied`/`channel_vacated` are per-node too, so a receiver counting them across a cluster
is counting nodes rather than channels.

**Nothing changes for a single daemon.** With clustering off there is no gossip and no
per-presence-change work at all.

Gossip is never fanned out to clients — it arrives on the same backplane as application events, so
the channel check is the only thing separating them, and there is a test asserting not one frame
reaches a subscriber.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Running more than one daemon**, and the earlier
"channels are process-global" warning now distinguishes apps from nodes — several daemons serving
the same app are supported; several *apps* on one daemon still share a channel namespace.
