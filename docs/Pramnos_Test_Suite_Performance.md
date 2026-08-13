---
use_cases:
  - Finding out why the test suite takes as long as it does
  - Deciding what to change to make ./dockertest faster
  - Writing a test that will not slow the suite down
---

# Why the suite takes 15 minutes, and what would actually help

Measured on 2026-08-13 against `1471dc9a`, in the project's own Docker environment, with
`./dockertest --log-junit`. Every number below is from that run rather than from
reasoning about the code — the first thing the measurement did was contradict the
standing hypothesis.

## The headline

| Run | Wall clock | Tests |
| --- | --- | --- |
| `./dockertest` (coverage on, the default) | **17:02** | 9364 |
| `./dockertest --no-coverage` | **14:58** | 9364 |

Coverage instrumentation costs **≈124 s, about 12%**. Real, and not the lever.

## The distribution is the finding

From the JUnit log of the `--no-coverage` run (891 s of measured test time):

| Threshold | Tests | Share of count | Time | Share of time |
| --- | --- | --- | --- | --- |
| ≥ 1000 ms | 203 | 2.2% | 405.7 s | **45.6%** |
| ≥ 500 ms | 516 | 5.5% | 642.2 s | 72.1% |
| ≥ 100 ms | 1353 | 14.4% | 853.2 s | 95.8% |
| ≥ 50 ms | 1718 | 18.3% | 880.2 s | 98.8% |

**203 tests — 2.2% of them — account for 46% of the run.** The other 7646 tests, taken
together, cost less than eleven seconds more than those 203 do. That is what makes this
tractable: there is no need to make the suite generally faster, only about two hundred
specific tests.

By directory:

| | Time | Tests | Per test |
| --- | --- | --- | --- |
| `tests/Unit` | 439.5 s (49%) | 7298 | 60 ms |
| `tests/Integration` | 383.5 s (43%) | 1265 | **303 ms** |
| `tests/Characterization` | 67.6 s (8%) | 801 | 84 ms |

`tests/Feature` is declared in `phpunit.xml` and contains **no tests**.

## What the standing hypothesis got wrong

The assumption was that database setup dominates, because the suite exercises MySQL,
PostgreSQL and TimescaleDB. Two measurements say otherwise:

- **`tests/bootstrap.php` touches no database at all.** It defines constants and loads
  stubs. There is no DROP/CREATE, no migration run, no dump import — so there is no
  fixed setup cost to remove.
- **Half the time is in `tests/Unit`**, which mostly has no database. Integration is
  expensive *per test* (303 ms) but is only 43% of the total.

The three-database matrix is not the problem either: `QueryBuilderMySQLTest` (36.8 s)
and `QueryBuilderTimescaleDBTest` (8.2 s) are the same shape of test against different
engines, and that repetition is the point — the placeholder and quoting bugs this
framework has actually shipped were dialect-specific.

## Where the 203 seconds actually are

The ten most expensive classes, from the same log:

| Class | Time | Tests | Per test |
| --- | --- | --- | --- |
| `InitCommandUnitTest` | 114.5 s | 61 | 1877 ms |
| `MediaObjectTest` | 84.5 s | 86 | 982 ms |
| `FrameworkMigrationsMySQLTest` | 69.9 s | 50 | 1398 ms |
| `QueryBuilderMySQLTest` | 36.8 s | 92 | 401 ms |
| `BaseTestCaseTest` | 32.0 s | 15 | 2135 ms |
| `TwoFactorAuthServiceMySQLTest` | 31.7 s | 17 | 1865 ms |
| `TestEnvironmentTest` | 28.3 s | 16 | 1770 ms |
| `TokenTest` | 25.1 s | 33 | 760 ms |
| `TwoFactorAuthServicePostgreSQLTest` | 23.5 s | 17 | 1384 ms |
| `OrmRelationsMySQLTest` | 22.5 s | 29 | 776 ms |

## Four changes, in order of return

### 1. Seven tests waited exactly 8.00 seconds for a hostname that must not resolve — **done, 56 s**

The slowest individual tests in the whole suite were these, and every one took **8.00 s
to the hundredth**:

```
8.00s  BaseTestCaseTest::test_docker_hostname_switching
8.00s  BaseTestCaseTest::test_get_connection_failure
8.00s  BaseTestCaseTest::test_it_builds_correct_dsn
8.00s  BaseTestCaseTest::test_it_builds_postgres_dsn
8.00s  TestEnvironmentTest::test_initialize_database_docker_switching
8.00s  TestEnvironmentTest::test_full_setup_flow
8.00s  TestEnvironmentTest::test_it_handles_database_initialization_logic
```

A round 8.00 s is not work, it is a timeout. These tests construct a `PDO` against
hostnames such as `testhost` and `pghost` — deliberately unresolvable, because what is
being asserted is *which DSN was built*, proven by the failure message naming the host.

**The first attempt at fixing it was wrong, and the way it was wrong is worth keeping.**
A connect timeout (`PDO::ATTR_TIMEOUT`, `connect_timeout` in the DSN) looked like the
obvious answer and changed nothing: still 8.00 s. Measured directly,

```
php -r 'gethostbyname("testhost");'   →  8.00s
```

The block is in **`getaddrinfo()`** — DNS, before any socket exists — so no socket
option can reach it. A connect timeout guards a different failure: a host that accepts a
connection and then hangs.

What worked, and made the tests better rather than only faster:

- the three tests that asserted *which DSN was built* now assert on the DSN. `buildDsn()`
  and `resolvedHost()` were extracted for them, so a string built from configuration is
  checked as a string instead of being inferred from a connection error;
- the tests that assert a *failure* point at `127.0.0.1:9` — an IP literal skips the
  resolver entirely and the discard port refuses immediately.

| | Before | After |
| --- | --- | --- |
| `BaseTestCaseTest` | 32.0 s | **0.28 s** |
| `TestEnvironmentTest` | 28.3 s | **4.4 s** |

**56 s**, against an estimate of 49. The connect timeouts stayed in as well, documented
for what they actually cover.

### 2. `InitCommandUnitTest` scaffolds a whole project per test — 114 s

61 tests at 1877 ms each. Each one runs `init` into a fresh temporary directory: several
hundred file writes, template rendering, JSON merges. Most of them then assert something
read-only about the result — that a file exists, that a string appears in it.

**Change:** scaffold once per class in `setUpBeforeClass()` and have the read-only tests
assert against that tree; keep a per-test scaffold only where the test *changes* the
project (the `--force`, `--dry-run` and guard tests, which are a handful). Same coverage,
one scaffold instead of 61.

**Estimated saving 80–100 s.** The same pattern applies to `InitCommandTest` (19.0 s) and
`InitSpaScaffoldingTest`, for perhaps another 30 s.

### 3. Integration tests create their schema per test, not per class — up to 150 s

303 ms per test, and the classes are shaped like `FrameworkMigrationsMySQLTest` (69.9 s /
50 tests): `setUp()` drops and creates tables, the test writes a few rows, `tearDown()`
drops them again. Every test pays the DDL.

**Change:** the split that actually fits a database:

- **schema per class** in `setUpBeforeClass()` — DDL is not transactional in MySQL, so it
  cannot be rolled back and must not be per-test;
- **data per test inside a transaction**, rolled back in `tearDown()` — which is fast,
  and stronger than deleting rows because it cannot leave anything behind.

This needs a base class (`Pramnos\Tests\Support\DatabaseTestCase`) so it is one
implementation rather than fifty. Tests that assert *on* DDL — the migration tests — keep
their current shape, because for them the DDL is the subject.

### 4. Two specific classes worth reading before optimising

- **`MediaObjectTest`, 84.5 s / 86 tests.** It creates real JPEGs with `imagecreatetruecolor()`
  and `imagejpeg()`, and copies them around. Some of that is the point (the class
  manipulates images); some of it is probably one fixture image being regenerated 86
  times, which `setUpBeforeClass()` would fix.
- **`TwoFactorAuthService{MySQL,PostgreSQL}Test`, 55 s combined**, with single tests at
  3.6–5.3 s. Backup codes are hashed, and a default-cost hash is deliberately slow.
  Lowering the cost *for the test environment only* is the standard move; the algorithm
  under test is unchanged.

## What not to do

- **Do not remove the always-on `<coverage>` block.** It costs 12%, and `--no-coverage`
  already exists for a run that does not need it. Rule 11 requires coverage on new code,
  and a coverage report that has to be asked for is a coverage report nobody has.
- **Do not drop a database from the matrix.** The bugs this framework has shipped in the
  query builder were dialect-specific — a `?` placeholder that only MySQL tolerated, a
  backtick that only MySQL accepts. The repetition is the test.
- **Do not reach for parallelism first.** `paratest` would give perhaps 3–4× on this
  machine, but the database is shared: it needs a schema or database per worker, and that
  is a larger change than items 1–3, which together are worth ~5 minutes on their own.
  Parallelism is the right *second* step, and it becomes easier once item 3 has moved
  schema creation to one place.

## Expected result

| Change | Saving |
| --- | --- |
| 1. ~~Connect timeouts~~ — **done**, by asserting on the DSN and using an IP literal | **56 s** |
| 2. Scaffold once per class | 80–130 s |
| 3. Schema per class + transaction per test | up to 150 s |
| 4. Fixture and hash cost in two classes | 40–80 s |

Around **five to six minutes off a fifteen-minute run**, without removing a single test,
a single database or the coverage report. The remaining nine minutes would be genuine
work, at which point parallelism is the next lever.

## How to measure it again

```bash
./dockertest --no-coverage --log-junit /var/www/html/var/junit.xml
```

Then read the XML: total per suite, time per class, and the distribution above. A change
that does not move the "≥ 1000 ms" row has not moved the suite.
