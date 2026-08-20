---
date: 2026-08-21
categories: [Changelog]
---

# Presence channels that knew nobody

`presence-` channels authenticated correctly — signature verified, `channel_data` and all —
and then dropped the member data on the floor. `subscription_succeeded` carried `'{}'`, no
`member_added` was ever sent, and there was no member list anywhere. `here()`, `joining()` and
`leaving()` could not have worked however they were written.

<!-- more -->

## Added

### Presence membership

The server now keeps a member list per channel, sends it to each new subscriber, and announces
arrivals and departures.

**Membership counts people, not sockets**, and that distinction is the whole correctness of the
feature. Membership is keyed by connection and reported by user:

| | |
|---|---|
| One user, three tabs | one member, count of 1 |
| Their second tab connects | no `member_added` — they were already here |
| Their last tab closes | `member_removed`, once |

Getting it wrong in either direction is visible: counting connections shows a room of one
person as a room of three, and announcing a departure per connection makes members flicker out
of the list.

Member ids are strings on the wire. A test caught the reason: PHP casts a numeric string array
key to an integer, so building the id list with `array_keys()` turned `"7"` into `7` and
serialised `[7]` instead of `["7"]`. Clients compare member ids as strings — pusher-js does — so
that reads as a member who is in the room but is never recognised as anybody, including as
yourself.

A `presence-` subscription that arrives with no member data still succeeds and stays unlisted. A
client that only wants the channel's events is legitimate, and inventing an identity for it
would put an anonymous entry in everybody's list.

Membership is read through the new **`Auth\PresenceAuthorizer`**, which *extends*
`ConnectionAuthorizer` rather than adding a method to it. The Realtime guide has always invited
applications to implement `ConnectionAuthorizer` themselves, so a new method on it would have
broken every one of those on upgrade, for a capability most do not need. A deployment with a
custom authorizer keeps working, with no membership, until it opts in. `PusherAuthorizer` and
`AllowAllAuthorizer` both implement it — the permissive one included, or presence would not
work in local development and the developer would be debugging the default rather than their
code.

### Client events (whisper)

`handleTextMessage()` switched on exactly three event names, so a `client-typing` from a
browser fell into the void. That is the whole category of typing indicators, cursors and
transient cues — the one direction SSE cannot carry, and the main reason to run a WebSocket.

**Off by default** (`broadcasting.websocket.client_events`), and that is a security decision
rather than caution. Until now no deployment has ever had a client-to-client write path through
this server; enabling it by default would open one on every installation that merely updated
the framework.

Three guards, each refusing silently — a client event is fire-and-forget, and answering every
rejection hands a browser a cheap way to make the server talk:

- private and presence channels only, because a public channel has no membership test and
  relaying on one is an open publish endpoint;
- the sender must be subscribed, because the subscription is the only proof of authorization
  the daemon holds — otherwise a connection could publish into any channel it can *name*;
- a per-connection budget, 10/s by default, because the fan-out is per subscriber and an
  unthrottled sender costs the size of the room.

`broadcast:serve` reports the setting in its banner either way. Silence reads the same as
"enabled" to somebody debugging a whisper that never arrives.

### `toOthers()`

The socket id was issued at handshake and never came back, so there was no way to say
"everyone but the originator" and an optimistic UI rendered its own change twice.

```php
$broadcasting->except(BroadcastingManager::socketIdFromRequest())
    ->broadcast('chat.updates', 'message.created', $payload);
```

Two BC constraints shaped it:

**No method grew a parameter.** A trailing optional parameter is source-compatible for callers
and **fatal for a subclass that overrides the method** — and this framework's own test suite
subclasses `LocalBroadcastServer` and overrides `broadcast()` with its exact three-argument
signature. So `broadcast()` kept its signature, the server gained `broadcastExcept()` beside it,
and the manager gained `except()`, which returns a clone. The clone is not fastidiousness
either: the manager is a container singleton, so mutating it would leak one request's exclusion
into every later broadcast in the process.

**The exclusion travels in the envelope.** The publishing process and the daemon that fans out
are not the same one, so anything held in PHP memory is gone by the time the edge sees the
event. Drivers that support it add an `except` key to the `{event, payload, timestamp}` envelope
they already write; consumers predating the key ignore it, because envelope decoding reads by
key. `DriverInterface` is untouched — the guide invites third-party drivers, and a fourth
parameter there would fatal all of them. One that cannot exclude broadcasts to everyone and the
manager **logs it**, because the only visible symptom is a duplicated item in one user's UI.

### The JavaScript client

`pramnos-echo.js` gains `join()`/`presence()` returning a presence channel with
`here()`/`joining()`/`leaving()`, `whisper()`/`listenForWhisper()`/`stopListeningForWhisper()`,
and `socketId()`/`headers()` for `toOthers()`. Members are normalised to `{ id, info }` with a
string id and an always-present `info`, so callers need no guards. `headers()` is empty before
the connection is up — the honest answer, since there is no connection to exclude yet.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Presence channels**, **Client events (whisper)** and **Not
echoing to the originator**, with three `use_cases:` entries.
