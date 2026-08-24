---
date: 2026-08-24
categories: [Changelog]
---

# A save that announces itself

`Model::_save()` and `_delete()` can now emit on the change feed. One property
turns it on; every existing model stays silent.

<!-- more -->

## Added

```php
class Device extends \Pramnos\Application\Model
{
    protected $emitChanges = true;
    protected $changeEntity = 'wcm-device';
    protected $changeIgnoreFields = ['viewcache', 'stats', 'alerts'];
    protected $changeSignificantFields = ['status', 'customerid', 'eui'];
}
```

Also `changeChannels()` for multi-tenant channel naming,
`withoutChangeEmission()` for operations whose physical shape is not their meaning,
and `$broadcastFields` — which is `null` by default and means a broadcast carries
identifiers only.

## Fixed

- **`OrmModel`'s soft delete announced an update.** It performs its work through
  `parent::_save()`, so the base class would have described a delete as an `UPDATE`
  and a subscriber would have kept showing a row the application considers gone.
  The write is now silenced and the truthful `deleted` emitted in its place.

## The test that would be the one to keep

```php
public function testAModelThatHasNotOptedInEmitsNothing(): void
{
    $model = $this->model();

    $model->emit(ModelChange::CREATED);
    $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);
    $model->emit(ModelChange::DELETED);

    $this->assertSame([], $this->received);
}
```

Every model in every application that upgrades is that model. Asserted for all three
operations rather than one, because the guard is a single early return and a
refactor could move it below any of them.

## The exclusion list caught all six new properties

`Model::getData()` filters internals out of every payload using
`INTERNAL_PROPERTIES`, a hand-maintained list — and the test that guards it derives
its side from the class by reflection rather than trusting the list. Adding six
properties without listing them failed immediately:

```
+    0 => 'Pramnos\Application\Model::$emitChanges',
+    1 => 'Pramnos\Application\Model::$changeEntity',
+    2 => 'Pramnos\Application\Model::$broadcastFields',
...
```

`$changeEntity` is a plain string, so the old type filter would have waved it
through into every API response of every model that opted in. That test was written
after the list turned out to be missing two entries the last time; it earned its
keep again.

A second, subtler consequence showed up next to it. Three tests assert `getData()`
is byte-identical to the implementation it replaced, by running a literal copy of
the old code beside it. That copy is 2018 logic, and it was now being run over a
2026 object — so it reported six new internal properties as differences. Nothing
was broken: the properties never existed in any release, so no payload changed for
anybody. The reproduction just needed telling that machinery added since is not
part of what backwards compatibility covers.

## Where it fires, and where it deliberately does not

`_save()` emits once, at the very end, after every path that could still return
early — a save with nothing to change, an update whose statement threw. A change
that did not reach the database is never announced.

`_delete()` emits with the key that was **passed in**, not the one the model holds,
because `_delete($primaryKey)` does not load the row. It still does not: that would
be a query on every delete to populate a payload the default mode does not send.
Code needing full data on delete loads the model first, which an application doing
so already does.

## Documentation

- [Model Change Feed Guide](../../Pramnos_Change_Feed_Guide.md) — turning it on,
  payload modes, and the multi-tenant channel warning, which is stated where the
  feature is turned on rather than in a reference section.
