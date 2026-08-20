---
date: 2026-08-20
categories: [Changelog]
---

# Rows that could never be written

The write spool is a promise to write a row later. Nothing decided what to do when later
turned out to be too late.

<!-- more -->

## Added

A row that fails is retried **five times** and then parked in `<table>.spool.failed`, with
the error that stopped it and a timestamp — removed from the spool, never read back, and
not counted as pending.

The case is ordinary and permanent: `tokenactions` carries a foreign key to `usertokens`,
and a token cleaned up while its rows waited takes the key with it.

```
insert or update on table "_hyper_12_225_chunk" violates foreign key constraint
DETAIL: Key (tokenid)=(3907) is not present in table "usertokens"
```

Nothing will make that row writable. The drain requeued it anyway, so on the installation
that reported this — where the drain had never run at all, and the backlog outlived the
tokens it referenced — 209 rows failed every minute once the schedule started working,
each printing its own line. A backlog that cannot drain is worse than one that never
drained: it is loud, it is permanent, and it buries the failures that *are* actionable.

Parked rather than dropped, because the row is somebody's audit trail and "here is what we
could not write, and why" is something an operator can act on. `spool:drain --status`
reports the count, `WriteSpool::parked()` returns it.

**Identical failures are now reported once with a count** — `209× insert or update on
table … violates foreign key constraint` — with at most three distinct messages per file
and the rest counted. The per-row line is gone.

`--max-attempts=N` tunes the limit per run, `WriteSpool::setMaxAttempts()` in code, and the
`spool_max_attempts` setting per installation. `0` restores the old behaviour of retrying
for ever, for an installation that would rather keep the rows and fix the cause.

The line format is unchanged for rows appended normally; a requeued row is wrapped with its
attempt count, and a drain reads both — so an upgrade mid-backlog loses nothing.

## Documentation

`Pramnos_Workers_And_Daemons_Guide.md` §1d — the write spool, the retry limit, what a
parked row is and what to do with it.
