---
date: 2026-08-14
categories:
  - Changelog
  - Documentation
tags:
  - testing
  - performance
---

# Where the suite's fifteen minutes actually go

Measured rather than reasoned about, and the first thing the measurement did was
contradict the standing hypothesis. Full analysis:
[Test suite performance](../../Pramnos_Test_Suite_Performance.md).

<!-- more -->

## The hypothesis was wrong

The Roadmap had assumed database setup dominates, because the suite exercises MySQL,
PostgreSQL and TimescaleDB. Two measurements say otherwise:

- **`tests/bootstrap.php` touches no database at all** — constants and stubs, no
  DROP/CREATE, no migration run, no dump import. There is no fixed setup cost to remove.
- **Half the time is in `tests/Unit`**, which mostly has no database: 439 s of 891 s, at
  60 ms per test. Integration is expensive *per test* (303 ms) but is 43% of the total.

Coverage instrumentation costs **12%** — 17:02 with it, 14:58 without. Real, and not the
lever either.

## The distribution is the finding

| Threshold | Tests | Share of count | Share of time |
| --- | --- | --- | --- |
| ≥ 1000 ms | 203 | 2.2% | **45.6%** |
| ≥ 500 ms | 516 | 5.5% | 72.1% |
| ≥ 100 ms | 1353 | 14.4% | 95.8% |

**203 tests account for 46% of the run.** The remaining 7646 cost eleven seconds more
than those 203 do, together. There is no need to make the suite generally faster — only
about two hundred specific tests.

## The one that is almost funny

The slowest individual tests in the whole suite each take **8.00 s, to the hundredth**:

```
8.00s  BaseTestCaseTest::test_it_builds_correct_dsn
8.00s  BaseTestCaseTest::test_it_builds_postgres_dsn
8.00s  TestEnvironmentTest::test_full_setup_flow
… seven of them
```

A round 8.00 s is not work, it is a timeout. Those tests construct a `PDO` against
hostnames like `testhost` — deliberately unresolvable, because what is being asserted is
*which DSN was built*, proven by the failure message naming the host. Then the suite waits
for TCP to give up.

One line in four places (`PDO::ATTR_TIMEOUT => 1`) removes **49 s** without changing a
single assertion.

## The plan, with numbers

| Change | Saving |
| --- | --- |
| Connect timeouts on the seven 8-second tests | ≈49 s |
| `InitCommandUnitTest`: scaffold once per class, not per test (61 × 1877 ms) | 80–130 s |
| Integration: schema per class, data per test in a rolled-back transaction | up to 150 s |
| `MediaObjectTest` fixtures and `TwoFactorAuthService*` hash cost | 40–80 s |

Around five to six minutes, without removing a test, a database or the coverage report.

## And what not to do

**Not** dropping a database from the matrix: the query-builder bugs this framework has
actually shipped were dialect-specific — a `?` placeholder only MySQL tolerated, a
backtick only MySQL accepts. The repetition is the test.

**Not** making coverage opt-in: it is 12%, `--no-coverage` already exists, and a coverage
report that has to be asked for is one nobody has.

**Not** parallelism first. `paratest` would give perhaps 3–4× here, but the database is
shared and each worker needs its own schema — a larger change than the four above, which
are worth five minutes between them. It is the right second step, and it gets cheaper
once schema creation has moved into one place.

## Documentation

- [Test suite performance](../../Pramnos_Test_Suite_Performance.md) — the measurement, the
  plan, and how to run it again.
- [Testing Guide](../../Pramnos_Testing_Guide.md) — "Writing a test that does not slow the
  suite down": the three habits that put a test in the expensive 2%.
