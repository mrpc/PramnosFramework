---
date: 2026-08-25
categories: [Changelog]
---

# Compression that made it larger

The changelog's `segmentby` was picked by reasoning about how TimescaleDB works.
Measuring it produced a bigger difference than the reasoning predicted — and
disproved the other half of the argument.

<!-- more -->

## The measurement

2 M rows, 12 entities, 240 000 records over 30 days
(`tests/Benchmarks/changelog_compression.php`):

| `segmentby` | chunk | ratio | stored | compress | per-row | recent |
|---|---|---|---|---|---|---|
| `entity` | 7 days | **12.82** | **37.5 MB** | 5.8 s | 11.0 ms | 4.8 ms |
| `entity` | 1 day | 10.02 | 48.7 MB | 4.6 s | 12.7 ms | 2.6 ms |
| `entity, itemid` | 7 days | 0.89 | 543 MB | 74.6 s | 16.8 ms | 176 ms |
| `entity, itemid` | 1 day | **0.59** | 822 MB | 133.6 s | **2.2 ms** | 53 ms |

## A ratio below 1

Compression made the table **larger**. Not marginally: 822 MB against 37.5 MB for
identical rows, and 133 seconds of CPU against 6.

TimescaleDB compresses in batches of up to 1000 rows *per segment*. A change log is
sparse per record — one row changes a handful of times a day — so putting `itemid`
in `segmentby` produces segments of a few rows each, far below that batch size, and
the per-segment overhead then exceeds the saving.

That was the prediction. It was right, and understated: "compresses to almost
nothing" is what the comment said, and the answer is "expands".

## The half that was wrong

The spec said the chosen layout **loses** on "recent changes across an entity", and
accepted that as the cost of a fast per-row lookup. It does not lose. It is 4.8 ms
against 176 ms — thirty-seven times faster.

Written down here rather than quietly corrected, because a stated trade-off that
turns out not to exist is the kind of thing that gets repeated in the next design
by whoever read it.

## What the other layout does win

`entity, itemid` at 1-day chunks takes the per-row lookup: 2.2 ms against 11.0 ms,
because the segment is located directly rather than found by skipping batches.

It costs 22× the disk and compression that does not compress. Not the default, but
the right answer for a log read constantly and kept briefly — which is what
`'hypertables' => [...]` overrides in `app.php` exist for.

## The rule, which generalises

- **`segmentby`**: columns you filter on that have *few* distinct values.
- **`orderby`**: the high-cardinality column first — compressed batches carry min/max
  metadata for `orderby` columns, so a filter on one skips batches without
  decompressing them.

## Documentation

- [Hypertable Guide](../../Pramnos_Hypertable_Guide.md) — a new "Choosing
  `segmentby`" section with the table above and the rule.
- [Model Change Feed Guide](../../Pramnos_Change_Feed_Guide.md) — what the log costs
  on disk, not only per write.
