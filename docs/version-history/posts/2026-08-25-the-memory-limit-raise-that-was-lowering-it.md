---
date: 2026-08-25
categories: [Changelog]
---

# The memory_limit raise that was lowering it

Four tests in a long-running suite reported a PHP warning. The warning was right, the
code was wrong, and on a generous host the effect was the opposite of what was intended.

<!-- more -->

## Fixed

- **`ResizeTools` raises `memory_limit` and no longer lowers it.** Resampling with a fill
  colour set `ini_set("memory_limit", "256M")` unconditionally before `imagefill()`. On a
  host configured with more than 256 MB that is a **reduction**, so the fill ran with less
  memory than the request already had — the exact failure the raise exists to prevent. And
  once the process was already using more than the new value PHP refused it outright:

  ```
  Failed to set memory limit to 268435456 bytes (Current memory usage is 279969792 bytes)
  ```

  It now parses the current limit and does nothing when it is unlimited or already at the
  floor. Raising only also makes the call unable to fail: usage can never exceed the
  current limit, so a new limit above it is always above usage.

- **Two `try/catch` blocks that could never fire are gone.** Both wrapped `ini_set()` and
  logged a caught `\Exception`. `ini_set()` does not throw — it returns `false` and raises
  a PHP warning — so the handlers were unreachable, the warning went unhandled, and the
  code read as though failure were covered. That is the part worth naming: a guard that
  cannot fire is worse than none, because it stops anybody from looking.

- `Helpers::parseMemoryLimit()` is public, so both callers share one parser rather than
  one having a private copy and the other a hard-coded literal.

## Tests

Five, and one of them is a lesson. The end-to-end test drives the real path — a fill
colour, an unlimited limit — and fails on *any* PHP diagnostic, which is what would have
caught the original.

The raise-path test asks for a floor **above** the current limit rather than lowering the
limit below the floor. The first attempt did the latter, `64M` against a 256 MB target,
and produced the very warning this change removes: a suite already using 179 MB cannot be
told its ceiling is 64 MB. Reproducing a bug inside its own test is a quick way to learn
that the arrangement, not the code, was causing it.

## Documentation

- [Media Guide](../../Pramnos_Media_Guide.md) gains **`memory_limit` while filling a
  thumbnail**: what the floor is, that a host above it is left alone, and what to do if
  256 MB is not enough.
