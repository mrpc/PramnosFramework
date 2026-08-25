---
date: 2026-08-25
categories: [Changelog]
---

# The busiest table, and who calls it

The changelog measurement turned up a rule, and the rule pointed at `tokenactions` —
one row per API request, kept three years, and the highest-volume table the framework
declares.

<!-- more -->

## Changed

- `tokenactions` now declares `segmentby urlid, method` rather than
  `tokenid, urlid, method`.

**Existing installations are unaffected.** `HypertableRegistry::apply()` sets
compression only on a table that has none, so this reaches new databases only.

## It depends entirely on who calls the API

`tokenid` is high cardinality — one per issued token — which is the pattern that
compressed a change log to a ratio below 1. But an API log is not a change log, and
whether its segments are sparse depends on traffic shape. Both plausible shapes were
measured, on 2 M rows over 60 endpoints and 90 days:

| callers | `segmentby` | ratio | stored | by-token | by-url |
|---|---|---|---|---|---|
| few, long-lived | `tokenid, urlid, method` | 6.95 | 36.8 MB | **0.41 ms** | 0.65 ms |
| few, long-lived | `urlid, method` | 7.72 | 33.0 MB | 6.83 ms | 0.44 ms |
| many, short-lived | `tokenid, urlid, method` | **0.50** | 515.5 MB | 5.44 ms | 38.5 ms |
| many, short-lived | `urlid, method` | 6.76 | 37.9 MB | 6.68 ms | 0.46 ms |

For a server-to-server API the shipped layout was **right**: 0.41 ms on "what did
this token do", sixteen times faster than the alternative, and a healthy ratio.

For an API serving browser sessions it collapses — and the detail that settles it is
that it does not merely trade disk for speed. At 0.50 it is storing 515 MB instead of
38 MB, spending 43 seconds compressing instead of 3, **and** answering the per-token
query more slowly than the layout without `tokenid` in it. It loses on every axis at
once.

## Why the default changed rather than the documentation

A framework default cannot know which kind of API an installation runs, and the bad
case is silent: nothing reports that compression is making a table larger. So the
default is the layout that is never bad, and an installation that knows its callers
takes the faster lookup deliberately:

```php
'hypertables' => [
    'tokenactions' => ['segmentby' => 'tokenid, urlid, method'],
],
```

The price of the safe choice is a token-history listing at 6.8 ms rather than
0.4 ms. That is an admin screen, not a hot path — and the analytical reads go through
the hourly continuous aggregate rather than this table.

## The other six were checked too

Five declare no `segmentby` at all, which means one segment per chunk and large
batches: no exposure. `application_stats` segments by `appid` and `audit_log` by
`event_type`, both low cardinality. `tokenactions` was the only one matching the
pattern.

## A test that argues with a number

```php
$this->assertStringNotContainsString('tokenid', (string) $spec['segmentby'],
    'tokenid is high cardinality: segmenting by it compresses a session-heavy '
    . 'API to a ratio below 1');
```

The existing assertion compared the whole declaration, which anybody changing
anything about it would update wholesale. This one asserts the single property that
matters and says why, so putting `tokenid` back has to argue with a measurement.

## Documentation

- [Hypertable Guide](../../Pramnos_Hypertable_Guide.md) — the table above, when to
  override it, and a note that existing installations keep the layout they were
  compressed with.
