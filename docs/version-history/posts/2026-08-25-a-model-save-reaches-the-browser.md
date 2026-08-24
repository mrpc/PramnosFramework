---
date: 2026-08-25
categories: [Changelog]
---

# A model save reaches the browser

`ChangeBroadcaster` connects the change feed to the channels the realtime stack
already serves. With `broadcasting` enabled, a model that emits reaches a browser
with no further wiring at all.

<!-- more -->

## Added

```php
// the model
protected $emitChanges = true;
protected $changeEntity = 'wcm-device';
```

```js
PramnosEcho.private('wcm-device')
    .listen('model.changed', debounce(refetchList, 150));
```

Registered by `BroadcastingServiceProvider::boot()`. A registration each project has
to remember is a feature that silently does nothing in most of them, and a listener
costs nothing while no model emits.

## The payload is identifiers, and that is the design

```json
{ "entity": "wcm-device", "key": 42, "op": "updated" }
```

Not the record. Not the changed values. **Not even the names of the columns that
moved** — "identifiers, plus which fields changed" is a half-rule to argue about
later, and field names alone map a schema the API never exposed.

The subscriber refetches through the API, where permissions already apply. So no
column can reach somebody the API would not have shown it to, no allow-list has to
be maintained as columns are added, and a missed or rolled-back event costs one
refetch returning current data rather than leaving a stale copy behind.

A model that declares `$broadcastFields` gets values and takes responsibility for
the choice — after reading the multi-tenant channel warning, because with values on
a subscriber on the wrong channel is a breach rather than a hint.

## The test that matters most asserts an absence

```php
$this->assertSame(
    ['entity' => 'wcm-device', 'key' => 42, 'op' => 'updated'],
    $sent['payload']
);
$this->assertArrayNotHasKey('data', $sent['payload']);
```

A test checking only "the message went out" passes on an implementation that
publishes the whole record. That failure is silent, looks correct in every log, and
is found by somebody reading a WebSocket frame.

## It never sees the model

The channels and the allow-list are resolved when the change is emitted and carried
on the `ModelChange`. Holding a model reference until a listener runs is the failure
`QueuedBroadcastableEvent` documents — a stale copy of a row that may no longer
exist — and a listener that can be queued must not be able to fall into it.

## What this does not replace

A database-backed subscription sees every write. This sees writes **through models**:
raw SQL, bulk `queryBuilder()` updates, migrations, `WriteSpool` and another service
writing to the same database emit nothing. That is the one capability genuinely lost
against Hasura, and the only equivalent is Postgres logical replication.

## Documentation

- [Model Change Feed Guide](../../Pramnos_Change_Feed_Guide.md) — a new
  "Broadcasting model changes" section with the five-step Hasura replacement and
  what it costs.
- [Realtime Guide](../../Pramnos_Realtime_Guide.md) — points `Model` users at the
  feed rather than the `Broadcastable` trait, which is for `OrmModel` and must be
  called by hand.
