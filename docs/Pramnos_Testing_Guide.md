---
use_cases:
  - Writing a test for a controller, model or HTTP endpoint
  - Using factories or seeders to build test data
  - Choosing a base test case, or testing without booting the application
  - Changing the debug toolbar's JavaScript, or any asset the framework ships
  - Running the linter or the JavaScript tests
  - Asserting that an action broadcast a realtime event
---

# Pramnos Testing Guide

Pramnos provides comprehensive testing infrastructure including HTTP testing, factory generation, and seeding.

## HTTP Testing

Requests go through `Pramnos\Testing\TestClient`, which boots the application and returns a
`Pramnos\Testing\TestResponse` to assert against.

```php
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Testing\TestClient;

class UserApiTest extends BaseTestCase
{
    public function testGetUsers(): void
    {
        $client   = new TestClient();
        $response = $client->get('/api/v1/users');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.username', 'john_doe');
    }
}
```

> Until 2026-08-14 this section showed a `Pramnos\Testing\HttpTest` base class with the
> request methods on `$this`. **It has never existed.** The capability is real; the class was
> not. If you have code extending it, it is `BaseTestCase` plus a `TestClient` — the methods
> below are the same ones, on the client rather than on the test.

> **Before 2026-08-26 the path was ignored.** `TestClient` set `REQUEST_URI` and nothing
> else, but classic MVC routing reads `$_GET['r']` — which is what the scaffolded rewrite
> produces — so `calcParams()` never ran and every request fell through to the default
> controller. The response was the site's home page whatever you asked for, and the
> assertions passed against it: a test written to prove `/admin/users` is refused to a guest
> passed on a home page no guard applies to. Attribute-routed projects were unaffected;
> everything routing the classic way was testing one page.
>
> If you have HTTP tests written before that date, re-read them. Some were asserting things
> the home page happens to satisfy.

### What a request writes

Anything the code under test `echo`es during a request is part of the response, and
`TestClient` captures it — placed in front of the body, which is the order a browser
receives it in.

```php
// A controller that writes straight to the output stream
$body = (string) $client->get('/Legacy')->getResponse()->getBody();
$this->assertStringContainsString('what it echoed', $body);
```

Before 2026-08-27 it went to the terminal instead, straight through PHPUnit's own
output: `Application::redirect()` writes a `<script>window.location=…</script>`
fallback before ending a request, so a suite exercising an administration area
printed a block of HTML per redirect between the progress dots. That was the visible
half. The invisible half was that a controller echoing its body — several bundled
ones did — could not be asserted on at all.

### One client, many requests

A web request builds an `Application`, serves one URL and ends, so a good deal of
per-request state is per-request only by accident. A `TestClient` keeps one
application across every call, and so does a worker or any long-running server —
which is where that shows.

`Application::beginRequest()` re-derives it, and `TestClient` calls it for you
before each request:

```php
$client->get('/admin');       // admin theme, Dashboard as the default controller
$client->get('/');            // site theme again — before this, still the admin one
```

It also resets `Request`'s statics. Those matter for the same reason: routing state
is only recomputed when there is a path to route, so a request to `/` used to serve
whatever controller the request before it had resolved.

If you handle several requests in one process yourself, call `beginRequest()` per
request. A single-request process needs nothing: the constructor calls it.

### Signing a user in

`loginUser()` on `BaseTestCase` establishes a session, and `logoutUser()` drops it:

```php
$this->loginUser($administratorId);
$client->get('/admin')->assertStatus(200);

$this->logoutUser();
$client->get('/admin')->assertStatus(302);
```

Always pair them. The session is process-wide in a test run, so a sign-in with no
way back leaks into every test after it — and those tests then pass or fail for a
reason that is nowhere in them.

The id must be above 1: 0 is the guest and 1 the built-in system account, and every
guard in the framework rejects both.

> **Before 2026-08-26 `loginUser()` signed nobody in.** It set `$_SESSION['auth']`
> and `$_SESSION['user_id']`, and nothing reads either — `staticIsLogged()` wants
> `logged` and `uid`. Tests using it exercised the signed-out path while reading as
> though they covered the signed-in one. If you have such a test, it is running for
> the first time now.

### CSS selector assertions

`assertSelectorExists()` and friends need two packages:

```bash
composer require --dev symfony/dom-crawler symfony/css-selector
```

Projects scaffolded from 2026-08-26 have them. Earlier ones do not, because they
are dev dependencies of the framework and a dependency's dev dependencies are never
installed — which is why those three assertions used to throw a missing-class
error. They stay out of `require`: nothing in production parses HTML.

### Available request methods

```php
$client->get('/path', $headers);
$client->post('/path', $data, $headers);
$client->put('/path', $data, $headers);
$client->delete('/path', $data, $headers);
$client->call('POST', '/path', $parameters, $headers);
$client->submitForm('Save', $data);          // finds the form, fills it, posts it
```

Headers are an array, which is also how authentication is passed:

```php
$client->get('/api/v1/profile', ['Authorization' => 'Bearer ' . $token]);
```

### Response assertions

Every assertion returns the response, so they chain:

```php
$response
    ->assertStatus(201)
    ->assertJsonPath('data.id', 42)
    ->assertSee('User created')
    ->assertDontSee('Error');
```

| Assertion | Checks |
| --- | --- |
| `assertStatus(int)` / `assertSuccessful()` | the status code |
| `assertJson(array)` | the body contains this structure |
| `assertJsonPath(string, mixed)` | one value, by dotted path |
| `assertSee(string)` / `assertDontSee(string)` | raw body content |
| `assertSeeText(string)` | body content with tags stripped |
| `assertSelectorExists(string)` | a CSS selector matches |
| `assertSelectorContains(string, string)` | a selector's text |
| `assertSelectorAttribute(string, string, string)` | a selector's attribute |

`getResponse()` returns the underlying `Pramnos\Http\Response` when you need something the
assertions do not cover.

### Database assertions

These are on `BaseTestCase` itself, because they ask the database rather than a response:

```php
$this->assertDatabaseHas('users', ['email' => 'john@example.com']);
$this->assertDatabaseMissing('users', ['email' => 'deleted@example.com']);
```

## Factories

### Generate Test Data

Factories create fake model instances for testing:

```php
class UserFactory
{
    public function definition()
    {
        return [
            'username' => \Pramnos\Support\Faker::username(),
            'email'    => \Pramnos\Support\Faker::email(),
            'password' => hash('sha256', 'password'),
            'active'   => true,
        ];
    }
}
```

### Using Factories

```php
// Generate single user
$user = factory(\App\Models\User::class)->create();

// Generate multiple
$users = factory(\App\Models\User::class, 10)->create();

// Generate with overrides
$user = factory(\App\Models\User::class)->create([
    'email' => 'admin@example.com',
    'active' => false,
]);

// Generate without saving
$attributes = factory(\App\Models\User::class)->make();
```

## Seeders

### Database Seeding

Seeders populate the database with test data:

```php
<?php

namespace Database\Seeders;

use Pramnos\Database\Seeder;

class UserTableSeeder extends Seeder
{
    public function run()
    {
        // Create seed data
        factory(\App\Models\User::class, 50)->create();
        
        // Or create specific records
        \App\Models\User::create([
            'username' => 'admin',
            'email'    => 'admin@example.com',
            'password' => hash('sha256', 'admin'),
            'role'     => 'admin',
        ]);
    }
}
```

### Run Seeders

```bash
# Run all seeders
php vendor/bin/pramnos db:seed

# Run specific seeder
php vendor/bin/pramnos db:seed --seeder=UserTableSeeder

# In tests
public function setUp(): void
{
    parent::setUp();
    $this->seed(['UserTableSeeder', 'PostTableSeeder']);
}
```

## Test Cases

### Setup & Teardown

```php
use Pramnos\Framework\Testing\BaseTestCase;

class UserControllerTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup before each test
        $this->user = factory(\App\Models\User::class)->create();
    }
    
    protected function tearDown(): void
    {
        // Cleanup after each test
        \App\Models\User::truncate();
        
        parent::tearDown();
    }
}
```

### Database Transactions

```php
public function testCreateUser()
{
    $this->withoutTransactions(function () {
        // Run with real database writes
        $user = \App\Models\User::create([...]);
        $this->assertDatabaseHas('users', ['email' => $user->email]);
    });
}
```

### Database Assertions

```php
// Check record exists
$this->assertDatabaseHas('users', [
    'email' => 'john@example.com',
    'active' => true,
]);

// Check record doesn't exist
$this->assertDatabaseMissing('users', [
    'email' => 'deleted@example.com',
]);

// Count records
$this->assertEquals(42, \App\Models\User::count());
```

## Complete Example

```php
class UserApiTest extends \Pramnos\Framework\Testing\BaseTestCase
{
    protected $user;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = factory(\App\Models\User::class)->create();
    }
    
    public function testUserCanViewTheirProfile()
    {
        $response = $this->withToken($this->user->api_token)
            ->get('/api/v1/profile');
        
        $response->assertOk();
        $response->assertJsonPath('data.email', $this->user->email);
    }
    
    public function testUserCanUpdateProfile()
    {
        $response = $this->withToken($this->user->api_token)
            ->patch('/api/v1/profile', [
                'email' => 'newemail@example.com',
            ]);
        
        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'userid' => $this->user->userid,
            'email'  => 'newemail@example.com',
        ]);
    }
    
    public function testUnauthorizedUserCannotAccessProfile()
    {
        $response = $this->get('/api/v1/profile');
        
        $response->assertUnauthorized();
    }
}
```

## Writing a test that does not slow the suite down

The suite's cost is concentrated, not spread: **203 tests out of 9364 account for 46% of
the run**, measured. Three habits are what put a test in that group, and all three have a
cheap alternative:

| Habit | Cost measured | Instead |
| --- | --- | --- |
| Connecting to a hostname that does not resolve | **8.00 s per test** | Assert on the DSN string if that is what you mean; use an IP literal (`127.0.0.1:9`) if you want a *failure*. A connect timeout does **not** help — the 8 s is `getaddrinfo()`, before any socket exists |
| Building an expensive fixture in `setUp()` — a scaffolded project, a real JPEG | 1–2 s per test | Build it once in `setUpBeforeClass()` when the tests only read it |
| Calling `$db->cacheflush()` in `setUp()` | **85 ms per call** — it is a directory scan | Call it once per class. It defends against what an *earlier* class left in the cache, and `query()` does not cache unless you ask it to |
| Hashing a password at the default cost | **143 ms per hash** — and 2FA setup hashes ten | Nothing: the suite already sets `PRAMNOS_BCRYPT_COST=4` in `tests/bootstrap.php`. Use `PasswordHash::make()` rather than `password_hash()` directly, so your code obeys it |
| Creating and dropping schema per test | ≈300 ms per test | Schema once per class; wrap each test in a transaction and roll it back |
| Letting the code under test shell out or reach the network | **1.9 s per test**, and variable | Skip it with the flag the command already has, or should have — `init` gained `--no-install` for exactly this. A unit test that depends on composer or on HTTP is slow *and* flaky |
| Saving a model, in a suite or in production | **1358 ms** before 2026-08-27 — `cacheflush()` walked the whole cache tree on every write | Nothing: fixed in `FileAdapter`. If you see it again, check that `clear()` is still sampling its sweep |
| `exec('rm -rf …')` in `tearDown()` for a small temporary tree | **≈12 ms per test** (measured: 382 ms → 272 ms over nine tests) | A recursive `unlink`/`rmdir` helper — one already exists in `ApiDocsTest`. **Measure before converting a large tree**: for a scaffolded project of hundreds of files, `rm -rf` in C may well beat PHP recursion, and this row is not a licence to assume otherwise |

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

## Owning a schema without paying for it per test

`Pramnos\Framework\Testing\DatabaseTestCase` is for integration tests that need their own
tables. Declare three things and the lifecycle is handled:

```php
use Pramnos\Framework\Testing\DatabaseTestCase;

class WidgetsMySQLTest extends DatabaseTestCase
{
    protected static function connectionConfig(): array
    {
        return ['type' => 'mysql', 'server' => 'db', 'user' => 'root',
                'password' => 'secret', 'database' => 'pramnos_test', 'port' => 3306];
    }

    protected static function ownedTables(): array
    {
        return ['widget_parts', 'widgets'];   // children first
    }

    protected static function schemaStatements(): array
    {
        return ['CREATE TABLE `widgets` (...)', 'CREATE TABLE `widget_parts` (...)'];
    }

    public function testSomething(): void
    {
        $this->db->query('INSERT INTO `widgets` ...');   // $this->db is connected
    }
}
```

| When | What happens |
| --- | --- |
| `setUpBeforeClass()` | Drops the owned tables, then runs the DDL |
| `setUp()` | Connects, and `DELETE`s every owned table |
| `tearDown()` | Closes the connection |
| `tearDownAfterClass()` | Drops the owned tables |

Foreign keys between owned tables are handled: the drops and deletes run with
`FOREIGN_KEY_CHECKS = 0` on MySQL, and `ownedTables()` is ordered so PostgreSQL is satisfied
without disabling anything. Override `setUp()`/`tearDown()` freely — just call `parent::`.

**Why it is worth converting a class.** `QueryBuilderMySQLTest` went from **16.8 s to
0.56 s** for its 92 tests, with no assertion changed. Recreating three tables per test cost
170 ms of the 183 ms each test took, in a class that never asserts anything about a schema.

### The one thing that will bite you

**Auto-increment counters no longer restart between tests.** A fixture that writes a
hardcoded foreign key —

```php
// product 1 = Apple, product 3 = Carrot
$this->db->query("INSERT INTO qb_tags (product_id, tag) VALUES (1, 'popular'), (3, 'healthy')");
```

— worked only because the table restarted at 1 every time. After converting, it points at
rows that do not exist, and the failure appears in the *join* tests rather than in the
fixture. Look the ids up instead, which is what the literals meant:

```php
$id = $this->db->queryBuilder()->select('id')->from('qb_products')
    ->where('name', 'Apple')->first()->fields['id'];
```

If a class genuinely asserts on the sequence, override `resetAutoIncrement()` to return
`true` — it costs about 9 ms per table, against 0.11 ms for the `DELETE` alone.

### When not to use it

When the DDL **is** the subject. The framework's migration and schema-builder tests keep
building their schema per test, because that is the behaviour they are asserting.

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

Two framework singletons are **per-request in production and process-wide in a test
run**. A test run is thousands of "requests" in one PHP process, so state one test
establishes answers for every test after it.

Both are registered in `phpunit.xml`, and a project scaffolded by `pramnos init` gets
them already:

```xml
<extensions>
    <bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\GateIsolation"/>
</extensions>
```

| Extension | What leaks without it |
| --- | --- |
| `RequestIdentityIsolation` | An identity sealed by one test stays sealed. A controller test running after a middleware test finds itself signed in as somebody it never authenticated — **135 failures**, in tests that had nothing to do with authentication. |
| `DocumentIsolation` | `Document` is a mutable singleton per type. A test that sets `->type = 'json'` is writing to the shared HTML document, and the next test that renders gets it — **three failures**, each of which appeared only in a full run. |
| `GateIsolation` | `Gate` keeps abilities, policies and hooks in statics. A `Gate::before(fn () => true)` registered by one test would allow everything for every test after it — and the failure lands in a test asserting that an ordinary user is *refused*. Written **with** the feature rather than after the failures. |

Both reset at `PreparationStarted`, which is **before `setUp()`** — so a test that
deliberately seals an identity or configures a document still gets exactly what it asked
for. There is nothing to opt out of and nothing to call.

**Why extensions rather than `setUp()`.** Both are reached indirectly: a controller calls
a middleware, which seals an identity; a controller asks the Factory, which asks the
Document. So any list of "the tests that need to reset this" is a list that goes out of
date the moment somebody adds a test — silently, and with the failure appearing somewhere
else. This is also why they are worth knowing about even if you never touch them: **a
failure that only happens in a full run is almost never a bug in the test that failed.**

If you hit one anyway — a full-run-only failure involving state you did not set — the fix
is a third extension of the same shape, not a `setUp()` in the test that noticed.

Existing projects that predate this: see
[the Upgrade Guide](Pramnos_Upgrade_Guide.md#test-isolation-extensions-for-existing-projects).

## `./dockertest` says a run is already in progress

Two runs against the same Docker databases corrupt each other, so `dockertest`
holds a lock. It is a **directory**:

```bash
/tmp/dockertest-<namespace>.lock.d/pid
```

`mkdir` is atomic on Linux, macOS and WSL alike, and succeeds only when the
directory does not already exist. The lock used to be `flock` on a file
descriptor — which is Linux-only. On macOS `flock` is simply absent, so
`flock: command not found` made the acquire fail and **every** run reported that
another was already in progress. There was no other run.

If you see that message and believe it is wrong:

```bash
./dockertest --force          # kills the recorded process, if any, and takes the lock
```

A run killed hard leaves the directory behind, and the next run notices: it reads
the PID file, finds the process gone, says "stale lock detected" and proceeds. You
should not need to remove anything by hand — if you do, `rm -rf` the path above.

A project scaffolded before this change still has the flock version in its own
`dockertest`; version control does not update it for you. Copy the lock block from
a freshly scaffolded project, or replace the file.

### …or that Docker is not responding, when it is

The same platform gap, one guard further on. The daemon-hang timeouts call GNU
coreutils `timeout`, which macOS also does not ship — so every guard exited 127,
"command not found", and the first one concluded the daemon was unreachable:

```
ERROR: Docker is not responding (timed out after 15s, or the daemon is not running).
```

The script now prefers a real `timeout`, then `gtimeout` (Homebrew coreutils), and
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
copy failed at random, on a database nothing in the project ever touches.

Terminating `template1`'s sessions is not a fix on its own — the scheduler can be
back before the next statement runs. `TestEnvironment` now retries the terminate
and the copy together (ten attempts, 200 ms apart) and rethrows anything that is
not SQLSTATE 55006 immediately, so a wrong password still fails on the first try
instead of two seconds later.

Nothing to change in a project: the retry lives in `TestEnvironment::setupPostgres()`.

## The JavaScript the framework ships

One browser asset — `src/Pramnos/Debug/assets/debugbar.js`, around 3700 lines, served on every
page of every project with the debug toolbar enabled — plus the tests that cover it under
`tests/js/`.

```bash
./testjs              # node --test over tests/js/**/*.test.js
./lintjs              # ESLint over src and tests/js
./lintjs --fix        # fix what can be fixed automatically
./lintjs src          # a subtree
```

Both run **inside the container**, like `./dockertest`, and for the same reason: the container
is the environment. A linter that reports differently depending on whose Node ran it is worse
than no linter. `npm` is in the `Dockerfile`; `./lintjs` installs it into a stale image rather
than failing on a detail nobody wants to think about while linting.

### What the linter is for, and what it is not

Every rule enabled describes a **defect**. There are no style rules — no quote policy, no
semicolons, no indentation — because `debugbar.js` predates the config by years: reformatting it
would bury the next real change in noise, and a `--fix` sweep across 3700 lines is precisely the
diff nobody can review.

The rule the config exists for is `no-redeclare`. That asset had `var hasMvcPage` declared
twice; a consuming project's linter found it, and the duplicate had stopped **1,195 panel tests**
from running there. Nothing here could have caught it.

!!! warning "Do not write a unit test for this class of mistake"
    It was tried. A test that scanned for duplicate `var` declarations flagged `var rows` in six
    unrelated functions, because it matched an **identifier** rather than a redeclaration, and it
    was deleted. `no-redeclare` understands scope; a grep never will.

    The same reasoning applies to `no-undef`, `no-dupe-keys` and the rest: they are answers a
    parser can give and a test cannot.

### First run

Six findings, and two of them were the config's own fault — `Blob` and `setImmediate` missing
from the globals. The other four were real: a dead `CLIENT_TABS` lookup table in `debugbar.js`
that nothing read, and three tests destructuring a `sandbox` they never used. The dead table is
worth a note, because deleting it correctly required reading the code rather than the error: the
three tabs it named *are* special-cased, by explicit `tab.key === …` checks in three separate
places. The constant duplicated knowledge that lives elsewhere; wiring it up would be a refactor
of behavioural code, so the observation is recorded in the file instead.

CI runs both, on Node 20 — the version the container ships, so a failure there reproduces with
`./lintjs` rather than being a CI-only surprise.

## Reference

**Related Guides:**
- [Pramnos_Test_Suite_Performance.md](Pramnos_Test_Suite_Performance.md) — where the suite's time goes, measured
- [Pramnos_Debugging_Guide.md](Pramnos_Debugging_Guide.md) — the toolbar the shipped JavaScript draws
- [Pramnos_Migration_Guide.md](Pramnos_Migration_Guide.md) — Running migrations in tests
- [Pramnos_Console_Guide.md](Pramnos_Console_Guide.md) — `db:seed` command and Seeder base class
- [Pramnos_Framework_Guide.md](Pramnos_Framework_Guide.md) — Middleware pipeline, Response Object

**Topics covered:**
- HTTP test client API and all methods
- Response assertions and JSON validation
- Factory definition and customization
- Seeder generation and execution
- Database state management in integration tests

## Init-less test database helper

`Pramnos\Framework\Testing\TestDatabase` is a standalone, **init-less** test-DB
helper for applications that deliberately do not run the MVC request lifecycle
(`Application::init()`) in tests — e.g. "Services + API + SPA" apps, or any app
whose own schema (a bespoke `sessions` table, say) would collide with the
framework's session tracking. Unlike `BaseTestCase` (whose `setUp()` boots
`init()`), this helper runs no lifecycle.

```php
use Pramnos\Framework\Testing\TestDatabase;

// A raw PDO to the configured database (built from the `database` settings,
// honouring `database.timezone`). Per-process singleton, persistent.
$pdo = TestDatabase::connection();
$pdo->prepare('INSERT INTO users (username) VALUES (?)')->execute(['alice']);

// Row-existence assertions.
TestDatabase::assertDatabaseHas('users', ['username' => 'alice']);
TestDatabase::assertDatabaseMissing('users', ['username' => 'bob']);

// Seams: inject a mock, or drop the cached connection for isolation.
TestDatabase::setConnection($mockPdo);
TestDatabase::reset();
```

It reads the `database` settings section (`hostname/port/database/user/password/
type` + optional `timezone`), so it connects to exactly the database the app
uses — seeded rows behave identically to rows written through the framework
database layer.
