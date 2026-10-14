---
use_cases:
  - Writing a test that will not slow the suite down
  - Finding out why the test suite takes as long as it does
  - Deciding what to change to make ./dockertest faster
---

# Keeping the test suite fast

The suite runs against MySQL, PostgreSQL and TimescaleDB. In the measurements below, the
default `./dockertest` took roughly three times as long as a `--nocoverage` run, and the
difference is Xdebug instrumenting every line of `src/`. Everything below is what keeps the
plain run short, and what the coverage run's extra time is for.

## Measure before you plan

```bash
./dockertest --no-coverage --log-junit /var/www/html/var/junit.xml
```

Read the XML: total per suite, time per class, and the distribution. **A change that does not
move the "≥ 1000 ms" row has not moved the suite.**

When one class is slow, profile it before writing the plan:

```bash
docker-compose exec php-apache-environment sh -c \
  'mkdir -p /tmp/prof && php -d xdebug.mode=profile -d xdebug.output_dir=/tmp/prof \
   -d xdebug.start_with_request=yes your-script.php'
```

That is not a formality. Every large saving on this suite came from a measurement
contradicting a plausible written diagnosis — a fixture blamed for a cost that was in
password hashing, a schema rebuild blamed for a cost that was in the database container's
durability settings, a "slow ORM" that was one `clear()` walking the whole cache per save.

## One wall-clock reading is not a measurement

The band on a developer machine is wider than most regressions you are looking for. Four runs of the
same commit, minutes apart, nothing else changed:

```
3:20    ← "a 44-second regression"
3:11    ← the same, with the new tests removed
2:38
2:36
```

The first reading was taken straight after a `--coverage` run, whose HTML report writes thousands of
files; the machine was still catching up. Acting on it would have meant reverting a test file that
costs **one second**, and the control run — the same suite with the new tests moved out of the tree —
would have "confirmed" the regression, because it was still inside the same noisy window.

So before you believe a slowdown:

- **Run it twice more.** Two readings that agree are a measurement; one is a reading.
- **Never compare across a coverage run.** `--coverage` leaves the disk busy; give it a plain run in
  between or the next number is about the report, not the suite.
- **A control run is only worth taking once the band is known.** Removing the suspect file and
  getting a number inside the noise proves nothing either way.

Which is why the numbers quoted elsewhere in this guide are pairs, and why a real regression here
looks like `2:37 → 3:00` twice in a row rather than a single bad reading.

## A connection per test costs more than the thing you are measuring

Four tests, each opening its own PostgreSQL connection in `setUp()`, moved the suite from its
2:34–2:38 band to 2:42 twice over. The tests themselves are two 600ms back-off measurements and two
controls — about 1.2 seconds of deliberate sleeping — so four handshakes cost roughly as much again
as the behaviour under test.

Sharing one connection for the class brought it back to 2:38–2:40:

```php
private static ?Database $shared = null;

protected function setUp(): void
{
    if (self::$shared === null) {
        self::$shared = static::openConnection();
    }
    $this->connection = self::$shared;
}

public static function tearDownAfterClass(): void
{
    self::$shared?->close();
    self::$shared = null;
}
```

The condition for doing this is that **nothing in the class writes anything**. A class that inserts
rows needs its isolation and should pay per test; one that only reads, or only provokes errors, has
nothing to isolate and no reason to pay.

What remains — the last second or two — is the `usleep()` the code under test performs, and there is
no way to observe a back-off without waiting for it. Worth stating plainly in the commit rather than
rounding away: a retry loop that has never run is as likely to spin for ever as to work, and the
elapsed time is the only assertion that can tell those apart.

## The distribution is the finding

A suite's total is decided by a handful of classes. Sort by time and look at the top ten
before anything else: the middle of the list is noise, and a percentage saved across
thousands of fast tests is worth less than one class fixed.

## What makes a test slow here

| Cost | What to do instead |
| --- | --- |
| **A DNS lookup that must fail.** A hostname that does not resolve costs the connect timeout — seconds per test. | Assert on the DSN, or use an IP literal that cannot resolve. |
| **`composer update` or an asset download.** A scaffolding test that installs is minutes, not milliseconds. | `--no-install` / `--no-download`. Scaffolding is the subject; installing is not. |
| **Production-cost password hashing.** `password_hash()` at the production cost, per fixture user. | The lowest legal cost in tests, or one hash reused. |
| **A schema rebuilt per test.** | Schema per class, rows per test. |
| **A container tuned for crash durability.** `innodb_flush_log_at_trx_commit=1` and friends make every insert a disk sync. | The test database's own config; it is disposable by definition. |
| **A cache walked per write.** Invalidating by scanning is invisible until a test writes a thousand rows. | Invalidate by key or category. |
| **`sleep()` and real timeouts.** | Inject the clock, or assert on what would have been waited for. |

## Where the time is, measured

Per suite, in one dated measurement on 16,174 tests. Two readings of each, agreeing; the noise band above is why one
would not be enough.

| Suite | Tests | PHPUnit time | Per test |
| --- | --- | --- | --- |
| Unit | 12,258 | 1:32 | **7.5 ms** |
| Integration | 3,078 | 1:58 – 2:01 | **38 ms** |
| Characterization | — | 0:23 | — |

**Integration is the larger half on a quarter of the tests**, which is what a database lane
costs and is not by itself a fault. What would be a fault is one class inside it dominating,
and none does — the slowest is 7.1 s and the top twelve together are about a third of the
suite:

```
    7.1s   63 tests   FrameworkMigrationsPostgreSQLTest
    6.8s   50 tests   FrameworkMigrationsMySQLTest
    4.7s   16 tests   DatabaseAuthDriverPostgreSQLTest
    4.4s   16 tests   DatabaseAuthDriverMySQLTest
    3.7s   50 tests   QueueManagerPostgreSQLTest
```

A long tail with no peak is the shape to want: it means the previous rounds of this work
landed, and that the next saving has to come from something structural rather than from one
class.

**Read these as a shape, not as a target.** The total below was measured on a different day
and a different database image, and the band on this machine is wider than the difference
between them.

## Coverage is not 12% — it is most of the run

The image sets `xdebug.mode=coverage`, so a plain `./dockertest` instruments every line of
`src/` whether or not anybody reads the report. Same commit, same 15,693 tests, runs minutes
apart:

| Run | Wall clock | What PHPUnit itself reports |
| --- | --- | --- |
| `./dockertest` | **9:50** | — |
| `./dockertest --nocoverage` | **2:56** | 149.5 s of test time |

That pair is a dated measurement: 15,693 tests on the `latest-pg14` database image. The
suite and the image (`timescale/timescaledb:2.26.4-pg17`) have both moved since, so the
ratio is the finding to carry forward, not the seconds.

**Instrumentation is 3.3× — about 70% of the default run.** A subset reproduces the ratio at
a smaller scale: `tests/Unit/Console` is 88 s by default and 27 s with `--nocoverage`, and
part of that 27 s is Docker start-up rather than tests.

A figure of 12% is easy to arrive at, and wrong: it comes from comparing a coverage-collecting
run with one where Xdebug is still loaded in coverage mode — which measures PHPUnit's
collection and report, not the instrumentation, which is where the time actually is. That is the trap this whole page is
about: the second measurement has to change the thing you think is expensive, not the flag
that names it.

What follows from it:

- **For an ordinary run, use `--nocoverage`.** A run a third as long is a different working rhythm
  from the default, and nothing about a red test needs a clover file.
- **For rule 11, use `--coverage`.** That is the run whose extra time buys something.
- The always-on `<coverage>` block still earns its place — a coverage report that has to be
  asked for is one nobody has — but the ordinary loop should not be paying for it.

## Three traps that make a test lie, and the sweeps that find them

The first two are found by sweeping rather than by reading, and all three produce a test
that passes whether the subject works or not. That is the
category worth automating: a test that is merely slow announces itself.

### `fail()` inside a `try` its own `catch` swallows

`PHPUnit\Framework\AssertionFailedError` extends `\RuntimeException`, so this passes
whether or not the subject throws:

```php
try {
    $subject->mustThrow();
    $this->fail('it did not throw');   // throws AssertionFailedError
} catch (\Exception $e) {              // catches it
    $this->assertStringContainsString('…', $e->getMessage());  // PHPUnit's message
}
```

The shape is easy to write and easy to miss in review, which is why a sweep guards it:
`FailIsNotSwallowedTest` fails on any occurrence. Only `\Throwable`, `\Exception`
and `\RuntimeException` swallow it — a `catch (ValidationException $e)` is fine, and a
sweep that flagged those too would report 128 sites of which 88 are correct.

### A sweep over nothing passes

When every assertion in a test sits inside a `foreach`, the loop body *is* the test — and an
empty collection means no assertion is evaluated at all. PHPUnit reports a pass, and not
even a risky one: the `foreach` line counts as work.

The shape is not theoretical: a helper that destructures a pair into one variable returns
`[]` every time, and a test asserting "at most two" and "no empty strings" is true of
nothing. A `glob()` over a directory that briefly vanishes mid-run turns a sweep into a
no-op the same way.

`ASweepOverNothingDoesNotPassTest` guards against it, restricted to a `foreach` over a **call**: a literal array or a variable
the test built above it cannot be empty by surprise, and flagging those reports three
hundred sites of which almost none is a risk.

### A sentinel that can also be a real value

The guard written to catch the next broken template reported success for every one of them.
It returned the parser's first output line and treated `''` as "it parsed" — and `php -l`
prints a blank line first, so the message is in `$output[1]`.

It was caught by deliberately breaking a template and watching the guard pass. **Verify a
new guard against the fault it is for**, before trusting it: a guard that cannot fail is
worse than no guard, because it is counted.

## What not to do

- **Do not remove the always-on `<coverage>` block** — but know what it costs, because it is
  not 12%. See [above](#coverage-is-not-12-it-is-most-of-the-run): it is most of the run, and it is the one number worth re-measuring
  before believing anything else about this suite.
- **Do not drop a database from the matrix.** The bugs this framework has shipped in the
  query builder were dialect-specific — a `?` placeholder only MySQL tolerated, a backtick
  only MySQL accepts. The repetition *is* the test.
- **Do not reach for parallelism first.** `./dockertest --parallel[=N]` exists — ParaTest
  over N workers, each with its own database, then the `serial` group in one process (see
  the [Testing Guide](Pramnos_Testing_Guide.md#running-the-frameworks-suite-in-parallel)).
  It collects no coverage, so the sequential run stays the reference, and a test that fails
  only in parallel is one that shares something. Parallelism hides a slow class rather than
  fixing it; remove the per-class costs above first.
- **Do not fix a class the profiler has not looked at.** See above; this is where the time
  goes when it is wasted.

## Two concurrent runs corrupt the databases

`./dockertest` takes a lock and tells you the PID holding it. That is not a nicety: both runs
share the test databases, and the second one's schema setup lands in the middle of the
first's transactions.
