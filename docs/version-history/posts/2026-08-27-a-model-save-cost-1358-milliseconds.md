---
title: A model save cost 1358 milliseconds
date: 2026-08-27
categories:
  - Performance
  - Cache
---

# A model save cost 1358 milliseconds

Not in a pathological case. On every write, on every deployment, with an empty
cache.

<!-- more -->

## What was happening

`Database::cacheflush()` is called by `Model::save()`. It calls
`Cache::clear($category)`, and `FileAdapter::clear()` ended like this:

```php
$this->cleanup();
```

`cleanup()` walks the **entire** cache tree — not the category being cleared, all of
it — and reads and unserialises every file to decide whether it has expired. So the
cost of writing one row was the cost of inspecting everything the application had
ever cached.

Measured on this project's container: **1358 ms per call.** With the cache holding
no files at all.

## The second half

No files, and still 1358 ms. The walk was of **3064 empty directories**.

`cleanup()` finished with `cleanEmptyDirectories($this->cacheDir)`, and that method
begins:

```php
if ($dir == $this->cacheDir) {
    return;
}
```

It walks *upward* from a directory it is given, removing empties as it goes, and
stops at the root. Handing it the root is a guaranteed no-op — so it had never
removed anything. Every directory a cache write created stayed for good, and each
one was walked again by every subsequent sweep. The tree only grew, and the sweep
only slowed.

Two bugs, and each one hid the other: the sweep was slow because of the
directories, and the directories accumulated because the sweep's cleanup call did
nothing.

## The fix

**The sweep is sampled.** Expired entries are not a correctness problem — `load()`
checks the timestamp before returning anything, so a stale file is never served.
The sweep only reclaims disk, and that is work to do occasionally rather than on
every write. It now runs on one `clear()` in a hundred.

**And never under `PRAMNOS_TESTING`.** A suite in which some calls sweep and others
do not is a suite whose result depends on a random draw: this framework has a test
asserting on cache contents that earlier tests left behind, and it started passing
or failing by luck the moment the sweep became occasional. The sweep is covered by
overriding the sampling method, which is the only way to test it without a coin.

**`cleanup()` prunes properly**, bottom-up, so a directory whose children have just
gone is seen as empty in the same pass.

**1358 ms → 0.21 ms.**

## What it was costing

This surfaced as a test-suite measurement — the framework's suite was 12:28 and is
now 3:19 — but the suite was only the place it was visible. Every model save in
every deployment paid it, on a cache that had been running long enough to
accumulate directories. Nobody profiles a save.

## Notes

- Nothing to change in a project.
- A cache directory with thousands of empty subdirectories in it will be tidied by
  the first sweep that runs. Deleting them by hand is safe at any time.
- If you have your own adapter with a `clear()` that sweeps, it has the same shape.

## Documentation

- `Pramnos_Test_Suite_Performance.md` — a dated section with this and the two other
  findings from the same measurement.
- `Pramnos_Testing_Guide.md` — a row in the "does not slow the suite down" table.
