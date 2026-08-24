---
date: 2026-08-24
categories: [Changelog]
---

# A commit that says so

`commitTransaction()` and `rollbackTransaction()` now fire an event. Two lines of
production code, and the reason they are worth a post is the asymmetry between
them.

<!-- more -->

## Added

| Event | Name | When |
|---|---|---|
| `ChangeFeed::EVENT_COMMITTED` | `database.transaction.committed` | after a **successful** `COMMIT` |
| `ChangeFeed::EVENT_ROLLED_BACK` | `database.transaction.rolledback` | after `ROLLBACK`, **whether or not it succeeded** |

```php
Event::listen(ChangeFeed::EVENT_COMMITTED,   fn() => /* rows are durable */);
Event::listen(ChangeFeed::EVENT_ROLLED_BACK, fn() => /* drop what you held */);
```

## Why one is conditional and the other is not

The commit event fires only when the `COMMIT` succeeded. A failed commit leaves
rows that may or may not be there, and releasing listeners onto data in that state
is worse than releasing them onto nothing.

The rollback event fires either way, and that looks inconsistent until you ask what
a listener does with it. It drops work it was holding. "I could not undo that" is
not a reason to go ahead and announce the change — a listener that has already
written an audit row cannot take it back, so the safe reading of a failed rollback
is still *do not announce it*. Being wrong in the direction of silence costs a
missed notification. Being wrong the other way puts a permanent record of something
that may never have happened.

## Named for what happened, not for who listens

They are `database.transaction.*` rather than `changefeed.*` because the change feed
is the first consumer, not the only plausible one. Cache invalidation wants the same
seam. So does an outbox. Naming an event after its current subscriber is how you end
up with a second, near-identical event the day a second subscriber appears.

## What the integration tests pin

Ten tests across PostgreSQL and MySQL, and two of them are about ordering rather
than firing:

- by the time a listener runs, `inTransaction()` already answers `false` — otherwise
  a listener that re-enters the feed would buffer into a transaction that has ended
  and hold its work until a commit that never comes;
- the rows are actually durable when the committed event fires, asserted by counting
  them from inside the listener.

An event that fired a moment too early would pass a naive test and fail in exactly
the case it exists for.

## Documentation

- [Database API Guide](../../Pramnos_Database_API_Guide.md) — new "Transactions
  announce themselves" section under Transaction Management, including the
  one-flag-not-a-depth-counter warning.
- [Model Change Feed Guide](../../Pramnos_Change_Feed_Guide.md) — the consumer.
