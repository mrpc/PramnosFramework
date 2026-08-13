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

### 2. Every scaffolding test ran `composer update` and downloaded assets — **done, 133 s**

**The diagnosis in the first version of this page was wrong, and the way it was wrong is
the useful part.** It said: 61 tests at 1877 ms each, each scaffolding a project — several
hundred file writes, template rendering, JSON merges — so scaffold once per class and have
the read-only tests share the tree.

Two measurements killed it. First, a shared scaffold does not fit: those 61 tests use **42
distinct option-sets**, because what most of them assert is what a *different* set of
answers produces. Second, and decisively:

| | Time |
| --- | --- |
| `init` into an empty directory | 1.9 s |
| `cp -a` of the finished tree (2556 entries) | **0.078 s** |

Producing the files is **25× cheaper than the run that produces them**. So the scaffold was
never the cost. A profile said where the cost was:

```
 85%  php::usleep          ← runProcessWithSpinner, polling two child processes
 12%  php::file_get_contents (15 calls)   ← downloading library assets, over the network
< 3%  everything that writes a file
```

`init` runs **`composer update` and `composer dump-autoload`** as real subprocesses, and
**downloads front-end assets over HTTP**. Every one of the 61 tests did both. So the class
was not merely slow: a unit-test suite depended on the network and on composer resolving a
throwaway `composer.json` — which is also why individual timings wandered between runs.

**The fix is a flag that was missing anyway.** Scaffolding files and installing
dependencies are separate jobs, and wanting only the first is a normal thing to want: a CI
job that installs from its own lockfile, a machine with no network, a project whose
`vendor/` is committed. `init` now takes **`--no-install`**, reported rather than silent —
see the [Console Guide](Pramnos_Console_Guide.md). The tests pass `--no-install` and
`--no-download`, except where those steps are the subject: one test asserts the flag's own
behaviour, one asserts a dry run still *names* the commands it would run, and
`InitNoInstallTest` keeps one real install so the default is covered.

| Class | Before | After |
| --- | --- | --- |
| `InitCommandUnitTest` | 114.5 s | **0.56 s** |
| `InitCommandTest` | 19.0 s | **0.42 s** |
| `InitOverwriteGuardTest` | 5.3 s | **0.02 s** |

**136 s net**, after subtracting the 2.1 s of `InitNoInstallTest`, which is new — against an
estimate of 80–130 s for a change that would not have worked.

`InitSpaScaffoldingTest` (3.8 s / 51 tests) did not move, and that is the check on the
diagnosis: it already passed `--no-download` and never reached the composer branch, so there
was nothing there to save. What remains in the Init family is honest work:
`InitSpinnerTest` (3.1 s) tests the spinner's escalation by running a genuinely slow
command, and `InitNoInstallTest` installs once, on purpose, so the default path stays
covered.

**The lesson, since this is twice on one page.** Item 1's first fix was wrong for the same
reason as this diagnosis: both were reasoned from what the code *looks* like it spends time
on. A profile takes two minutes and would have said `usleep` and `file_get_contents` before
any of it was written.

### 3. The MySQL container was configured for crash durability — **done, 126 s**

The plan here was a base class: schema per class, data per test in a rolled-back
transaction. It is still the right plan, and it is now worth much less, because a profile
found something cheaper first.

**The clue was in the original table and nobody read it.** The same assertions, against
different engines:

| | Per test | |
| --- | --- | --- |
| `QueryBuilderMySQLTest` | **401 ms** | 92 tests |
| `QueryBuilderPostgreSQLTest` | 67 ms | 83 tests |
| `FrameworkMigrationsMySQLTest` | **1398 ms** | 50 tests |
| `FrameworkMigrationsPostgreSQLTest` | 269 ms | 59 tests |

Both classes in each pair do the same thing — `setUp()` drops and creates tables, the test
writes rows, `tearDown()` drops them. A 5–6× difference is not "MySQL is slower at SQL". So
the two engines were measured directly, in their own containers:

| | MySQL | PostgreSQL |
| --- | --- | --- |
| Connect | 2.3 ms | 5.1 ms |
| 2 × `DROP` + 2 × `CREATE TABLE` | 279.6 ms | 36.0 ms |
| 5 × `INSERT` | **77.0 ms** | 22.0 ms |
| Transaction + `ROLLBACK` | 7.5 ms | 0.6 ms |

15 ms per single-row `INSERT` is not query execution, it is **two `fsync` calls per
commit** — the InnoDB redo log and the binary log. The MySQL container was running with
`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, `log_bin=ON` and the doublewrite
buffer on: full crash durability, for a database whose entire purpose is to be dropped.
`dockertest --resetdb` drops it on request and every integration test creates its own
tables.

**The change is [`docker/mysql/my.cnf`](https://github.com/mrpc/PramnosFramework/blob/main/docker/mysql/my.cnf)
— no test was touched.**

| | Before | After |
| --- | --- | --- |
| 5 × `INSERT` | 77.0 ms | **2.1 ms** |
| 2 × `DROP` + 2 × `CREATE TABLE` | 279.6 ms | **112.8 ms** |
| Transaction + `ROLLBACK` | 7.5 ms | **1.1 ms** |

And in the suite, on the nine MySQL classes that cost more than five seconds:

| Class | Before | After |
| --- | --- | --- |
| `FrameworkMigrationsMySQLTest` | 69.9 s | **21.8 s** |
| `QueryBuilderMySQLTest` | 36.8 s | **16.7 s** |
| `TokenActionMySQLTest` | 20.4 s | **6.6 s** |
| `SchemaBuilderMySQLTest` | 13.9 s | **2.4 s** |
| `TwoFactorAuthServiceMySQLTest` | 31.7 s | **21.2 s** |
| *(nine classes, total)* | 223.2 s | **97.1 s** |

**126 s**, and all 685 MySQL tests still pass.

**Rebuild after pulling this**, or you keep the old settings and the old timings:

```bash
docker-compose build db && docker-compose up -d db
```

`dockertest` checks `innodb_flush_log_at_trx_commit` on every run and prints that command
if the container is still durable — a container built before the config existed passes
every test and is silently two minutes slower, which is precisely the kind of thing nobody
notices.

**Do not do this to a database you would miss.** The settings trade crash safety for
speed, which is only free because this data is disposable.

### 3b. Schema per class, rows per test — **in progress, 46 s from five classes**

`Pramnos\Framework\Testing\DatabaseTestCase` now implements the split, and the first three
classes are on it:

| Class | Before | After |
| --- | --- | --- |
| `QueryBuilderMySQLTest` | 16.8 s | **0.56 s** |
| `QueryBuilderPostgreSQLTest` | 5.1 s | **1.47 s** |
| `QueryBuilderTimescaleDBTest` | 5.1 s | **1.49 s** |

A subclass declares three things — the connection, the tables it owns, the DDL — and gets
the schema built in `setUpBeforeClass()`, the rows removed with `DELETE` in `setUp()`, and
the tables dropped after the class. `$this->db` is a connected handle per test.

**The auto-increment trap is real and it is worth knowing before you convert anything.**
Counters no longer restart between tests, and six join tests in the first class converted
began returning zero rows: the tag fixture wrote `product_id` values of `1`, `3` and `5`
because the products table had restarted at 1 every time. The comment above it even said
`// product 1 = Apple`. Looking the ids up by name is what those literals meant, so the fix
made the fixture correct rather than merely compatible — and the same fixture in the
PostgreSQL class had the same bug waiting.

`resetAutoIncrement()` exists for classes that genuinely assert on the sequence, and is off
by default: the reset costs about 9 ms per table against 0.11 ms for the `DELETE` alone.

#### Two classes that could not use the base class, and a new finding

`TokenTest` (15.8 s / 33 tests) and `UsersControllerTest` (10.65 s / 17 tests) reach the
database through `Factory::getDatabase()`, because that is what the code under test does.
`DatabaseTestCase` owns its own handle, so these two got the *pattern* by hand rather than
the base class — schema in `setUpBeforeClass()`, `DELETE` in `setUp()`.

That took `TokenTest` from 15.8 s to 6.0 s. The remaining 180 ms per test led somewhere
useful:

| Per call | |
| --- | --- |
| `Settings::clearSettings()` + `loadSettings()` | 0.01 ms |
| `Application::getInstance()` | 0.00 ms |
| Drop the singleton, reconnect | 0.45 ms |
| **`$db->cacheflush()`** | **84.77 ms** |

**`cacheflush()` costs 85 ms** — it is a file-cache directory scan, not a flag. `TokenTest`
called it once per test (2.8 s of its runtime) and `UsersControllerTest` called it **three**
times per test (255 ms per test, 4.3 s of its 10.65 s), in classes where no `query()` call
opts into the SQL cache at all — `$cache` defaults to `false`. It is a defence against
whatever an *earlier class* left in the cache, which one call per class handles.

| Class | Before | After |
| --- | --- | --- |
| `TokenTest` | 15.8 s | **3.19 s** |
| `UsersControllerTest` | 10.65 s | **0.50 s** |

Only four test files call `cacheflush()`, so this is not a sweeping win — but it is worth
knowing what the call costs before putting it in a `setUp()`.

**Still to convert**, from the current measurement — the classes over 5 s that are not about
DDL:

| Class | Now |
| --- | --- |
| `OrmRelationsMySQLTest` | 10.4 s |
| `ModelTest` | 9.5 s |
| `MessagingModelsMySQLTest` | 8.4 s |
| `TokenActionMySQLTest` | 8.0 s |
| `TwoFactorAuthTest` | 7.7 s |
| `RbacFunctionsCharacterizationTest` | 6.5 s |
| `MessagingModelsPostgreSQLTest` | 5.2 s |

`FrameworkMigrations{MySQL,PostgreSQL}Test` (17.7 s and 14.9 s) stay as they are: for them
the DDL **is** the subject.

### 4. A fixture that was not the problem, and a hash that was — **done, 78 s**

Two classes, and the item as originally written was half wrong. Again.

#### `MediaObjectTest` — 46.8 s / 86 tests

The study guessed "one fixture image being regenerated 86 times". The images are 10×10
JPEGs and cost nothing. Reading `setUp()` was enough:

```php
foreach (['usertokens', 'userstogroups', 'userdetails', 'users', 'usergroups'] as $t) {
    $this->db->query("DROP TABLE IF EXISTS `{$t}`");
}
User::setupDb();
// ... then DROP + CREATE for media and mediause
```

**Seven drops and seven creates per test**, for a class where not one of the 86 tests
asserts anything about the schema. They assert what `MediaObject` does with rows and files.

The schema moved to `setUpBeforeClass()`; `setUp()` now empties the tables. Which raised a
question worth measuring rather than assuming:

| Per cycle, two tables | |
| --- | --- |
| `DROP` + `CREATE` | 128.6 ms |
| `TRUNCATE` | **159.5 ms** |
| `DELETE` + `ALTER … AUTO_INCREMENT = 1` | 18.7 ms |
| `DELETE` | **0.22 ms** |

**`TRUNCATE` is slower than dropping and recreating the table** — it is an implicit DDL
statement, not the fast path it looks like. And the auto-increment reset costs 18 ms of the
18.7, which this class does not need: every id assertion in it is `assertGreaterThan(0)` or
a comparison against another id, never a literal `1`. So: plain `DELETE`.

**46.8 s → 7.2 s.** Same 86 tests, same 223 assertions.

The drop-and-recreate with `FOREIGN_KEY_CHECKS = 0` was kept in `setUpBeforeClass()`,
because the reason it was written still holds: another class may have dropped `users`
before this one runs, and InnoDB then refuses to create a table whose foreign key points at
a table that is not there. Classes run sequentially, so once per class is as safe as 86
times.

#### `TwoFactorAuthService{,MySQL,PostgreSQL}Test` — 46.8 s combined

This half of the item was right: bcrypt. On PHP 8.5, `PASSWORD_DEFAULT` is bcrypt at cost
12 — **142.9 ms per hash** — and enabling 2FA hashes **ten** backup codes, so one call cost
1.43 s. That is exactly the runtime of `testCompleteSetupInsertsNewRowOnSuccess`.

```
cost  4:   0.71 ms
cost  8:   9.05 ms
cost 12: 142.9 ms   ← the default
```

The framework's three `password_hash()` call sites now go through
`Pramnos\Auth\PasswordHash::make()`, which reads `PRAMNOS_BCRYPT_COST` and otherwise
behaves exactly as the bare call it replaced. `tests/bootstrap.php` sets `4`.

| Class | Before | After |
| --- | --- | --- |
| `TwoFactorAuthServiceMySQLTest` | 21.2 s | **4.2 s** |
| `TwoFactorAuthServicePostgreSQLTest` | 21.3 s | **4.2 s** |
| `TwoFactorAuthServiceTest` | 4.3 s | **0.03 s** |

**The algorithm under test does not change** — cost is a bcrypt parameter, so a hash made
at cost 4 is verified by the same `password_verify()` that ships. `PasswordHashTest` asserts
that, and asserts that every invalid cost falls back to the default rather than to something
cheap: a typo in an environment variable must not be able to weaken a deployment.

**78 s**, against an estimate of 40–80.

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
| 2. ~~Scaffold once per class~~ — **done**, and by a different change: `--no-install` | **136 s** |
| 3. ~~Schema per class + transaction per test~~ — **done**, by fixing the container instead | **126 s** |
| 3b. Schema per class + rows per test | **46 s** so far, five classes of twelve |
| 4. ~~Fixture and hash cost in two classes~~ — **done**; the fixture was never the cost | **78 s** |

**442 s delivered so far** — 56 + 136 + 126 + 78 + 46 — without removing a single test, a
single database or the coverage report.

The full run with coverage went **17:02 → 7:46**: 556 s, more than the 442 s measured
without it. Coverage instrumentation is a multiplier on work done, so removing work removes
its share of the instrumentation too — the 12% figure at the top of this page is 12% of
whatever the suite still does.

Two of those three came from a profiler contradicting a written plan. Items 3b and 4 are
still estimates, and should be treated as such until something measures them.

## How to measure it again

```bash
./dockertest --no-coverage --log-junit /var/www/html/var/junit.xml
```

Then read the XML: total per suite, time per class, and the distribution above. A change
that does not move the "≥ 1000 ms" row has not moved the suite.

And when a class is slow, **profile it before writing the plan**. Twice on this page a
plausible diagnosis was wrong, and both times two minutes of measurement said so:

```bash
docker-compose exec php-apache-environment sh -c \
  'mkdir -p /tmp/prof && php -d xdebug.mode=profile -d xdebug.output_dir=/tmp/prof \
   -d xdebug.start_with_request=yes your-script.php'
```

The third item did not need a profiler at all — only reading the table that was already on
this page, where the same tests were 5× slower on one engine than another.
