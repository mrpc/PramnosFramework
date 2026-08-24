---
date: 2026-08-24
categories: [Changelog]
---

# A model that says what it changed

Two classes land today. Neither does anything visible on its own, which is the
point: they are the seam everything else in this line of work hangs off.

<!-- more -->

## Added

- **`Pramnos\Event\ModelChange`** — one change to one record, as a readonly value
  object: what entity, which key, created/updated/deleted, the record, the diff,
  who did it and from where.
- **`Pramnos\Event\ChangeFeed`** — delivers those under a single event name,
  `model.changed`, and holds them while a database transaction is open.

```php
Event::listen(ChangeFeed::EVENT, function (ModelChange $change) {
    if ($change->entity === 'wcm-device' && $change->has('status')) {
        // …
    }
});
```

Nothing emits into it yet. The `Model` hooks that will are the next commit; this
one is the contract they write against.

## Why the transaction buffer is here from the start

It would have been easy to deliver every change the moment it happened and add
buffering later, when something complained. Nothing would have complained loudly.

A broadcast published for a change that then rolled back costs one wasted refetch
that returns the old data — it heals itself, silently, and nobody files a bug. But
the same feed is meant to drive an audit log, and a changelog row recording a
change that did not happen is not self-healing. It is a record that is wrong,
indistinguishable from the records that are right, discovered — if ever — long
after the transaction that produced it is unreconstructable.

So the buffer exists for the listener that writes things down, not for the one
that publishes them. Its test is the one that matters:

```php
FakeTransactionChangeFeed::$open = true;
FakeTransactionChangeFeed::emit($change);
FakeTransactionChangeFeed::discard();
// nothing was delivered
```

## Two limits, stated rather than papered over

`Database::inTransaction()` tracks a single flag, not a depth counter. Nest two
transactions around model saves and the inner commit flushes while the outer is
still open. A raw `BEGIN` through `query()` is not tracked either — its own
docblock has always said so.

Neither is worked around in the feed. Fixing them properly means a depth counter
inside `Database`, which is a change to shared machinery on behalf of a feature
that does not need it yet. They are in the guide instead, next to the buffer they
affect, with what to do about it.

## One event name, not three

`model.changed`, and listeners switch on `$change->entity` and `$change->op`.

The alternative — also firing `model.wcm-device.updated` — reads better at the
call site and costs a second registration that has to be kept in step with the
first. One of the two gets forgotten, and the listener that was registered against
the forgotten name receives nothing, with no error anywhere.

## Documentation

- New: [Model Change Feed Guide](../../Pramnos_Change_Feed_Guide.md), wired into
  the nav.
