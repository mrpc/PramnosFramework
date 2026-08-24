---
use_cases:
  - Broadcasting model changes to a browser without polling
  - Replacing a Hasura subscription with the framework's own WebSocket
  - Writing an audit log of every change a model makes
  - Reacting in application code when any model is saved or deleted
  - Turning a model save into a live update in the browser
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

## Turning it on

Five properties, all on `Pramnos\Application\Model`, all optional but the first:

```php
class Device extends \Pramnos\Application\Model
{
    /** Announce saves and deletes. Off by default. */
    protected $emitChanges = true;

    /** The name the feed uses. Defaults to the model name. */
    protected $changeEntity = 'wcm-device';

    /** Never reported, never leaves the process. */
    protected $changeIgnoreFields = ['viewcache', 'stats', 'alerts'];

    /** An update is only announced when one of these changed. */
    protected $changeSignificantFields = ['status', 'customerid', 'eui'];

    /** Fields whose VALUES may be broadcast. null = identifiers only. */
    protected $broadcastFields = null;
}
```

| Property | Default | |
|---|---|---|
| `$emitChanges` | `false` | The opt-in. Nothing happens without it |
| `$changeEntity` | `''` → model name | A stable, self-describing name |
| `$changeIgnoreFields` | `[]` | Dropped from both the diff **and** the record |
| `$changeSignificantFields` | `[]` → any field | Applies to **updates only** |
| `$broadcastFields` | `null` | See [Payloads](#payloads) |

### Where it fires

`_save()` emits once, at the very end — after every path that could still have
returned early. A save with nothing to change returns before it; so does an update
whose statement threw. A change that did not reach the database is never announced.

`_delete()` emits after the row is gone, with **the key that was passed in**, which
is not always the one the model holds. The row is deliberately not loaded first:
that is a query on every delete to populate a payload the default does not send.
Code that needs full data on delete loads the model before deleting.

!!! warning "`$force = true` reports every field as changed"
    `_save($table, $key, $autoGetValues, $debug, true)` skips the change detection,
    so `_lastChanges` — and therefore the feed — reports every column with
    `old => null`. That is existing behaviour of a public method and is not changed
    here. Consequence: a forced save always passes the significance gate.

### Soft deletes announce a delete

`OrmModel`'s soft delete performs an `UPDATE`, but means a delete. The framework
silences the write and emits `DELETED` itself, so a subscriber does not keep showing
a row the application considers gone.

If you write a similar operation — a physical shape that is not its meaning — do the
same:

```php
$this->withoutChangeEmission(function () {
    parent::_save();
});
$this->emitChange(ModelChange::DELETED, [], $id);
```

### Failure is never the save's problem

Everything in the emission path is wrapped: a listener that throws is logged and
swallowed. A broadcaster that cannot reach Redis must not turn a committed write
into an exception the user sees.

---

## Payloads

`$broadcastFields` decides what leaves the process, and **only** what leaves the
process. Local listeners always receive the whole record — in-process there is no
boundary to cross.

**`null` (the default) — identifiers only.**

```json
{ "entity": "wcm-device", "key": 42, "op": "updated" }
```

The subscriber refetches through the API, where permissions already apply. No column
can reach somebody the API would not have shown it to, no allow-list has to be
maintained as columns are added, and a missed or rolled-back event costs one refetch
that returns the current data.

**A list — values travel.**

```php
protected $broadcastFields = ['deviceid', 'status', 'lastupdate'];
```

```json
{ "entity": "wcm-device", "key": 42, "op": "updated",
  "data":    { "deviceid": 42, "status": 3 },
  "changes": { "status": { "old": 1, "new": 3 } } }
```

One message, no roundtrip — and the model now owns the decision. Read the channel
warning below **before** turning this on.

---

## Channels

The default is a per-table firehose plus a per-row channel:

```
private-wcm-device
private-wcm-device.42
```

!!! danger "Override this in a multi-tenant application"
    Every subscriber authorized for `private-wcm-device` learns that **any** row of
    the table changed, whoever owns it. With identifiers-only payloads that leaks
    existence and timing — the refetch it prompts is denied by the API — but it is
    still a leak. With `$broadcastFields` set it is a breach.

    **Per-tenant channels are a precondition for turning values on.**

```php
public function changeChannels($op)
{
    // The row's own owner — never User::getCurrentUser().
    return ['private-deya.' . $this->deyaid . '.wcm-device'];
}
```

The tenant key must come from the **row**. Reading it from the session works until a
queue worker or a CLI import runs, where there is no session and every change would
publish onto one tenant's channel — or none.

Whatever this returns needs a matching `ChannelRegistry` rule. A channel nobody is
authorized for is a publish into nothing, and it is silent.

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

**You do not have to wire this.** The first emission registers the listeners
itself, because the alternative failure is silent and conditional: without the
wiring, a change emitted inside a transaction is buffered and never released, so the
feed would stop working for exactly the code that wraps its writes in a transaction.

`ChangeFeed::boot()` is still public if you prefer to wire it explicitly at
application boot. It registers `database.transaction.committed` → flush and
`database.transaction.rolledback` → discard, and is idempotent, so a service
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

## Broadcasting model changes

With the `broadcasting` feature enabled, changes reach channels with **no further
wiring**. A model that sets `$emitChanges` is one property away from a live browser:

```php
// app.php
'features' => ['broadcasting'],

// the model
protected $emitChanges = true;
protected $changeEntity = 'wcm-device';
```

```js
PramnosEcho.private('wcm-device')
    .listen('model.changed', debounce(refetchList, 150));
```

One event name on the wire — `model.changed` — so a client binds once per channel
and switches on `op`.

### Replacing a Hasura subscription

This is the shape that replaces a WebSocket-to-the-database subscription:

1. enable `broadcasting`; `transport: websocket`, `default: redis`
2. run `broadcast:serve` under the daemon orchestrator
3. add a [`ChannelRegistry`](Pramnos_Realtime_Guide.md#channel-authorization) rule for
   `private-<entity>`
4. set `$emitChanges = true` on the models the front-end watches
5. replace the subscription with the two lines above

What you give up, stated plainly:

- **No ad-hoc query language.** The front-end needs API endpoints. Every `Model`
  implements `ApiListSource`, so the refetch is cheap to wire — but it has to exist.
- **No row-level permission on the stream.** The refetch goes through the API, which
  has them. Per-tenant channels are a precondition for turning `$broadcastFields` on.
- **No initial snapshot.** One GET on mount.
- **Changes not made through a model do not emit** — raw SQL, bulk
  `queryBuilder()->update()`/`delete()`, migrations, `WriteSpool`,
  `DeferredWriteQueue`, or another service writing to the same database. A database
  cannot be replaced by a model feed for those, and the only equivalent is Postgres
  logical replication.
- **A WebSocket reconnect loses what happened during it**, unless the backplane is
  `RedisStreamDriver` and the transport is SSE, which replays. Another reason the
  identifiers-only payload wins: a missed event costs a stale list until the next
  one, not a corrupted local store.

### Turning it off

The broadcaster is one listener among however many are registered. Removing it does
not affect the feed:

```php
Event::forget(ChangeFeed::EVENT);   // and re-register what you do want
```

---

## Related

- [Realtime Guide](Pramnos_Realtime_Guide.md) — the channels, drivers and daemon a
  broadcast listener publishes through.
- [ORM Guide](Pramnos_ORM_Guide.md) — `OrmModel`'s own `creating`/`created`/… hooks,
  which fire per model class and are a different thing from this bus.
