---
date: 2026-08-21
categories: [Changelog]
---

# A typing cue from a minute ago

Clustering relays client events between nodes. It relayed them regardless of age — which is the
one thing a transient cue cannot survive, and a consuming application had already documented
exactly this failure on its SSE path.

<!-- more -->

## Fixed

A client event relayed from another node is now dropped if it is older than 30 seconds
(`LocalBroadcastServer::relayedClientEventMaxAge()`; zero disables the check).

A cue — somebody is typing, a cursor moved — carries no timestamp of its own, and a receiver sets
state from *arrival* time. So a stale one does not merely arrive late: it asserts something false,
that a person who stopped typing minutes ago is typing now.

This cannot happen while gossip is live pub/sub, which is why it shipped unnoticed. It is what
happens the day an operator persists ingest cursors and a node replays a backlog after a deploy —
stale "someone is typing…" for every client on that node at once. The identical case on the SSE
path was found and filtered at 30 seconds by a consuming application; this is the same judgement
on the cluster path, made where the timestamp is available.

**Presence deltas are deliberately not age-filtered**, and the asymmetry is the point. A delta's
meaning is a state that either still holds or has been superseded, and a message older than the
sending node's last accepted one is already refused by `ClusterState`. A cue's meaning depends on
when it was published. Filtering deltas by age would drop a legitimate membership change from a
node whose clock is behind.

Also fixed while adding the setter: `relayedClientEventMaxAge(0)` is documented as disabling the
check, and the first implementation read it as a zero-length window — dropping every relayed cue
instead, the exact opposite. The test caught it.

## Documentation

`Pramnos_Realtime_Guide.md`, under Running more than one daemon, now states the staleness window
and why presence deltas are exempt from it.
