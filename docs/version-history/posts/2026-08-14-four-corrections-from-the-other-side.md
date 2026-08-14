---
date: 2026-08-14
categories:
  - Changelog
  - Documentation
tags:
  - realtime
  - querybuilder
  - broadcasting
---

# Four corrections from the other side of the boundary

A consumer adopted the last three releases and sent back what happened. One item is a
**correction to this documentation**: a benefit claimed without the condition it depends on.
Two are shapes worth naming that no framework test would ever find. One is a hazard in a base
class that shipped without its warning.

<!-- more -->

## 1. Cursor persistence is worth nothing when subscribers do not outlive the ingest

The Realtime guide said, and had said since `RedisStreamSocket` shipped:

> A worker restarted mid-deploy with `SUBSCRIBE` misses everything published while it was down,
> while one reading from its last id is given the gap.

True, and incomplete in the way that matters. **Replay is worth it when the subscribers outlive
the ingest.** For an SSE endpoint — one process per client — they do: the client reconnects and
resumes. For a WebSocket worker that *owns the listening socket*, they do not: a restart drops
every client with it, so the backlog is replayed into the same empty room the events were
published into while it was down.

The consumer measured it rather than taking the paragraph at face value, and came back with a
negative result: their clients also re-read their state on every reconnect, because WebSocket
carries no initial snapshot, so the gap was already closed from the client side. Persisting
cursors would have added supervisor state, a stale-cursor failure mode and a backlog to filter,
to deliver events to nobody.

They kept the cue filter the [entry-id fix](2026-08-14-the-ingest-dropped-the-id-it-had-just-read.md)
made possible — one comparison, and it makes any future replay safe rather than something to
remember — and turned their tripwire into an ordering guard: the filter must exist before a
backlog can.

**The guide now states the condition, with a table of the two cases.** The framing was this
side's, and so is the correction.

## 2. A `try/catch` around a call that does not throw is a comment

The most useful thing in the report, and it is about code that had *already got the decision
right*:

```php
try {
    $patterns = $db->queryBuilder()->from('url_blacklist')->getAll();
} catch (\Throwable $e) {
    // a membership failing open grants somebody another station's tools, so this denies
    return self::DENY;
}

return $this->cache($patterns);   // ← an unreadable table arrives here, as []
```

`getAll()` does not throw on PostgreSQL, so the `catch` is unreachable and the failure walks
into the branch that caches a miss. Of the eight reads of this class they found, **six already
had a `catch` written for exactly this failure** — one arguing the fail-closed direction
explicitly, in a comment.

Their summary is the sentence to keep: *the week's work was less about deciding correct
behaviour than about making decisions somebody had already taken actually run.*

The [Query Builder guide](../../Pramnos_QueryBuilder_Guide.md) now names the shape, and says to
treat such a `catch` as a **signal rather than a guard**: somebody knew this could fail and
which way it should go, so check what the call actually does on failure.

The same sweep found one that was not a list at all: `ensureLaunchLicence()`, where an
unreadable table read as *"this station has no licence"* and a second one was written.

**Corrected 2026-08-14, after this was first published.** The report added that there was no
unique constraint behind the idempotence, and this page repeated it as fact. There is one —
`uq_licenses_one_current UNIQUE (station_id) WHERE ends_at IS NULL`, checked against the live
database — and they corrected their own docblock, which had it the wrong way round. The read is
still the defect; the missing constraint was not. Left visible rather than quietly edited,
because a page about repeating claims without checking them should not do it silently.

## 3. A channel whose safety rests on the authorizer, not its name

Their `private-admin-notifications` is a bare literal where every public channel beside it
rebuilds its name with a station id. They checked before reporting it, confirmed it is **not** a
leak today because the authorizer requires a platform admin — and then noted that their own
roadmap direction, admitting station owners, would put every station's reports in every
station's panel.

The reason that is worth documenting rather than fixing: **the two facts live in different
files.** Whoever widens an authorizer is reading the authorizer, not the worker that chose the
channel name. The guide now says to note the dependency where the channel is broadcast and pair
it with the authorizer in a test.

They also offered the general form, from a related find: *where a transport cannot carry
something, look for every mechanism that assumed it could.* `EventSource` cannot send headers,
so a header-based scope silently did nothing — and applying that rule immediately found a second
stream with the same gap. That sentence is now in the guide too.

## 4. `Service::database()` shipped without its warning

They adopted `Pramnos\Application\Service` and caught a hazard in it that this side had not
written down. The lazy fallback is `Factory::getDatabase()`, which is right for a service written
against the base and **wrong for one being moved onto it**: a class that previously reached its
database some other way changes which database it talks to the moment its constructor is left
defaulted. Nothing reports it, and every query still succeeds.

The numbers are why it now has its own section: **59 call sites** constructed the class they were
converting, and it had been built on an application-level `getInstance()`. Had the two resolvers
differed, a conversion sold as observability would have repointed all 59. They passed the
instance in explicitly and pinned it with a test.

The [Application Styles guide](../../Pramnos_Application_Styles_Guide.md) now says to do that,
and says to convert selectively — they converted one service out of sixty-five, which is about
the right ratio.

## What the framework got right, according to the other side

Worth recording because it is the part that is easy to lose:

- The **engine split** ask was answered *moot* — they run PostgreSQL in both places — but the
  sweep it prompted found `isNicknameAvailable()` folding a failed read into "nothing found" on
  all three of its lookups. Three failure paths, all granting: a guest could take a registered
  account's name, a station persona's, or one somebody was signed in under at that moment.
- The **Gate tab's `default` row** was adopted as a *rule rather than a feature*: they applied
  the insight to their own authorization layer, which has no gate involved, and now generate the
  vocabulary and the usage from the source by reflection rather than writing either into a test —
  *"a guard carrying its own copy of the vocabulary only agrees with itself."*

## Documentation

- [Realtime guide](../../Pramnos_Realtime_Guide.md) — when cursor persistence is worth it, and
  channels whose safety is the authorizer's.
- [Query Builder guide](../../Pramnos_QueryBuilder_Guide.md) — the unreachable `catch`.
- [Application Styles guide](../../Pramnos_Application_Styles_Guide.md) — converting an existing
  class onto `Service`.
