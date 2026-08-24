---
use_cases:
  - Broadcasting model changes to a browser without polling
  - Replacing a Hasura subscription with the framework's own WebSocket
  - Writing an audit log of every change a model makes
  - Reacting in application code when any model is saved or deleted
---

# Pramnos Change Feed Guide

Every model save and delete can announce itself. One local event carries what
changed; listeners turn that into whatever the application needs — a broadcast on
a channel, a row in a changelog, a message on a queue.

```php
class Device extends \Pramnos\Application\Model
{
    protected bool $emitChanges = true;
}
```

That is the whole opt-in. Nothing else about the model changes, and a model that
does not set it emits nothing at all.

!!! note "Local first, sockets second"
    The feed is a **local** event bus. It works with no broadcasting driver, no
    Redis and no WebSocket daemon — which is why it is `$emitChanges` rather than
    `$broadcastChanges`. Broadcasting is one listener among several.

---

## The event

Everything is delivered under a single event name, carrying one value object:

```php
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;

Event::listen(ChangeFeed::EVENT, function (ModelChange $change) {
    if ($change->entity !== 'wcm-device') {
        return;
    }

    if ($change->has('status')) {
        // …
    }
});
```

One name rather than `model.<entity>.<op>`. Two naming schemes means two
registrations to keep in step, and one of them gets forgotten; listeners switch on
`$change->entity` and `$change->op`, which they already hold.

### What a `ModelChange` carries

| Property | Type | |
|---|---|---|
| `entity` | `string` | The application's name for the thing, e.g. `wcm-device` |
| `key` | `string\|int\|null` | Primary key value; `null` when a delete had none |
| `op` | `string` | `ModelChange::CREATED` / `UPDATED` / `DELETED` |
| `data` | `array` | The record as `getData()` saw it. **In-process only** |
| `changes` | `array` | `field => ['old' => …, 'new' => …]`, ignore-list already removed |
| `channels` | `list<string>` | Resolved from the model at emit time |
| `broadcastFields` | `list<string>\|null` | Fields whose values may be broadcast; `null` = identifiers only |
| `userid` | `?int` | Who caused it, when known |
| `source` | `string` | `web` / `api` / `cli` |
| `at` | `int` | Unix timestamp |
| `model` | `class-string` | The emitting class |
| `table` | `string` | Fully-qualified table name |

Helpers: `has($field)`, `only($fields)`, `except($fields)`, `changesOnly($fields)`,
`toArray()`.

`data` is the **whole record**, because in-process there is no trust boundary to
cross. Anything putting it on a wire is responsible for filtering it first — see
`broadcastFields`.

---

## Transactions

A change emitted inside an open transaction is **held** until that transaction
commits, and **dropped** if it rolls back.

```php
$db->startTransaction();
$device->save();          // nothing delivered yet
$db->rollbackTransaction();
// nothing was ever delivered
```

This exists for the listeners that write things down. A changelog row recording a
change that was rolled back is an audit trail nobody can trust. Broadcasting is
less sensitive — a broadcast carrying only identifiers costs, at worst, one wasted
refetch that returns the old data, and heals itself.

Wire it once, at boot:

```php
\Pramnos\Event\ChangeFeed::boot();
```

`boot()` registers `database.transaction.committed` → flush and
`database.transaction.rolledback` → discard. It is idempotent, so a service
provider booting per request in a long-running worker does not accumulate
listeners.

!!! warning "Two limits worth knowing"
    `Database::inTransaction()` tracks **one flag, not a depth counter**. A nested
    `startTransaction()` followed by the inner commit flushes the buffer while the
    outer transaction is still open. And a raw `BEGIN` issued through `query()` is
    not tracked at all — its own docblock says so — so changes inside one are
    delivered immediately.

    Neither is worked around in the feed. If you nest transactions around model
    saves and the difference matters, flush explicitly at the outermost commit.

### Ordering

Held changes are delivered in the order they were emitted. A listener that emits
during a flush — a changelog writer that is itself a model, say — has its change
delivered exactly once: the buffer is cleared *before* the first listener runs, so
re-entrant emissions land in a fresh buffer rather than one about to be wiped.

---

## Inspecting the feed

```php
ChangeFeed::pending();   // how many changes are waiting for a commit
ChangeFeed::flush();     // deliver them now
ChangeFeed::discard();   // drop them
ChangeFeed::reset();     // tests only — clears the buffer and the boot flag
```

---

## Related

- [Realtime Guide](Pramnos_Realtime_Guide.md) — the channels, drivers and daemon a
  broadcast listener publishes through.
- [ORM Guide](Pramnos_ORM_Guide.md) — `OrmModel`'s own `creating`/`created`/… hooks,
  which fire per model class and are a different thing from this bus.
