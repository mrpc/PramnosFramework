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

> **Where it ended up**, for a reader arriving at this page later: `--no-coverage` is now
> **4:01** and the default run **6:58**, for 9398 tests. Every number in the
> sections below is the measurement that was true when that item was worked on; the totals at
> the end are current.
>
> **Checked again 2026-08-16: 6:55 and 7:11 for the same 9568 tests**, two runs one
> test-only commit apart. 170 tests added since 6:58/9398, and the difference between
> the two runs is larger than the difference from the baseline.
>
> **Final measurement, 2026-08-17: `--nocoverage` is 3:44 and 3:46 for 9750 tests** — two
> consecutive runs, 211.9 s and 214.1 s of measured test time. That is 182 tests more than the
> 6:55/7:11 check above and roughly half the wall clock, which is item 3b and item 4 landing.
> These two runs happen to agree to within 2 s; do not read that as the spread having narrowed,
> because two samples cannot say so. The ±15 s figure above is the one to plan against.
>
> That is the number worth carrying: **run-to-run spread here is ±15 s**, so a single
> measurement cannot tell you whether anything changed. The first draft of this note
> recorded 7:11 alone and read "+13 s" off it as though it meant something — the same
> mistake this page documents four times below. Compare ranges, or compare nothing.
> A real regression will not announce itself either; it will look like the suite
> having grown.

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

### And the same distribution after the work

Measured the same way, at the end of the items below (228.3 s of measured test time):

| Threshold | Tests | Share of count | Time | Share of time |
| --- | --- | --- | --- | --- |
| ≥ 1000 ms | 19 | 0.2% | 27.2 s | 11.9% |
| ≥ 500 ms | 66 | 0.7% | 57.8 s | 25.3% |
| ≥ 100 ms | 625 | 6.9% | 171.4 s | **75.0%** |
| ≥ 50 ms | 1147 | 12.7% | 210.2 s | 92.1% |

| | Time | Tests | Per test |
| --- | --- | --- | --- |
| `tests/Integration` | 126.9 s (55.6%) | 1161 | **109 ms** |
| `tests/Unit` | 63.9 s (28.0%) | 7436 | 9 ms |
| `tests/Characterization` | 38.9 s (17.0%) | 801 | 49 ms |

**The shape of the problem has inverted.** It began concentrated — 203 tests were 46% of the
run — and is now shallow and wide: the `≥ 1000 ms` bucket is down from 405.7 s to 27.2 s, and
what remains sits in 625 tests at ≥ 100 ms. `tests/Unit` went from 60 ms per test to 9 ms.
There is no longer a small set of classes to fix, which is what makes the parallelism question
below worth answering with numbers.

Also worth noting: **wall clock is now 4:01 against 228.3 s of measured test time** — about
13 s of everything else. There is no meaningful fixed overhead left to remove.

`tests/Characterization` is the one directory this work never touched: 17% of the time for
8.5% of the tests.

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

### 3b. Schema per class, rows per test — **done, 81 s from ten classes**

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

#### The rest of the conversions, and one that had to be undone

| Class | Before | After |
| --- | --- | --- |
| `OrmRelationsMySQLTest` | 10.20 s | **0.91 s** |
| `ModelTest` | 9.05 s | **2.27 s** |
| `MessagingModelsMySQLTest` | 8.38 s | **1.38 s** |
| `TokenActionMySQLTest` | 8.06 s | **1.02 s** |
| `TwoFactorAuthTest` | 5.57 s | **0.90 s** |

`MessagingModelsMySQLTest` and `TokenActionMySQLTest` were rebuilding their schema by
**running the framework migrations on every test**. `TwoFactorAuthTest` called
`User::setupDb()` and three migrations per test. `OrmRelationsMySQLTest` dropped and created
six tables per test. In every case the class asserts what a *model* or a *controller* does.

**`MessagingModelsPostgreSQLTest` was converted and then reverted.** The identical change
that took its MySQL sibling from 8.38 s to 1.38 s made the PostgreSQL class **slower**:

| | Before | After the conversion |
| --- | --- | --- |
| `MessagingModelsPostgreSQLTest` | 5.71 s | **7.34 s** |

Measured twice, reproducible, and reverted. The cause was not chased further — what matters
for anyone repeating this work is the rule it implies: **the conversion pays where DDL is
expensive, and PostgreSQL DDL is not.** The engine comparison at the top of item 3 already
said so — 36 ms against 279.6 ms for the same drop-and-create — so on PostgreSQL the per-class
machinery can cost more than the DDL it avoids. `RbacFunctionsCharacterizationTest` (5.95 s,
also PostgreSQL) was left alone on the same evidence rather than converted and measured, which
is a judgement call and marked here as one.

**Left as they are on purpose:** `FrameworkMigrations{MySQL,PostgreSQL}Test` (17.7 s and
14.9 s). For them the DDL **is** the subject.

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
| 3b. Schema per class + rows per test | **81 s**, ten classes |
| 4. ~~Fixture and hash cost in two classes~~ — **done**; the fixture was never the cost | **78 s** |
| 5. `tests/Characterization` — one class converted, two order dependencies fixed | **4 s**, and all three suites run alone |

**481 s delivered** — 56 + 136 + 126 + 78 + 81 + 4 — without removing a single test, a single
database or the coverage report.

The full run with coverage went **17:02 → 6:58**: 604 s, more than the 477 s measured
without it. Coverage instrumentation is a multiplier on work done, so removing work removes
its share of the instrumentation too — the 12% figure at the top of this page is 12% of
whatever the suite still does.

Two of those three came from a profiler contradicting a written plan. Items 3b and 4 are
still estimates, and should be treated as such until something measures them.

### 5. `tests/Characterization` — the directory nobody had looked at

17% of the run for 8.5% of the tests, and untouched by everything above. Measured on its own:
**36.2 s across 55 classes**, of which the remaining 45 classes are 8.1 s for 681 tests —
12 ms each, already fine. The cost is in the top ten.

| Class | Before | After |
| --- | --- | --- |
| `UserTokenManagementCharacterizationTest` | 5.14 s | **1.31 s** |

It dropped five tables and ran `User::setupDb()` per test, while its `tearDown()` already
cleaned up by row. Schema moved to `setUpBeforeClass()`; nothing else changed.

**`UserAdminCreationMySQLCharacterizationTest` (4.01 s / 5 tests, the worst per-test in the
directory) was deliberately left alone.** It asserts on generated key *values* — that the
first admin lands on `userid = 1`, that a scaffolded admin gets `userid = 2` because 1 is
reserved for the anonymous identity. The schema and its `AUTO_INCREMENT` behaviour are the
subject, which is the documented "when not to use this" case. Converting it would have meant
resetting the counter per test, at which point most of the saving is gone anyway.

#### And a suite that only passed in one order

Running `./dockertest --testsuite 'Characterization Tests'` on its own **failed four tests**
in `ApikeyCharacterizationTest`, while the full run passed. Pre-existing — confirmed by
checking out the pre-work version of `tests/` and reproducing it.

The cause is a trap already described in another class's comments, met from the other side:

```php
$this->db->query('CREATE TABLE IF NOT EXISTS `applications` (...)');
```

`applications` is a **shared table name** — several classes create their own version with
different columns. `IF NOT EXISTS` keeps whichever schema arrived first, and this class then
inserts into a table missing the columns it needs. In a full run something else had already
left the table in a shape these tests could live with; alone, nothing had.

Fixed by dropping before creating, so the class always gets its own schema. **A suite that
only passes in one order is a suite nobody can bisect**, which makes this worth more than the
time it saves.

#### So the same question was asked of every suite

If one suite could not run alone, the others were worth checking:

```bash
./dockertest --nocoverage --testsuite 'Unit Tests'
./dockertest --nocoverage --testsuite 'Integration Tests'
./dockertest --nocoverage --testsuite 'Characterization Tests'
```

`Integration Tests` failed too — **all seven tests of `QueueControllerMySQLTest`**, with
`RuntimeException: No such file or directory` from a MySQL connect that had been given no
host at all.

The cause is worth knowing, because the shape recurs. `parent::setUp()` boots the
application, which builds the Factory's **database singleton before this class loads the
fixture settings**. The cached handle points at nothing, and mysqli falls back to a socket
path it was never given — hence a filesystem error from a database call. The class passed for
as long as some earlier class had already built a correct singleton.

```php
Settings::loadSettings($settingsFile);

$singleton = &Factory::getDatabase();   // discard what parent::setUp() built
$singleton = null;

$this->db = Factory::getDatabase();
```

Every other class in the suite that boots this way already did exactly that. This one had
simply never needed to.

**All three testsuites now pass on their own**, which is the state to keep them in: the point
of running one is to narrow down a failure, and narrowing must not change the answer.

*(A first attempt blamed a missing `CONFIG` constant and was wrong — the constant made no
difference, and the guard was removed again rather than left in as noise.)*

### 6. `FrameworkMigrations*` — measured, and there is no room

The three migration classes are the largest left: 17.5 s, 15.0 s and 4.7 s, **37.2 s
between them**. Their DDL is the subject, but that only settles *what* they build, not how
many times. So it was profiled rather than assumed.

The profile of two tests looked like an obvious win:

```
 59%  dropAllTestTables()      ← 4 calls (setUp + tearDown, per test)
 24%  the migrations under test (up() / down())
```

`dropAllTestTables()` issues about **82 `DROP` statements** — every table, view and trigger
the framework schema could contain — twice per test, to clean up after a test that creates
one table. A no-op `DROP` measures 0.2 ms and one `information_schema` query listing
everything measures 0.35 ms, so replacing 82 blind drops with "drop exactly what this test
created" looked like it should be most of that 59%.

It was implemented, and it works, and it is **stricter** than the blanket list — it catches
tables the hand-maintained list has never heard of. Then it was measured:

| | Run 1 | Run 2 | Average |
| --- | --- | --- | --- |
| Snapshot-and-diff | 16.98 s | 15.40 s | 16.2 s |
| Unchanged | 16.99 s | 16.92 s | 17.0 s |

**Within noise.** Single runs during development had ranged from 15.5 s to 20.1 s for the
*same* code, which is the width of the effect being chased.

**Reverted.** These are the most delicate tests in the suite — characterization of what every
framework migration builds — and adding a schema-diffing teardown to them for a change that
measurement cannot distinguish from noise is a bad trade. The likely explanation is that
`information_schema` against a database holding the entire framework schema costs about what
the 82 drops cost, so the work moved rather than disappeared.

**This is the fifth time on this page that a written hypothesis lost to a measurement**, and
the only one where the answer was "do nothing". That is a result too: 37.2 s of the remaining
run is now known to be genuine work rather than unexamined overhead.

## Schema introspection: already cached where it is safe to cache

Measured at **0.590 ms** per `SHOW COLUMNS`, which looked like an obvious target: a
request touching three tables pays ~1.8 ms to ask the database about columns that only
change when a migration runs.

Reading the code before building anything found that half of it was already done, and
the other half declined on purpose:

| Call site | `$cache` |
| --- | --- |
| `Model::_getTableFields()` and the other read paths | **`true`**, 3600 s |
| `Model::_save()` | **`false`** |

The read paths already introspect through the query cache, so a listing pays once an
hour rather than once a request. The 0.590 ms is a cold call.

**The write path is uncached deliberately, and the asymmetry is the whole design.** A
stale schema on a read path costs a column missing from a list: visible, harmless, fixed
by waiting. A stale schema in `_save()` means the loop never sees a newly added column,
so every row is written without it — silent data loss, found later, unrecoverable.

So there is nothing to build here, and the item is closed by writing the reason down
next to the `false` rather than by adding a second caching layer in front of it. Anybody
arriving at this measurement again will find the answer where they are already looking.

## 7 — The remaining conversions, measured and declined

The standing plan was: finish the `DatabaseTestCase` conversions, then re-ask the parallelism
question. Both halves are answered below, and the first one is answered **no**.

Fifteen classes still do DDL inside `setUp()` rather than `setUpBeforeClass()` — the shape item
3b converted ten of, for 81 s. So the remainder looked like more of the same. It is not, and the
arithmetic is the entire argument:

| Class | Time | Tests | ms/test |
| --- | --- | --- | --- |
| `SchemaMigrationsCharacterizationTest` | 2.84 s | 11 | 257.9 |
| `UserAdminCreationMySQLCharacterizationTest` | 2.34 s | 5 | 467.0 |
| `MigrationRunnerMySQLTest` | 1.88 s | 15 | 125.4 |
| `ApplicationAutoMigrationsMySQLTest` | 1.06 s | 12 | 88.3 |
| `TriggerSequenceMySQLTest` | 0.86 s | 8 | 108.0 |
| `UserAdminCreationPostgreSQLCharacterizationTest` | 0.76 s | 4 | 189.3 |
| `CTEMySQLTest` | 0.53 s | 5 | 105.9 |
| **Total** | **10.26 s** | | |

**10.26 s out of 211.9 s of measured test time — 4.8%.** At the reduction item 3b actually
achieved (8.38 s → 1.38 s, about 83%), converting all seven perfectly saves roughly **8.5 s**.

The two runs recorded below differ from each other by 2.2 s of measured time and 2 s of wall
clock, and the spread this page recorded earlier was ±15 s. Either way the whole remaining
programme is at or under the noise it would have to be measured in: **there is no experiment
that could show it worked.** Item 3b was worth doing because it moved 81 s. This is not that
work at a smaller scale — it is work whose result is indistinguishable from doing nothing.

Two further reasons, both already established above:

- **Three of the seven are PostgreSQL**, where this conversion has been measured making things
  *slower*: `MessagingModelsPostgreSQLTest` went 5.71 s → 7.34 s and was reverted. PostgreSQL
  DDL costs 36 ms against MySQL's 279.6 ms, so the per-class machinery can exceed the DDL it
  avoids.
- **Four of the seven are migration tests**, and for migrations the DDL *is* the subject.

The groundwork keeps the value item 3b claimed for it — one place per class that says where its
schema lives — and is worth continuing when a class is touched for other reasons. As a
performance programme it is finished.

### Two other candidates, examined and left

`InitSpinnerTest` (3.21 s / 5 tests / 641 ms per test) spends 3 s of that inside `sleep 2` and
`sleep 1` in the commands it drives. That is the shape of item 1, where seven tests waited
8.00 s each — except here the waiting is the subject: the spinner polls every 100 ms and
escalates on an **integer-second** threshold, so a command must genuinely exceed one second for
the escalation path to exist. The compressible part is about 1.4 s, 0.6% of the run, in exchange
for tightening a timing-dependent test. A flaky spinner test costs more than 1.4 s the first
time it fails in CI.

`TestEnvironmentTest` (6.20 s / 16 tests) creates and drops real databases on two engines and
shells out for binary discovery. That is what it is for.

## Is `paratest` the next step? Asked again, and the answer has changed

The original page said parallelism was the right *second* lever, then said "not yet — finish the
cheaper work first". **That answer expired**, because item 7 measured the cheaper work and found
it worth 8.5 s. Parallelism is now the only remaining lever of any size. The question is no
longer *whether something cheaper exists* but *whether the prize justifies the cost*, and both
are stated below with current numbers.

### What it would buy

Two runs, one after the other, on the same tree:

| Run | Wall clock | Measured test time | Classes | Tests ≥ 1000 ms |
| --- | --- | --- | --- | --- |
| A | 3:44 | 211.9 s | 584 | 19 (28.8 s) |
| B | 3:46 | 214.1 s | 584 | 21 (31.5 s) |

Modelling longest-processing-time bin-packing by class, on this 10-core machine (run A):

| Workers | Makespan | Speed-up |
| --- | --- | --- |
| 2 | 105.9 s | 2.00× |
| 4 | 53.0 s | 4.00× |
| 8 | **26.5 s** | 8.00× |
| 12 | 19.8 s | 10.68× |

Scaling is ideal to 8 workers — no class is large enough to become the tail. The ceiling is
**10.7×**, set by `FrameworkMigrationsPostgreSQLTest` at 19.8 s, which stays as it is because
its DDL is the subject. In run B the ceiling reads 12.7×, because which migration class is
largest swaps between runs; that difference is measurement noise and not a change.

Non-test wall clock is **about 12 s** (225 s wall against 213 s measured). So at 8 workers a
`--nocoverage` run of **3:45 plausibly becomes 45–60 s** once per-worker overhead is added back
— roughly **three minutes saved per run**.

### What it would cost

**Every worker needs its own database.** This is not a configuration flag, it is the whole
job. The integration classes share one `pramnos_test` and, worse, share *table names* —
`users`, `usertokens`, `applications`, `messages` and more are dropped and created by several
classes each. Two workers running concurrently would drop each other's tables, and the
failures would be non-deterministic.

Concretely:

1. Read paratest's `TEST_TOKEN` and derive a database name per worker.
2. Create those databases up front, on **three** engines — MySQL, PostgreSQL and TimescaleDB.
3. Route every connection through it. `tests/fixtures/app/settings.php` is one place;
   **38 test files name `pramnos_test` themselves** and would each need to go through a helper.
4. Merge coverage across workers.

Step 3 is the real work, and `DatabaseTestCase` has already made it smaller: classes on the
base class declare their connection in one method, so a single change to
`connectionConfig()`'s default would carry all of them.

### The recommendation

**It is the only lever left, and it is still not obviously worth pulling. Decide on CI, not on
a developer's patience.**

The trade, stated plainly:

- **Prize:** about three minutes per run, 3:45 → roughly 50 s.
- **Cost:** per-worker databases on three engines, and the 38 test files that name `pramnos_test`
  themselves routed through a helper. Permanent, and it buys a suite that is harder to reason
  about — a failure appearing only under a particular worker split is a bad afternoon, and it
  arrives long after the change that caused it.

Which way that goes depends on who is waiting:

- **For a developer's local loop, no.** 3:45 is inside the span where you look at the diff
  rather than leave the desk, and `--filter` already answers the fast-feedback case in under a
  second. Buying three minutes with permanent cross-worker state isolation is a poor trade when
  the thing being bought is not the binding constraint.
- **For CI, plausibly yes**, and that is a different calculation: it is minutes × runs × people,
  it is money rather than patience, and a flake under a worker split is caught by a rerun rather
  than by somebody's afternoon.

So: **not on the strength of the local number. Do it when CI wall clock is what hurts**, and do
step 3 first — routing every connection through one helper — because that step is worth having
on its own and is the one that makes the rest mechanical.

What is explicitly **not** the answer any more: "finish the cheaper work first". Item 7 measured
the cheaper work. There is 8.5 s in it, and the suite's own run-to-run spread is larger than
that.

### Checked again 2026-08-24: 5:14 for 10,570 tests, and one real regression

Asked directly: *had the last few days' work undone any of this?* Measured rather
than reasoned, the same way this page says to.

| | 2026-08-17 | 2026-08-24 |
| --- | --- | --- |
| `--no-coverage` wall clock | 3:44 – 3:46 | **5:14** |
| tests | 9,750 | 10,570 |
| measured test time | 212 – 214 s | **290.6 s** |
| ≥ 1000 ms | 19 tests / 28.8 s | **68 tests / 96.3 s** |
| `tests/Unit` per test | 7.9 ms | 11.0 ms |
| `tests/Integration` per test | 103.6 ms | 120.8 ms |
| `tests/Characterization` per test | 35.2 ms | 52.7 ms |

**One genuine regression, found and fixed.** `Database::maskInertSql()` — the
comment-aware placeholder scanner added on 2026-08-24 — walks the statement one
byte at a time in PHP, and it replaced two quote-only regexes. Measured on
ordinary statements:

| Statement | scanner | the regexes it replaced |
| --- | --- | --- |
| 41 bytes | 21.9 µs | 0.26 µs |
| 103 bytes | 50.6 µs | 0.38 µs |
| 150 bytes | 74.1 µs | 0.31 µs |

**150–240× on a path that runs for every prepared statement the framework
issues**, in production as much as in the suite. Fixed with a fast path: with no
comment opener present the only inert regions are string literals, which one
regex finds exactly — so the walk is paid only by the statements that actually
contain a comment. 22–74 µs became 1.3–1.7 µs, of which ~1 µs is the reflection
call the benchmark used.

That is the kind of thing this page exists to catch, and it would not have shown
up in the suite total: at ~50 µs a statement it is a couple of seconds across
the whole run, and invisible against a ±15 s spread. It was worth finding for
the request path, not for the tests.

**The rest of the growth is not from those days.** Of the 290.6 s, files added or
touched since 2026-08-23 account for **26.9 s (9.2%)** across 424 tests. The
`≥ 1000 ms` band grew from 19 tests to 68, and the classes in it are almost
entirely older:

| Class | Tests in band | Time | Age |
| --- | --- | --- | --- |
| `TwoFactorAuthServiceMySQLTest` | 17 | 24.7 s | May 2026 |
| `MessagingModelsPostgreSQLTest` | 11 | 15.7 s | pre-existing |
| `FrameworkMigrations*` | 8 | 11.2 s | supposed to be slow |
| `PostgresIntrospectionFindsKeysTest` | 2 | 2.3 s | **new** |
| `DevPanelControllerTest` | 1 | 1.1 s | **new attribute** |

**`TwoFactorAuthServiceMySQLTest` is the largest single class in the suite** —
17 tests at ~1.45 s each, dropping and creating three tables in `setUp`. That is
exactly the shape [item 3b](#3b-schema-per-class-rows-per-test--done-81-s-from-ten-classes)
fixed for ten other classes, and this one was not among them. Same for
`MessagingModelsPostgreSQLTest`. Together they are **40 s** — the single largest
remaining item on this page, and it is the one item 3b already knows how to fix.

**Process isolation.** The 2026-08-24 work added `RunTestsInSeparateProcesses` or
`RunInSeparateProcess` covering 88 tests, 48 of which were not isolated before.
Measured on the worst case, `PostgresIntrospectionFindsKeysTest`: 10.09 s
isolated against 8.77 s not — **1.3 s for 10 tests**, so ~130 ms per isolated
test. Real, and cheap next to what it buys: those attributes are what stopped a
`define('DEVELOPMENT', true)` in one test file from deciding the whole run's
behaviour, and what stops a MySQL class inheriting a PostgreSQL connection under
a filter. The remaining 8.8 s of that class is its own PostgreSQL DDL.

**The new integration tests are the honest cost.** `ClientPoolTest`,
`ClientTransportTest` and `ClientBodyCeilingTest` fork a real socket server per
test and contain 1.75 s of deliberate `usleep` — because concurrency and timing
are only observable in time. 3.8 s of measured test time for 54 tests that
verify wire behaviour nothing else covered.

**Declined:** `getColumns()`'s new `$fresh` flag bypasses the cache for code
generators. Measured at 0.59 ms uncached against 0.05 ms cached — 0.54 ms per
generator introspection, on the CLI path only. It buys a generator that reads the
schema its migration just wrote, which is the same reasoning as
[the write path being uncached](#schema-introspection-already-cached-where-it-is-safe-to-cache).

### 8 — The two classes item 3b never reached, and what was underneath one of them

Named in the 2026-08-24 check above as the largest remaining item: 40 s across two
classes that rebuilt their schema in every `setUp()`. Both are now on the
schema-per-class pattern, and the second one led somewhere more interesting than
the first.

| Class | Before | After |
| --- | --- | --- |
| `TwoFactorAuthServiceMySQLTest` | 24.7 s / 17 tests | **3.3 s** |
| `MessagingModelsPostgreSQLTest` | 22.2 s / 11 tests | **17.5 s** |

Neither can use `DatabaseTestCase`, for the reason `TokenTest` could not: the code
under test reaches the database through `Database::getInstance()`, so the test has
to use that instance rather than a handle of its own. Both got the pattern by hand
— schema in `setUpBeforeClass()`, `DELETE` in `setUp()`, drop after the class.

The auto-increment trap was checked rather than assumed in both. Neither class
asserts on a generated id's *value*: every assertion is a row count, an
`assertNotEmpty()` on a key, or a comparison against an id the same test captured.

**The second class barely moved, and that is the finding.** 22.2 s to 17.5 s for a
change that took the first class from 24.7 s to 3.3 s. Profiling rather than
guessing — which this page has now recommended three times and been right about
three times:

| Per test | |
| --- | --- |
| `setUp()` in total (settings, application, five `DELETE`s) | **3.3 ms** |
| every test, including ones that assert almost nothing | **1.0 – 1.9 s** |

The cost is not in the fixture. It is in the saves.

#### `cacheflush()` is O(keyspace), and every save pays for one

| | |
| --- | --- |
| `Settings::getSetting('cache')` | 0.00 ms |
| `Cache::getInstance()` (same category) | 0.32 ms |
| **`$cache->clear('mails')`** | **268 ms** |
| `$db->cacheflush('mails')` | 293 ms |

Measured with the file cache directory **empty**, so it is not a directory walk.
The configured method is `false`, which resolves to Redis, and
`RedisAdapter::clear($category)` deletes by pattern. The pattern is already
narrow — `prefix + category_*` — but `SCAN` with a `MATCH` **still traverses the
whole keyspace**: `MATCH` filters what is returned, not what is walked. So
clearing one category costs the same as clearing all of them.

Counted against the code rather than recalled — an earlier revision of this
section said `_save()` called it twice, and it does not:

| `Model` path | `cacheflush()` calls |
| --- | --- |
| insert (`_save`, line 413) | 1 — the category |
| update (`_save`, lines 430/433) | 1 — the record's key, or the category as fallback |
| delete (`_delete`, lines 595–596) | **2** — the record's key *and* the category |

So **every save is one full Redis traversal and every delete is two**. That is the
1.3 s per test above, and it is not a test problem — it is what every write costs
in production. The multiplier is smaller than first written; the per-call number
is not, and it is the per-call number that makes this worth fixing.

This page has met this number before. Item 3b measured `cacheflush()` at **85 ms**
and removed the calls from two test classes that did not need them. It is 268 ms
now, and this time the calls are inside `Model` where no test can remove them.

**Not fixed here, deliberately.** The repair is a different invalidation design —
a Redis SET per category holding its own keys, so a flush is `SMEMBERS` + `DEL`
and costs the size of the category rather than of the database. That is a
correctness-sensitive change to the cache layer and it wants its own work, its own
measurements and its own tests. Written down here because the measurement is the
expensive part and it is now done.

Worth knowing before picking it up: this only started costing anything when the
SQL cache began working at all. `Cache::getInstance()`'s method default went from
`'memcached'` — a store nobody configured, so every cache call was a silent no-op
— to `''`, which resolves to the configured store. A consuming project's suite
found the same change from the other side, as four tests that suddenly served
stale rows.

### Where the time actually is now, for anyone re-opening this

| Threshold | Tests | Time | Share |
| --- | --- | --- | --- |
| ≥ 1000 ms | 19 | 28.8 s | 13.6% |
| 500–999 ms | 43 | 28.1 s | 13.2% |
| 100–499 ms | **525** | **100.0 s** | **47.2%** |
| 50–99 ms | 510 | 36.8 s | 17.3% |
| < 50 ms | 8653 | 18.2 s | 8.6% |

| Directory | Time | Tests | Per test | Was |
| --- | --- | --- | --- | --- |
| `tests/Integration` | 123.6 s (58%) | 1193 | 103.6 ms | 303 ms |
| `tests/Unit` | 59.7 s (28%) | 7603 | **7.9 ms** | 60 ms |
| `tests/Characterization` | 28.2 s (13%) | 801 | 35.2 ms | 84 ms |

The shape has inverted since this page was written. Then, **203 tests were 46% of the run** and
the work was to find them. Now the largest band is 525 tests averaging 190 ms, and the 19 tests
over a second are 13.6% — mostly the migration classes that are supposed to be slow. There is no
target left, which is the same finding as item 7 from the other direction.

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
