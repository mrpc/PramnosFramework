---
use_cases:
  - Running the suite in parallel
  - Keeping a test from slowing the suite down
  - Reading coverage reports, and what #[CoversClass] decides
---

# A fast suite, and coverage that means something

Part of the [testing guide](Pramnos_Testing_Guide.md).

## Running the framework's suite in parallel

```bash
./dockertest --parallel        # 4 workers
./dockertest --parallel=6      # or as many as you choose
```

This is the framework's own suite, not a scaffolded project's. ParaTest splits the test files
over N workers, and then the `serial` group runs in a single process, in a fraction of the
sequential run's time. The sequential `./dockertest --nocoverage`
remains the reference run: there is no coverage in parallel, and a failure seen only in
parallel is a test that shares something.

**Each worker has its own database.** ParaTest sets `TEST_TOKEN` (1…N). `tests/bootstrap.php`
turns it into `TEST_DATABASE = pramnos_test_<N>`, creates that database on both servers when
it is missing, and points `DB_NAME` / `PG_NAME` at it. A sequential run has no token and keeps
`pramnos_test`. A test therefore names the database as `TEST_DATABASE` and never as a literal.

**Each worker has its own `var/`.** `VAR_PATH` and `CACHE_PATH` point at `var/workers/<N>`, so
the file cache, the verified-migration markers and the spool are not shared. A file the code
writes under the project root itself, such as `www/uploads/` or `src/Models/`, is not covered. A
test that writes there names its directory after `TEST_TOKEN`, or is serial.

**Every class starts with no settings and no connection.** `tests/Support/DatabaseStateIsolation`
clears both before each test class. A class that loads its own settings is unaffected. A class
that relied on the one before it to have loaded them fails sequentially too, and the fix is to
load them.

**A helper class lives in the file that uses it, or is required.** The autoloader maps a class
to a file of the same name. A test that uses a double declared in another test's file must
`require_once` that file, or it works only after that file has run.

**`#[Group('serial')]` is for what no database separates.** That means files under the
project (the cache directory, `www/uploads`, a generated model), the Redis instance,
`/tmp/dockertest-*`, and tests whose timing does not survive four workers' load. Before adding
a class to the group, see whether the shared thing can be given a per-test name instead.

## Writing a test that does not slow the suite down

The suite's cost is concentrated, not spread: a small fraction of the tests accounts for a
large share of the run. A few habits are what put a test in that group, and each has a
cheap alternative:

| Habit | Cost measured | Instead |
| --- | --- | --- |
| Connecting to a hostname that does not resolve | **8.00 s per test** | Assert on the DSN string if that is what you mean; use an IP literal (`127.0.0.1:9`) if you want a *failure*. A connect timeout does **not** help — the 8 s is `getaddrinfo()`, before any socket exists |
| Building an expensive fixture in `setUp()` — a scaffolded project, a real JPEG | 1–2 s per test | Build it once in `setUpBeforeClass()` when the tests only read it |
| Calling `$db->cacheflush()` in `setUp()` | **85 ms per call** — it is a directory scan | Call it once per class. It defends against what an *earlier* class left in the cache, and `query()` does not cache unless you ask it to |
| Hashing a password at the default cost | **143 ms per hash** — and 2FA setup hashes ten | Nothing: the suite already sets `PRAMNOS_BCRYPT_COST=4` in `tests/bootstrap.php`. Use `PasswordHash::make()` rather than `password_hash()` directly, so your code obeys it |
| Creating and dropping schema per test | ≈300 ms per test on MySQL | Schema once per class, rows deleted per test. A static flag in `setUp()` that builds the tables the first time and empties them after, with the drop in `tearDownAfterClass()`. A test that changes the schema clears the flag so the next one rebuilds |
| A throwaway database per test (`CREATE DATABASE`, then the migrations) | ≈500 ms per test on MySQL | One per class and engine, emptied per test. The framework's own suite does this with `tests/Support/ReusesProbeDatabase`. It is safe only while the migrations seed no rows |
| Writing a row with an explicit auto-increment id into a shared table | a duplicate-key failure in some *other* test, later | Let the sequence give the id (`RETURNING`, or read it back), and delete the row. On PostgreSQL an explicit id leaves the sequence behind |
| `#[RunTestsInSeparateProcesses]` on a class | a PHP process and a full bootstrap per test — 25 tests took 5.8 s | Nothing to work around: every class already starts with no connection and no settings (see above). Keep it only for what a process cannot undo, such as `define()` without a guard, `header()` or `exit` |
| Linting generated files with one `php -l` each | ≈40 ms a file — a scaffold of 80 files took 1.2 s | One `php -l` for many files (PHP 8.3+), then file by file only if that fails, to name the offender |
| MySQL's general log on (`--general-log=1`) | one file write per statement, to a file that is never rotated | Off. `pramnos init` leaves it off; a `docker-compose.yml` that has it on the `db` command can drop it |
| Letting the code under test shell out or reach the network | **1.9 s per test**, and variable | Skip it with the flag the command already has, or should have — `init` gained `--no-install` for exactly this. A unit test that depends on composer or on HTTP is slow *and* flaky |
| Saving a model, in a suite or in production | over a second per write if `cacheflush()` walks the whole cache tree | Nothing: `FileAdapter::clear()` samples its sweep instead. If a save is slow again, check that it still does |
| `exec('rm -rf …')` in `tearDown()` for a small temporary tree | **≈12 ms per test** (measured: 382 ms → 272 ms over nine tests) | A recursive `unlink`/`rmdir` helper — one already exists in `ApiDocsTest`. **Measure before converting a large tree**: for a scaffolded project of hundreds of files, `rm -rf` in C may well beat PHP recursion, and this row is not a licence to assume otherwise |

**PHPUnit's dependency resolution costs time on a large suite.** With `#[Depends]` unused,
`resolveDependencies="false"` on `<phpunit>` saves the quadratic resolution PHPUnit performs
before every run. In this framework's suite it was nearly half of a run filtered to one class.
Leave it on if any test uses `#[Depends]`.

**A PostgreSQL test database does not wait for the disk on commit.** `TestEnvironment`
runs `ALTER DATABASE <test db> SET synchronous_commit = off` on every run, which can
nearly halve a commit-heavy suite. The
setting belongs to that database, so a development database on the same server keeps full
durability. If the connecting user does not own the database, the step is skipped and the
suite is only slower.

**The MySQL test container keeps its data in memory.** `docker-compose.yml` mounts a
`tmpfs` at `/var/lib/mysql`, on top of the durability settings in `docker/mysql/my.cnf`. On
WSL and Docker Desktop every `CREATE`, `DROP` and `ALTER` otherwise goes through a slow
virtualised disk: the MySQL migration tests took 9.9 s on disk and 3.2 s in memory.
PostgreSQL gained nothing measurable from the same change, because `fsync=off` already
keeps it off the disk. Recreating the container starts it empty, which the suite expects.

**Granting many permissions? Use `allowMany()`.** One `allow()` per privilege flushes the
permission cache and queues a `permissions_changed` lookup every time. A test that sets
up an organisation's roles calls it dozens of times. See the
[Authorization Guide](Pramnos_Authorization_Guide.md#permissions-grants-in-a-table).

DDL is not transactional in MySQL, which is why the split is *schema per class, data per
test* rather than everything in one transaction.

**Empty tables with `DELETE`, not `TRUNCATE`.** Measured on this project's MySQL container,
for two tables:

| | |
| --- | --- |
| `DROP` + `CREATE` | 128.6 ms |
| `TRUNCATE` | **159.5 ms** |
| `DELETE` + `ALTER … AUTO_INCREMENT = 1` | 18.7 ms |
| `DELETE` | **0.22 ms** |

`TRUNCATE` looks like the fast path and is slower than recreating the table — it is an
implicit DDL statement. The auto-increment reset is 18 ms of the 18.7, so only pay it if an
assertion actually depends on the first row being id 1; prefer `assertGreaterThan(0, $id)`
and you never need to.

The full measurement, and what is planned from it, is in
[Test suite performance](Pramnos_Test_Suite_Performance.md). To check your own test's
place in the distribution:

```bash
./dockertest --no-coverage --log-junit /var/www/html/var/junit.xml --filter YourTest
```

## `#[CoversClass]` decides what a test contributes to coverage

### A test that names one class but exercises seventeen

Take a tool-discovery test that asserts the wire contract of every registered MCP tool — a
unique name, a description long enough to choose by, a JSON Schema that survives a
round-trip. Declaring `#[CoversClass(McpServiceProvider::class)]` on it is true of what it
asks the server for and false of what it exercises: every line of tool code it runs is
discarded from the report, invisibly, behind a green run. Without the attribute, those
lines count.

That is the case for declaring nothing at all when the subject is a contract rather than a
class. Naming every tool would work today and be wrong for the same reason the test is
written the way it is — a tool added tomorrow would silently stop counting. `requireCoverageMetadata`
is not enabled here, so a test with no attribute contributes everything it executes.

**`#[CoversClass]` is a filter, not a label.** Reach for it when a test's subject really is one or
two named classes, and leave it off when the test deliberately runs code outside them.

### And a file with no class needs `#[CoversFunction]`

The same rule, in the shape that is easy to miss. `src/Pramnos/DevPanel/adminer-object.php` declares
one global *function* — Adminer looks the hook up with an unqualified string, so it cannot be a
method. With only `#[CoversClass(AdminerBridge::class)]` on its test, every test passes and the
coverage report calls the file **0%**, because no attribute names it; `AdminerObjectHookTest`
therefore also carries `#[CoversFunction('adminer_object')]`.

So when reading a report: a 0% file is not always unexecuted machinery. Check what names it first —
`#[CoversFunction('the_function')]` is what credits a file that declares functions rather than a
class.


Not just what the report *labels*. PHPUnit restricts a test's coverage to the classes its metadata
names — so a class a test genuinely exercises, but does not declare, contributes **nothing** to that
class's numbers.

That is easy to lose an hour to, because the test passes, the code demonstrably runs, and the report
says zero:

```php
#[CoversClass(AbstractAdapter::class)]
#[CoversClass(FileAdapter::class)]
#[CoversClass(RedisAdapter::class)]     // leave this out and the Redis rows credit nothing
class StructuredOperationParityTest extends TestCase
```

That class asserts that the fallback adapters and the native Redis one behave identically.
Without the third attribute its Redis rows still pass and `hashSet()` provably reaches the
server, yet `RedisAdapter` reads zero when the class runs on its own — and a different,
higher number in a full run, because *other* tests declare `RedisAdapter` and contribute
their own lines. Neither number is a measurement fault. If a file looks less covered than
the tests suggest, check the attributes before you go looking for a flake.

**Add the attribute only where the class really is part of the test's subject.** The parity test *is*
about all three adapters, so declaring all three is accurate. `PageCacheEdgesTest` merely uses a
Redis adapter as a collaborator — declaring it there would misstate what the test is about and
credit `RedisAdapter` with lines nothing in it asserts on. A coverage number inflated that way is
worse than one that is honestly low.

## Read a coverage report by 0% first, not by percentage

A file at **0%** is a different kind of signal from a file at 70%, and it is worth acting on
before anything else: it means the machinery is written, shipped, and has never been executed —
not by a test, and quite possibly not by anybody. Nothing about it has ever been observed, so it is
where defects wait.

Which makes the obvious ranking a trap. Sorting by *percentage* with a "files of at least N
statements" filter — the natural way to avoid noise from three-statement classes — hides exactly
these files, because they tend to be small: a reader, a writer and a service provider are 20 to 40
statements each.

So rank twice:

```bash
# 1. everything at zero, whatever its size
# 2. then the largest absolute gaps
```

Percentage is the least useful of the three orderings on its own: a 1,800-statement file at 86% has
more uncovered code than a 40-statement file at 0%, and the 40-statement file is the one where
something has never run.

## Reflection into a private method covers the algorithm, not the tool

The `route-list` tool's routes-file parser is tested through reflection:

```php
$routes = (new \ReflectionMethod(RouteListTool::class, 'parseRoutes'))
    ->invoke($tool, $contents);
```

That is a fine way to test a parser — the cases are pure string-in, array-out, and driving them
through the public surface would mean writing thirty files to disk. But none of those tests touches
`execute()`: finding the candidate files, reading them, stamping each route with the file it came
from, applying the filter while reading, and choosing between the two answer shapes. A report can
show the class well covered while the tool itself has never been called.

So the tool also has tests that go in through `execute()` against real files, with `projectRoot()`
overridden to a directory the test owns. They pin behaviour the parser tests cannot see:

- with routes on disk and no attribute controllers — **which is every console application, and the
  console is the only kernel that reaches this tool** — the answer is the keyed report, never the
  flat list;
- the keyed report is in discovery order (`app/routes.php`, `routes.php`, `routes/web.php`,
  `routes/api.php`, and within a file the order written), while only the combined answer is sorted
  by URI, because only it merges two sources with no natural order between them.

The rule this leaves: **test the private method for the algorithm and the public one for the wiring.**
If every test of a class reaches past its entry point, the entry point is untested no matter what the
percentage says — and a `protected` seam like `projectRoot()` exists so that the public test is cheap.
