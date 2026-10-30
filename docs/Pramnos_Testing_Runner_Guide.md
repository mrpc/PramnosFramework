---
use_cases:
  - Asserting that something was broadcast
  - Isolating process-wide state between tests
  - Finding why a test passes alone and fails in the suite
  - Reading what ./dockertest does with containers, locks and exit codes
  - Finding why a test passes on its own and fails in the full suite
---

# Process state, and ./dockertest

Part of the [testing guide](Pramnos_Testing_Guide.md).

## Asserting that something was broadcast

`NullDriver` discards silently and `LogDriver` writes a file a test then has to
parse, so a test asserting "this action broadcasts" either needed a real Redis or
asserted nothing. The second kind keeps passing after the broadcast is deleted,
which is the failure mode worth naming.

`Broadcasting\Testing\FakeDriver` records instead of publishing:

```php
use Pramnos\Broadcasting\Testing\FakeDriver;

protected function tearDown(): void
{
    FakeDriver::restore();      // unconditional; safe when nothing was swapped
}

public function testMarkingAnOrderPaidAnnouncesIt(): void
{
    // Arrange
    $fake = FakeDriver::swap();          // becomes the process-default manager

    // Act
    $order->markPaid();

    // Assert
    $fake->assertBroadcast('private-order.' . $order->id, 'order.paid');
    $fake->assertBroadcastCount(1);
}
```

`swap()` installs the fake as the process default, so code that resolves the manager
itself is captured — no need to thread a driver through the code under test. It
remembers whatever was installed, and `restore()` puts exactly that back: a test
that left a fake in place would silently swallow every later test's broadcasts, and
the failure would surface in an unrelated file.

### What you can assert

| | |
|---|---|
| `assertBroadcast($channel?, $event?, $payloadMatches?)` | something matched |
| `assertNotBroadcast($channel?, $event?)` | nothing matched |
| `assertBroadcastCount($n, $channel?, $event?)` | exactly `$n` matched |
| `assertNothingBroadcast()` | this path stays quiet |
| `assertBroadcastExcept($socketId, $channel?, $event?)` | `toOthers()` reached the driver |

`assertBroadcastExcept()` earns its own place: the exclusion is easy to lose — a
driver that does not implement it, a socket id that never left the request — and its
only production symptom is one user seeing a duplicate of their own action.

For anything the assertions do not cover, `recorded()` returns every entry and
`matching()` narrows by channel, event and a payload predicate.

A failing assertion **lists what was actually broadcast**. Without that, a reader
cannot tell a missing broadcast from one on a channel whose name is built slightly
differently, which is the usual cause:

```
Expected a broadcast on "private-order-42" named "order.paid", but none matched.
Recorded:
  - private-order.42 / order.paid
```

See the [Realtime guide](Pramnos_Realtime_Guide.md) for what the channels and events
mean.

## Isolating process-wide state

Request state is **per-request in production and process-wide in a test run**. A test run is
thousands of "requests" in one PHP process, so state one test establishes answers for every
test after it.

Each extension resets one piece of it; all are registered in `phpunit.xml`, and a project scaffolded by `pramnos init` gets
them already:

```xml
<extensions>
    <bootstrap class="Pramnos\Framework\Testing\ProcessStateIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\GateIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\ServerGlobalIsolation"/>
</extensions>
```

| Extension | What leaks without it |
| --- | --- |
| `ProcessStateIsolation` | Per **test class**, not per test. It records the process once, after `tests/bootstrap.php`, and puts it back before every class: settings, the default connection, the applications, `Auth`, `Permissions`, two dozen registries (features, menu, health checks, mail types, second factors…), `$_SESSION`, `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_ENV`, and the environment `putenv()` changes. It also empties the caches of table shapes and loaded users. What the bootstrap set up stays, and what a class added is gone. Without it, a random-order run fails by which class happened to go first. |
| `RequestIdentityIsolation` | An identity sealed by one test stays sealed. A controller test running after a middleware test finds itself signed in as somebody it never authenticated — and fails, in a test that has nothing to do with authentication. It also clears `$unittesting_logged`, the override that makes `Session::staticIsLogged()` answer "signed in" under `UNITTESTING` whatever the session says. Left set, it would sign in every test after it. |
| `DocumentIsolation` | `Document` is a mutable singleton per type. A test that sets `->type = 'json'` is writing to the shared HTML document, and the next test that renders gets it — a failure that appears only in a full run. |
| `GateIsolation` | `Gate` keeps abilities, policies and hooks in statics. A `Gate::before(fn () => true)` registered by one test would allow everything for every test after it — and the failure lands in a test asserting that an ordinary user is *refused*. |
| `ServerGlobalIsolation` | `$_SERVER` is a superglobal. A test class that starts with `$_SERVER = []` makes tests elsewhere pay: a console command with no `PHP_SELF`, a URL with a port and no host — **warnings in a full run only**. It is restored to what it was after the bootstrap script, so what `tests/bootstrap.php` sets is kept. |

The others reset at `PreparationStarted`, which is **before `setUp()`** — so a test that
deliberately seals an identity or configures a document still gets exactly what it asked
for. There is nothing to opt out of and nothing to call.

**Why extensions rather than `setUp()`.** All are reached indirectly: a controller calls
a middleware, which seals an identity; a controller asks the Factory, which asks the
Document. So any list of "the tests that need to reset this" is a list that goes out of
date the moment somebody adds a test — silently, and with the failure appearing somewhere
else. This is also why they are worth knowing about even if you never touch them: **a
failure that only happens in a full run is almost never a bug in the test that failed.**

If you hit one anyway — a full-run-only failure involving state you did not set — the fix
is another extension of the same shape, not a `setUp()` in the test that noticed.

A project whose `phpunit.xml` lacks these extensions: see
[the Upgrade Guide](Pramnos_Upgrade_Guide.md#test-isolation-extensions-for-existing-projects).

## A test that passes alone and fails in the suite

Such a failure is almost never a bug in the test that failed. Something that ran before it
left state behind, and the test inherited it. To find it, run the suite in random order:

```bash
./dockertest --nocoverage --order-by=random --random-order-seed=101
```

A seed reproduces the same order, so a failure you see once you can run again. Try a few
seeds, and a fresh database for each one: an empty database shows which tables a test only
had because a class before it built them.

These are the causes that have actually turned up, with what to do about each:

| What was shared | How it showed | Instead |
| --- | --- | --- |
| A framework table built by hand (`CREATE TABLE IF NOT EXISTS …`) | The first class to reach an empty database defined the table for the run. A `NOT NULL` column with no default failed 225 inserts elsewhere, and a `sessions` keyed on `sid` turned every recorded visit into an update of one row | `Testing\Schema::table('name')`, which builds it from its migrations. A table with no recipe gets one in `Schema::RECIPES` |
| A table the test never built | It existed because an earlier class had created it, until the order changed | Build every table the code under test reads, including the ones its foreign keys point at |
| A row cached by the code under test | `User::load()` keeps a row for ten seconds and `MediaObject::getList()` a listing for sixty. A test that recycled an id, or emptied the table, read the old answer | `$db->cacheflush('<category>')` after writing rows the code has cached: `userlist` for users, `media` for media |
| A cookie, a session key or a `$_GET` value | A `visitorid` cookie made a tracking test record somebody else; a `$_GET['_option']` became another controller's route argument | `ProcessStateIsolation` resets them before every class. Inside a class, reset what your own tests set |
| Something registered on a singleton | An `afterLogin()` closure from one test asserted inside another class's sign-in | `ProcessStateIsolation` restores `Auth`, `Permissions` and the registries before every class |
| An application built for the first time inside a test | Its constructor resets the administration area, which undid what the test had just arranged | Build it in `setUp()`, before arranging anything: `Application::getInstance()` |
| A method's static variable in a class hierarchy | Since PHP 8.1 a subclass shares it, so `Validator::getInstance()` answered with whichever class asked first | In production code, key the instance by `static::class` |
| A value tied to the process | `md5(session_id())` is the same for every test in a run, so two classes writing "the current session" collided | Delete the row for that key before writing it, or use a value of the test's own |
| The wall clock | On WSL the clock is corrected backwards, so a deadline of exactly N seconds read as N + 1 | Measure durations with `hrtime()`, and allow one second where a test compares against `time()` |

The first two rows account for most of what turns up. If a test reads a table, it should
build that table.

## Walking a directory from a test — `Tree::files()`, not the iterator

A sweep that reads a tree uses `Pramnos\Framework\Testing\Tree::files($directory)`:

```php
use Pramnos\Framework\Testing\Tree;

/** @return list<string> */
private static function viewFiles(string $theme): array
{
    return Tree::files(dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views');
}
```

It returns every `.php` file under the directory, recursively and sorted — pass `null` as
the second argument to keep every extension. Sorted because a failure message assembled
from the list has to read the same on two runs; directory order does not.

### What it is for

The error it exists for looks like this:

```
UnexpectedValueException: RecursiveDirectoryIterator::__construct(
  …/scaffolding/themes/tailwind/views): Failed to open directory: No such file or directory
```

— on a directory that is tracked in git, that nothing in the repository writes, and that
was never removed. What goes missing is the container's *view* of it. The repository is a
bind mount, and on macOS Docker Desktop that is VirtioFS (`fakeowner` over
`/run/host_mark/Users`), which under load can answer `ENOENT` for a directory that is on
the disk throughout. It is rare, and it is not reproducible on demand.

`Tree::files()` looks **three times**, 50 ms apart, and then raises a `RuntimeException`
naming the path and saying how to tell the two explanations apart. One retry is not enough
for a sweep over a large tree such as the whole of `scaffolding/`; a loop would tolerate a
directory that is genuinely gone. Three keeps the cost of a directory that is actually
missing at 100 ms before it raises.

### The two shapes it replaces, and why both are wrong

- `new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir))` throws an
  `UnexpectedValueException` that reads like a bug in the test rather than a directory that
  could not be read.
- `glob($dir . '/*')` returns `false`, so the sweep quietly examines nothing and **passes**.
  A sweep still asserts its list is non-empty, because `Tree::files()` legitimately returns
  `[]` for a directory that is there and holds nothing.

`Tree::matching($pattern)` is the glob half. It looks twice — one retry, 50 ms later — when
the first look returns `false` or nothing, and returns `[]` for a pattern that genuinely
matches nothing. The emptiness assertion alone is not enough: it makes a blink a red test
rather than a silent pass, which is right, but the red is still a failure nobody can act on.
The pause is paid only when there is nothing to report.

### And `Tree::read($path)` for the file itself

The listing is not the last chance to blink. A sweep lists a directory, then reads each
path it was given — and that read can answer `false` with a warning PHPUnit turns into an
error, on a file that has not changed.

```php
$body = Tree::read($path);   // not file_get_contents()
```

Same attempts as `files()`, same `RuntimeException` naming the path. An **empty file is
`''`, not a failure**, which is the distinction the retry depends on: reading the two as the
same thing would pause on every empty file in a sweep and then raise on one.

### If you are the one adding a sweep

Do not put an `isFile()` filter in your own walk on the assumption that directories arrive.
In the default `LEAVES_ONLY` mode they do not — not even empty ones, which are descended
into and produce nothing. `TreeTest` pins that by asserting an empty subdirectory never
appears, so a change to `SELF_FIRST` fails there rather than in whatever sweep reads a
directory as a file.

## The TimescaleDB container runs with no background workers

`docker-compose.yml` starts it as `postgres -c timescaledb.max_background_workers=0`. That
is the framework's own container only — the compose file `init` writes for an application
does not carry the line and must not, because an application's retention and compression
policies have to actually run.

Under the suite, the scheduler crashes the server:

```
background worker "Retention Policy [9262]" was terminated by signal 11: Segmentation fault
DETAIL:  Failed process was running: CALL _timescaledb_functions.policy_retention()
LOG:  terminating any other active server processes
```

Each crash puts PostgreSQL into
recovery and kills whatever test is mid-statement, which surfaces as
`the database system is not yet accepting connections` on two or three unrelated
`FrameworkMigrations…` tests and — because the timing is random — as an occasional failure
anywhere else. **If you are looking at a database error in a test that has nothing to do
with TimescaleDB, check `docker logs pramnos_timescaledb | grep Segmentation` before
believing it.**

The policy itself is well-formed: `pushlog`, a `timestamptz` dimension, `drop_after 90
days`, no chunks to drop. Calling it by hand succeeds. It crashes only while the suite is
creating and dropping hypertables underneath it, so it is a concurrency bug in 2.26.4
rather than anything the framework does wrong — and the framework pins 2.26.4 deliberately,
for reasons the compose file explains, so it is not going away by upgrading.

**Nothing in the suite needs the scheduler.** What the suite asserts is that a policy is
*registered* — `TimescaleInspector` reads `jobs LEFT JOIN job_stats` precisely because a job
that has never run has no stats row — and the software-emulated path is driven directly by
`PolicyEngine`. Nothing anywhere waits for a job to fire. If you write a test that does,
this setting is what it will fail against, and the answer is to call the policy yourself
rather than to turn the workers back on.

## `./dockertest` exits with PHPUnit's status

`echo $?` after a run is PHPUnit's result: **0 for a green suite, non-zero for a
red one**. It matters because most things that run the suite never read the
summary on screen:

```bash
./dockertest --nocoverage && git commit -m "…"   # the commit does not happen on red
```

A git hook, a CI step, a `&&` chain and an agent checking its own work all ask
the same question the same way, and a runner that answers 0 for a failing suite
tells every one of them the work is finished.

The failure is easy to reintroduce, so it is worth knowing the shape: the script
ends with an `if` that opens the coverage report, and **a shell `if` whose
condition is false exits 0**. Any statement after PHPUnit becomes the script's
status unless the status is captured the moment PHPUnit returns:

```bash
docker-compose exec … vendor/bin/phpunit "${passthrough[@]}"
phpunit_status=$?     # $? is the *previous* command — nothing may come between
…
exit $phpunit_status
```

`InitDockertestExitStatusTest` asserts it on both the scaffolded runner and this
framework's own: every branch that invokes PHPUnit captures `$?` on the very next
line, and the last statement is `exit $phpunit_status`.

A project's `dockertest` is its own copy, and updating the framework does not update
it — the same caveat as the lock block below. Check the last lines of yours; if there is
no `exit $phpunit_status`, add the two pieces above.

## `./dockertest` stops the containers it started

A run that finds the project's containers down starts them, and stops them again when it
exits — pass or fail, before it releases the lock. A run that finds them already up leaves
them as they were. A test run therefore never leaves a database stack holding memory on the
machine afterwards.

```bash
./dockertest --nocoverage           # starts what is down, stops it on exit
./dockertest --nocoverage --keep    # leaves up what it started, for a series of runs
```

`./testjs` and `./dockernpm` behave the same way: a container that is down is started for
the command and stopped when it ends — the whole stack when nothing of the project was
running, otherwise only the service they started. `./testjs` falls back to the host's
`node` only where there is no Docker at all.

A project scaffolded before this keeps its own scripts; copy the `started_here` / `_finish`
block from a freshly scaffolded `dockertest` (and `scaffolding/templates/dockernpm.stub`,
`spa-testjs.stub`) to get it.

## `./dockertest` says a run is already in progress

Two runs against the same Docker databases corrupt each other, so `dockertest`
holds a lock. It is a **directory**:

```bash
/tmp/dockertest-<namespace>.lock.d/pid
```

`mkdir` is atomic on Linux, macOS and WSL alike, and succeeds only when the
directory does not already exist. A lock built on `flock` would not do: `flock`
is Linux-only, and on macOS `flock: command not found` makes the acquire fail, so
**every** run reports that another is already in progress.

If you see that message and believe it is wrong:

```bash
./dockertest --force          # kills the recorded process, if any, and takes the lock
```

A run killed hard leaves the directory behind, and the next run notices: it reads
the PID file, finds the process gone, says "stale lock detected" and proceeds. You
should not need to remove anything by hand — if you do, `rm -rf` the path above.

A project whose own `dockertest` still locks with `flock` is not updated by a
framework upgrade. Copy the lock block from a freshly scaffolded project, or replace
the file.

### …or that Docker is not responding, when it is

The same platform gap, one guard further on. The daemon-hang timeouts need GNU
coreutils `timeout`, which macOS does not ship. Called directly there, every guard
exits 127, "command not found", and the first one concludes the daemon is unreachable:

```
ERROR: Docker is not responding (timed out after 15s, or the daemon is not running).
```

So the script prefers a real `timeout`, then `gtimeout` (Homebrew coreutils), and
otherwise uses a small bash implementation that mirrors the two call forms in use
and returns `124` on a deadline, as GNU `timeout` does — the callers test for that
code to tell a wedged daemon from a failed command.

## …or that `template1` is being accessed by other users

On a TimescaleDB project the bootstrap can fail before a single test runs:

```
Database setup failed: SQLSTATE[55006]: Object in use: 7 ERROR:  source database
"template1" is being accessed by other users
DETAIL:  There is 1 other session using the database.
```

`TestEnvironment` builds the PostgreSQL test database as a copy of `template1`,
because that is where the image installs the `timescaledb` extension — copy
`template0` instead and the test database has no extension. PostgreSQL refuses to
copy a template while any session is attached to it, and on TimescaleDB one
regularly is: the extension runs a background-worker scheduler per database,
`template1` included, and that worker reconnects on a schedule of its own. So the
copy can fail at random, on a database nothing in the project ever touches.

Terminating `template1`'s sessions is not a fix on its own — the scheduler can be
back before the next statement runs. `TestEnvironment` retries the terminate
and the copy together (ten attempts, 200 ms apart) and rethrows anything that is
not SQLSTATE 55006 immediately, so a wrong password still fails on the first try
instead of two seconds later.

Nothing to change in a project: the retry lives in `TestEnvironment::setupPostgres()`.
