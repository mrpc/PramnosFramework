---
use_cases:
  - Writing a test that will not slow the suite down
  - Finding out why the test suite takes as long as it does
  - Deciding what to change to make ./dockertest faster
---

# Keeping the test suite fast

The suite runs ~11,600 tests against MySQL and PostgreSQL, with coverage on by default, in
under ten minutes. It was three times that for a third of the tests, and everything below is
what keeps it where it is.

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

## What not to do

- **Do not remove the always-on `<coverage>` block.** It costs about 12% of whatever the
  suite still does, and `--no-coverage` already exists for a run that does not need it.
  Rule 11 requires coverage on new code, and a coverage report that has to be asked for is a
  coverage report nobody has.
- **Do not drop a database from the matrix.** The bugs this framework has shipped in the
  query builder were dialect-specific — a `?` placeholder only MySQL tolerated, a backtick
  only MySQL accepts. The repetition *is* the test.
- **Do not reach for parallelism first.** `paratest` would give perhaps 3–4× on one machine,
  but this suite shares a database: it needs a schema or a database per worker, plus every
  static-state reset that currently relies on running in one process. It is the right step
  only once the per-class costs above are gone — and after them, the suite is fast enough
  that the answer has stayed "not yet".
- **Do not fix a class the profiler has not looked at.** See above; this is where the time
  goes when it is wasted.

## Two concurrent runs corrupt the databases

`./dockertest` takes a lock and tells you the PID holding it. That is not a nicety: both runs
share the test databases, and the second one's schema setup lands in the middle of the
first's transactions.
