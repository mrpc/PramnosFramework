---
use_cases:
  - Broadcasting model changes to a browser without polling
  - Replacing a Hasura subscription with the framework's own WebSocket
  - Writing an audit log of every change a model makes
  - Reacting in application code when any model is saved or deleted
  - Turning a model save into a live update in the browser
  - Keeping an audit trail of everything a model changed
  - Recording what a user did, in place of the deprecated userlog table
  - Showing one organisation's activity log in a multi-tenant application
---

# Pramnos Change Feed Guide

Every model save and delete can announce itself. One local event carries what
changed; listeners turn that into whatever the application needs — a broadcast on
a channel, a row in a changelog, a message on a queue.

```php
class Device extends \Pramnos\Application\Model
{
    protected $emitChanges = true;
}
```

That is the whole opt-in. Declare it untyped, as the base class does: `Model` declares
`protected $emitChanges = false`, and PHP refuses to load a subclass that adds a type to it. Nothing else about the model changes, and a model that
does not set it emits nothing at all.

!!! note "Local first, sockets second"
    The feed is a **local** event bus. It works with no broadcasting driver, no
    Redis and no WebSocket daemon — which is why it is `$emitChanges` rather than
    `$broadcastChanges`. Broadcasting is one listener among several.

---

## Turning it on

Six properties, all on `Pramnos\Application\Model`, all optional but the first:

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

    /** Attach a stack trace to the changelog row. See "Traces are opt-in". */
    protected $captureTrace = false;
}
```

| Property | Default | |
|---|---|---|
| `$emitChanges` | `false` | The opt-in. Nothing happens without it |
| `$changeEntity` | `''` → model name | A stable, self-describing name |
| `$changeIgnoreFields` | `[]` | Dropped from both the diff **and** the record |
| `$changeSignificantFields` | `[]` → any field | Applies to **updates only** |
| `$broadcastFields` | `null` | See [Payloads](#payloads) |
| `$captureTrace` | `false` | See [Traces are opt-in](#traces-are-opt-in) |

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

**Registering a listener again does nothing**, for the same event and priority. So an
application can register its listeners in `init()` even when it starts many times in one
process, as it does once per test in a suite. Two registrations count as the same when:
- they are the identical callable: a function name, `[Class, 'method']`, or the same object;
- they are closures written at the same place in the code, in the same class, that
  captured the same values;
- they are equal instances of the same `ListenerInterface` class.

A loop that registers a closure per item, each capturing a different value, keeps every
registration.

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
| `captureTrace` | `bool` | Whether the model asked for a trace |
| `trace` | `?string` | The stack trace taken at the save, when `captureTrace` is on |

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

## Writing changes down

Enable the `changelog` feature and every emitted change is recorded:

```php
// app.php
'features' => ['changelog'],
```

That is the whole setup. The tables come with the feature — an application that does
not enable it gets none of them.

### Three tables, because retention is per table

A TimescaleDB retention policy drops **whole chunks by time** and takes no row
predicate, so one table can only ever have one retention. These three populations
have genuinely different answers to how long they are worth keeping:

| Table | What | Kept |
|---|---|---|
| `pramnos.changelog` | machine diffs, one row per save | 30 days |
| `pramnos.changelog_events` | things a person did | 2 years |
| `pramnos.changelog_trace` | stack trace and request context | 3 days |

Retune any of them from `app.php` without editing the framework:

```php
'hypertables' => [
    'pramnos.changelog' => ['retention' => '90 days'],
],
```

### Recording an event a diff cannot express

```php
$device->logEvent('device.assigned_on_finalize', ['tmpdeviceid' => 7]);
```

A save records what changed; this records what *happened*. It needs no
`$emitChanges` — a model can record deliberate events without announcing every save.

This is also where events about a **user** go. `userlog` is
[deprecated](Pramnos_Authentication_Guide.md#userlog-is-deprecated): it records only the
user a row is about, and this table records the actor and the source as well.

### Without a model, and on behalf of somebody

```php
use Pramnos\Changelog\ChangelogWriter;

ChangelogWriter::event('user', $userId, 'newsletter.opened', ['list' => 'news']);
ChangelogWriter::event('organization', 12, 'post.published', ['post_id' => 42], by: $editorId);
ChangelogWriter::event('device', 42, 'device.expired', by: 0);   // nobody: the system did it

$device->logEventAs($operatorId, 'device.replaced', ['by' => 43]);
```

`ChangelogWriter::event()` writes the row `logEvent()` writes, for any `(entity, itemid)`:
- a user, which is not a model;
- an organisation with no model of its own;
- a row brought over from an older log.

It goes through the same spool and into the same table.

- **`by`** is who did it. Null asks who is signed in, and `0` is nobody. A console command
  or an import acting for a person says who. A model does the same with `logEventAs()`,
  while `logEvent()` keeps recording whoever is signed in.
- **`at`** is when, as a Unix time. It is now unless an import says otherwise.
- **`scope`** is the tenant; **`source`** (`web`, `api` or `cli`) is worked out unless
  given.

A failure is logged and never thrown, as for `logEvent()`: recording that something
happened must not be the reason it fails to.

### Bringing an application's own log over

An application that kept its own log — a `userlog`, an `itemlog` — moves it with a command of
its own, because only the application knows what its columns and logtypes meant. The writer
takes what such a command needs: the moment the row was written (`at:`), who acted where the
log recorded it (`by:`, `0` for nobody), and a `source` of its own.

```php
foreach ($rows as $row) {
    ChangelogWriter::event('user', $row['userid'], self::EVENTS[$row['logtype']] ?? 'user.legacy',
        $details, (int) $row['logtype'], $row['log'] ?: null, by: 0, at: (int) $row['date'], source: 'import');
}
```

Two cautions for such a command. Unserialize a stored `details` with
`['allowed_classes' => false]`: an old row must never be able to construct an object. And give
each logtype an event code once, so the row can be rendered and translated, which stored prose
never can.

### Reading it back

```php
foreach (ChangelogReader::history('wcm-device', 42) as $row) {
    echo ChangelogRenderer::describe($row);   // "status: 1 → 3"
}
```

`history()` defaults to **application events only**. The automatic feed is one row
per save and would bury them; ask for `['events', 'feed']` in a diagnostic view.

For a screen that lists the log, `listing()` gives a page and the total the pager needs:

```php
$page = ChangelogReader::listing(['entity' => 'user', 'itemid' => 42], limit: 25, offset: 50);
$page['rows'];    // these 25, newest first
$page['total'];   // how many match

ChangelogReader::listing(['userid' => 7, 'from' => strtotime('-7 days')]);           // what 7 did
ChangelogReader::listing(['event' => ['user.login', 'user.logout'], 'source' => 'api']);
ChangelogReader::listing(['scope' => 'org:12'], order: 'asc');
```

Every filter is optional, and any combination of them applies at once:
- `entity`, `itemid`;
- `origins`, which defaults to events, as `history()` does;
- `event`, `logtype`, `userid`, `source`;
- `from` (inclusive) and `to` (exclusive), as Unix times;
- `scope`.

`itemid`, `event` and `logtype` each take one value or a list. An unknown filter is refused
rather than ignored, because a typo would otherwise list everything.

Labels are optional and go through i18n first:

```php
ChangelogRenderer::label('wcm-device', [
    'status'                      => 'Status',
    'device.assigned_on_finalize' => 'Assigned on finalize',
]);
```

### One tenant's events

A multi-tenant application asks "what happened in this organisation". Have the model
say which tenant its events belong to:

```php
protected function changeScope(): ?string
{
    return 'org:' . $this->organization_id;
}
```

`logEvent()` writes it to `changelog_events.scope`, and the reader filters on it:

```php
ChangelogReader::eventsInScope('org:12');              // newest first, 50 by default
ChangelogReader::eventsInScope('org:12', 'post', 20);  // only its posts
```

- **Null by default.** A model that does not override `changeScope()` writes no scope,
  so an application with no tenants writes exactly the row it always did.
- **A string, not an id**, so one column can hold more than one kind of owner
  (`org:12`, `account:7`). At most 64 characters.
- **Events only.** The automatic feed has no scope, and neither does `history()`'s
  view; `eventsInScope()` reads the events table directly.
- **Costs an application with no tenants nothing.** On PostgreSQL the index on
  `(scope, created_at)` is partial, `WHERE scope IS NOT NULL`, so it stays empty while
  nothing is scoped. MySQL has no partial indexes and gets no index: a full one would be
  paid on every insert by applications that never scope anything.
- **Not in the compression `segmentby`.** Changing segmentby on a live hypertable means
  decompressing every chunk first, so that is a maintenance-window decision.
- **Copied onto the event, not joined from the record.** The post already knows its
  organisation, but a join loses `post.deleted` once the post is gone, and rewrites
  history if the post moves to another organisation. The event keeps the tenant it
  happened in.
- **Deploying before `migrate` loses nothing but the scope.** Until the column exists
  the drain writes the event without it, and `eventsInScope()` returns `[]`. Events
  written before a model had a scope belong to none.

Storing the tenant inside `details` and filtering on `details->>'organization_id'` works
on a small table, but it reads every compressed chunk in the window.

### Traces are opt-in

```php
protected $captureTrace = true;
```

`getTraceAsString()` on every save is not free. Turn it on while chasing something
and off afterwards — and note that traces are kept days while the rows they describe
are kept weeks, so most rows have none.

### What it costs

`WriteSpool`, not an insert: **0.003 ms** per row against 2.807 ms for an insert into
a compressed hypertable, both measured in this repository. `spool:drain` writes them
in batches a minute later, and the framework's own schedule already runs it.

On disk, 2 M rows compress to **37.5 MB** — a ratio of 12.8 — with the declared
layout. The obvious alternative layout stores the same rows in 822 MB, because
compression there makes the table *larger*; see
[choosing `segmentby`](Pramnos_Hypertable_Guide.md#choosing-segmentby-a-measurement-worth-repeating).

### And what the drain costs, which is nothing on a backend you do not use

`spool:drain` runs `everyMinute` on the framework schedule, so anything it does per pass it
does sixty times an hour, for ever. Two things follow:

**It finds the Redis lists from a set of table names**, `spool:tables`, written when a row is
pushed — not from `KEYS spool:*`. `KEYS` is O(the whole database) and **blocks the server**
for its duration; run every minute, it dominates the slowlog and crowds out everything else
the slowlog could have told you.

`SCAN … MATCH` is not the substitute: `MATCH` filters what is returned, not what is
traversed, so it trades one stall for a walk of the whole database. A set of names is
O(tables).

**And it does not ask Redis at all when the spool is `file`**, which is the default. With file
spooling the rows never reach Redis, so a Redis call per pass would stall the server only to be
told there is nothing there. A backend nobody enabled should cost nothing.

The check is *could rows be there*, not *is that the configured driver*: a driver changed from
`redis` to `file` leaves whatever was already pushed, and refusing to look would strand it.

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
