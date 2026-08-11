---
date: 2026-08-12
categories:
  - Changelog
  - Added
tags:
  - timescaledb
  - compression
  - console
  - queue
---

# Late writes into compressed chunks no longer have to be lost

A hypertable with a compression policy refuses inserts into the ranges it has
already compressed. Until now the only answers were to lose the row or to leave
the table uncompressed for ever.

<!-- more -->

## The problem

Every application that writes late data meets this: a delayed reading, a
backfill, a correction, a webhook that arrives months after the event it
describes. The insert simply fails.

The workaround that suggests itself — decompress the chunk, write the row,
compress it back — is correct and unusable, because that pair costs the same for
one row as for ten thousand. Done per row it is slower than the data is worth;
done per chunk it is cheap. That grouping is the whole trick, it is not obvious,
and every application that meets compression will rediscover it, probably the
slow way first.

## Added

**`Pramnos\Database\DeferredWriteQueue`** — both halves of the pattern.

On the write side, `write()` decides per row whether the target time is still
writable, reading the cutoff from the **live compression policy** rather than
from a constant that drifts the first time somebody changes the policy. Recent
rows go straight in; late ones are queued in `deferredwrites`. A row that is
cleared and then fails anyway — the policy compressed the chunk in the second
between the two — is queued rather than lost.

On a database with no compression policy, on MySQL, and on any development or CI
box without TimescaleDB, there is no cutoff, nothing is ever deferred, and this
is a plain insert with one cached lookup in front of it. The same call works on
every backend, which is the point: the application does not branch on the
database it happens to be running against.

**`php pramnos timescale:drain`** — writes what is waiting, one
decompress/compress pair per chunk however large the backlog. It asks
TimescaleDB only for the chunks that actually have rows waiting, so a drain is
proportional to the backlog rather than to the table's age. `--status` reports
the backlog and the cutoff without touching anything; `--retry-failed` puts
failed rows back.

**`HypertableRegistry`** gained `deferred_writes`, `conflict` and
`conflict_update`, so the threshold and the overwrite rule are declared next to
the compression policy they belong to instead of being constants in a model.
Adding a table to the queue is a registry entry, not a code change — the queue
stores rows as JSON and carries no per-table knowledge.

**`SchemaBuilder::compressChunk()` / `decompressChunk()`** — chunk-level
compression, quoted through PostgreSQL's own `format('%I.%I', …)`. Both return
`false` on a backend without TimescaleDB rather than raising.

**`deferredwrites`** — a core framework migration, created on every backend.

## Behaviour worth knowing

A batch runs in one transaction. When it raises, the batch is replayed row by
row, so one bad row is marked failed and its five hundred blameless neighbours
are still written — the difference between a queue that drains and one that jams
behind a single row.

Failed rows are kept with their error message and never retried automatically. A
row that fails once usually fails the same way for ever, and an hourly retry
hides the problem instead of showing it. Fix the cause, then `--retry-failed`.

The chunk is compressed again even when every row in it failed, because a chunk
left decompressed never recompresses on its own — the policy only looks at
chunks it has not already handled.

## Documentation

[Hypertables guide](../../Pramnos_Hypertable_Guide.md) — new section, *Writing
late data into a compressed table*.
