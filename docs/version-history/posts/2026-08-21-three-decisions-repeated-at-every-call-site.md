---
date: 2026-08-21
categories: [Changelog]
---

# Three decisions repeated at every call site

`broadcast('private-order.' . $id, 'order.paid', [...])` restates which channel, what the event
is called and what the payload looks like, everywhere it is written. They drift — and the
channel name is the one that drifts silently.

<!-- more -->

## Added

`BroadcastableEvent`: an event that knows its own channels, name and payload.

```php
final class OrderPaid implements BroadcastableEvent
{
    public function broadcastOn(): array   { return ['private-order.' . $this->order->id, 'ops']; }
    public function broadcastAs(): string  { return 'order.paid'; }
    public function broadcastWith(): array { return ['id' => $this->order->id]; }
}

$broadcasting->event(new OrderPaid($order));
```

The failure this prevents is worth naming: one call site builds `private-order.42` and another
`private-order-42`, and the subscriber that guessed wrong receives nothing, with no error
anywhere. Naming the three things once, beside the data they describe, is the whole point.

The payload is resolved **once** per dispatch rather than once per channel — `broadcastWith()`
may be loading relations, and calling it per channel multiplies that by the size of the audience.
An event naming no channels publishes nothing rather than failing, because a conditional audience
legitimately resolves to an empty list. `except()` composes with it.

Distinct from the `Broadcastable` trait, which broadcasts a model's own lifecycle. This is for a
named thing that happened, whose audience and payload are its own business.

### Deferring one

`QueuedBroadcastableEvent` is a marker: implementing it sends the event to the queue instead of
publishing inline. Worth it for a slow or unreliable publish — a managed Pusher endpoint over
HTTP — and **not** worth it for a local Redis `PUBLISH`, which is faster than the queue push that
would defer it.

**What is queued is the payload, not the event object.** The resolved channels, name and payload
are serialised and the object never is. That is a deliberate difference from frameworks that
serialise the event and rebuild it in the worker, and it removes a class of failure with it: an
event holding a model cannot reach a worker after the row was deleted, cannot rebuild a stale
copy of it, and cannot fail to unserialise because a class moved.

The cost is the mirror image and is the thing to know: `broadcastWith()` runs **now**, in the
request. An event whose payload is meant to describe the state at delivery time cannot express
that, and should not be queued. A test asserts the job payload contains no objects at all, so
this stays true.

A queued event whose queue is unreachable **throws** rather than falling back to an inline
publish. Falling back would turn a deliberate "get this out of the request" into the slow request
somebody was avoiding — on a path that only misbehaves under load, and so would be discovered in
production.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Events that describe themselves**, with a `use_cases:` entry.
