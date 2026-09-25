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

## The screen sweep a scaffolded project ships

`init` writes `tests/Integration/ScreenSweepTest.php`. It discovers every controller under
`src/Controllers/` and `src/Admin/Controllers/`, requests each one's default action and
every *read* action it declares, and asserts each answers under 500 and is not a 404.

It exists because a controller is easy to test and a **rendered view** is not, and that is
where a project's uncovered code lives. A scaffolded project starts around 78% coverage
and almost all of the gap is views and the actions that render them — a 600-statement
admin `view.html.php` at 0% is a normal finding. The sweep takes that to roughly 93%
without anybody writing an assertion, and the failures it catches are not subtle: an
entire admin area answering 404 because a config block was never written, a view in a
directory nothing reads, a renamed column printing nothing.

### Two places to edit, and they are marked

```php
protected function seedRows(): void
{
    $this->db()->queryBuilder()->table('things')->insert([
        'name' => 'A thing', 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

protected function actingAsAdmin(): void
{
    // Sign in, if the screens worth sweeping are behind a login.
}
```

**Seed one row in every table a screen lists.** Without it every list renders its empty
state, which is the half of a template nobody gets wrong. The row loop is where a renamed
column starts printing nothing and a null blows up a formatter. One row, not a page: the
sweep is about whether the loop runs.

**And give a detail screen its subject.** `subjectFor()` is the same problem one layer
along: `/admin/Users/view` with no id is a valid request that answers 200, and what
renders is the "nothing selected" branch — four lines at the top of a template whose six
hundred are the point. A project that adopted the sweep without it watched coverage
*fall* from 74.8% to 45.0%.

```php
protected function subjectFor(string $prefix, string $action): string
{
    return match ($action) {
        'view', 'edit' => (string) $this->seededUserId(),
        'log'          => (string) $this->seededChannelId,
        default        => '',
    };
}
```

A `match` on the action, not one shared id: handing `view` a channel id where it wants a
user id renders the "not found" branch and the sweep reports success over it — the same
bug again, one layer further down.

### The framework's own tables are seeded for you

`seedFrameworkRows()` runs before `seedRows()` and puts one row in each of the framework's
own tables — the activity log, passkeys, two-factor, tokens, GDPR requests, the mail and
push logs. It is not something an application should have to write.

The reason is the person card. `Admin/Views/users/view.html.php` is usually the single
largest uncovered file in a project on this framework: it is the framework's file, behind
the framework's controller, drawing from seventeen of the framework's tables. One project
measured it at 355 of 605 statements, a quarter of everything uncovered anywhere; seeding
these took it to 495 of 605 and the project's total from 93.49% to 95.30%.

Each insert is absorbed on its own, because a table that is not there is a feature this
installation does not have. And anything unique gets a random value —
`passkey_credentials.credential_id` is unique across the table, so a literal would seed
the first test that runs and **silently seed nothing** afterwards: the panel renders once
and its empty state the rest of the time, which looks exactly like a test that passes.

`actingAsAdmin()` is left empty because "an administrator" is a product decision. Without
it the sweep still catches a fatal — an action that refuses is code that ran — but it
stops at the first guard and covers the refusal rather than the screen. A **403 is a
pass** for exactly that reason; demanding 200 everywhere would be asserting that nothing
is protected.

### The allowlist is the safety

`READ_ACTIONS` names the verbs the sweep may request, and it is an allowlist rather than a
denylist on purpose. **A GET to `delete/5` on a controller that does not check the request
method deletes row 5**, and "most controllers check" is not something to bet a suite on —
least of all one that runs on every developer's machine.

Adding a verb is a decision. `ScreenSweepStubIsSafeTest` in the framework fails if a
mutating one appears there, so it is a decision somebody has to make on purpose. `delete`
is refused; `deleteaccount` is allowed and is not an oversight — it is the confirmation
screen that asks for a password, and the deletion itself is a POST.

### `DELIBERATE_404`

An address that answers 404 by design goes there **with its reason**, so "this 404 is
fine" is a decision made once rather than a rule that quietly swallows the next real one.
An email-tracking endpoint that redeems a one-time token is the usual case: there the 404
*is* the feature.


## Saying what request a test is making

`Request::create('/path', 'POST')` is how, and it is worth knowing why the obvious
alternatives do not hold:

```php
$request = Request::create('/debug/grant', 'POST');
```

It sets the statics **and** `$_SERVER['REQUEST_URI']` / `$_SERVER['REQUEST_METHOD']`,
because the framework reads those back. `Request::__construct()` copies
`$_SERVER['REQUEST_METHOD']` over the static whenever that key is set, and the framework
builds requests of its own mid-dispatch — `Controller::_runThroughMiddleware()` does it on
every middleware-guarded action. So this:

```php
Request::$requestMethod = 'POST';   // not enough
```

…lasts exactly until the code under test constructs a request, and then it is whatever
`$_SERVER` says.

**That failure is silent and it looks like a pass.** `CsrfMiddleware` skips safe methods, so
a `POST` that had quietly become a `GET` sailed through the token check the test existed to
prove. It stayed green for as long as some earlier test in the run happened to leave
`REQUEST_METHOD` set to `POST`, and went red the day the order changed.

Writing `$_SERVER` yourself and then calling `Request::resetInstance()` is the other
supported shape, and several suites use it:

```php
$_SERVER['REQUEST_URI']    = '/api/stations';
$_SERVER['REQUEST_METHOD'] = 'POST';
Request::resetInstance();
$request = new Request();
```

`resetInstance()` forgets the framework's derived state and **leaves `$_SERVER` alone** —
deliberately, because clearing it would turn the block above into a request for nothing.

Restore what you changed in `tearDown()`. A leftover `REQUEST_METHOD` is what made the
failure above order-dependent, and the next one will be the same shape.

### `Factory::getRequest()` follows the reset

`Request::resetInstance()` clears the factory's cached request too, so the request the
framework dispatches with is the one your reset produced.

It did not, for a while: the factory cached in a **function** static, which nothing outside
that method can clear. After a reset there were two request objects, and the stale one was
what `Api::exec()` handed to the middleware pipeline. The visible symptom was narrow and
alarming — an endpoint listed in `public_api_paths` answered `403 APIKeyMissing` in tests
and correctly in production, because `ApiAuthMiddleware` reads the request's own URI and the
bootstrap's request had none.

**`Factory::getRequest()` returns by reference, and that is an API.** Substituting a mock
works, and is how several suites here do it:

```php
$request = &Factory::getRequest();
$request = $mock;
```

Which is why the cache still exists rather than being deleted — the reference has to point
at something that outlives the call. `Factory::resetRequest()` is the way to clear it, and
`Request::resetInstance()` calls it for you.

## Posting a form in a test

```php
$client = new TestClient();
$client->get('/register');

$response = $client->submitForm('Create account', ['username' => 'someone']);
```

`submitForm()` reads the form off the page the client last received — the action, the
method, and **every field already in it**, the hidden CSRF input included. `$data`
overrides by name; a field the test does not name keeps what the page rendered, which is
what makes it usable on a settings form with thirty fields and one under test.

The button is matched on its text, its value or its name. A relative action resolves
against the page it came from, and an empty one posts back to the same address — which is
what a browser does and what several of this framework's own views rely on.

It needs `symfony/dom-crawler` and `symfony/css-selector`, the same pair the selector
assertions use; a scaffolded project has both in `require-dev`.

### When there is no page to read — `Session::tokenParameters()`

For a request that is not going through a rendered form — an API call, a POST to an
address nothing links to — the CSRF field is available directly:

```php
$client->post('/account/privacy', ['marketing' => 1] + $session->tokenParameters());
```

It returns one entry: `[field name => fingerprint]`, the same two strings
`getTokenField()` puts in the markup. Pass `true` for the IP-pinned variant, matching
`getTokenField(true)`.

Both of these exist because neither half was reachable. `submitForm()` threw
`not yet fully implemented` while the class documented it as the way to post a form, and
the CSRF field's name is a private property while its value is `getFingerprint()`, which
is also not public — so the only way in was a regular expression over generated HTML, in
every application that tested a form. Those are the highest-value tests in an application
with accounts in it, and the ones most likely to be skipped, because the first hour of
writing one went on this rather than on the behaviour.


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

## Take the connection from `Connection::fresh()`, not from the factory

```php
use Pramnos\Framework\Testing\Connection;

Settings::clearSettings();
Settings::loadSettings($settingsFile);
$db = Connection::fresh();
```

`Factory::getDatabase()` hands back whatever instance already exists, and it was built
from whichever settings were loaded when the **first** class in the run asked for one. A
class that loads its own settings and then calls the factory gets the old object:
`connected` says true while nothing behind it is usable, or `server` is empty and the next
`connect()` falls through to a unix socket —

```
RuntimeException: No such file or directory
```

— reported against a class that has touched none of this. It is order-dependent, so it
appears when a filter or a new test changes what runs first and vanishes when the class is
run alone, which is the worst way for a defect to behave. Three classes had each worked it
out separately and written the same two lines; one had not, and carried the failure.

**And when you park the singleton to put a mock in its place, park the object.**

```php
$dbRef = &Database::getInstance();
$this->originalDb = $dbRef;      // not: clone $dbRef
```

A clone is a different `Database` whose connection parameters are frozen at clone time and
whose `connected` flag says true. `tearDown()` then restores *that* as the singleton, and
every class after it inherits a corpse.

## Build a table from the migration that builds it in production

```php
use Pramnos\Framework\Testing\Schema;

Schema::table('applications', $this->db);
```

**Never hand-write `CREATE TABLE` for a table the framework migrates.** A fixture that
does is testing against a schema nothing else has, and the difference stays invisible until
it is expensive. This repository has already paid for it: fixtures declared
`applications.redirect_uri`, a column no migration has ever created — production registers
the URI in `callback`, and only a *view* aliases it under the other name. So those tests
set a field nothing consulted, took the "no redirect URI registered" branch, and passed.
Four fixtures carried the column; a fifth carried the same wrong assumption in an array
literal.

The cost argument does not survive measurement. On this project's MySQL container, for
`applications`:

| | per call |
| --- | --- |
| hand-rolled `DROP` + `CREATE` | 9.56 ms |
| canonical migrations, `drop` + `up()` | 12.18 ms |
| canonical migrations, table already present | **0.23 ms** |

A migration's `up()` opens with `if (hasTable()) return;`, so the second class to ask pays
0.23 ms instead of building its own 9.56 ms copy. Converting eight fixtures cost nothing
measurable: 16,075 tests in 3:22, against 3:22 before.

`Schema::table()` takes a **name**, not a list of migrations, because the list is the part
that gets written wrong — `applications` is created by one migration, gains `systemuser`
from a second and a wider `callback` from a third, and the first attempt at this named only
two. Add a table by putting its recipe in `Schema::RECIPES`. `Schema::ensure([...])` takes
an explicit list for the cases that genuinely need one.

**Seeding a row means seeding it the way production does.** The canonical `usertokens` has
`token`, `deviceinfo` and `scope` as `TEXT NOT NULL`, and MySQL gives a TEXT column no
default — so a seed that omits one is refused, and a stub that declared them nullable was
hiding that. Converting the fixtures surfaced it in eleven places, and in one production
writer: `Oauth::generateAuthCode()` omitted `deviceinfo`, which meant the framework's own
authorization endpoint could not issue a code at all under strict mode.

**Two fixtures may still be hand-rolled**, and both are about the schema rather than about
a feature:

- a test for a migration, which has to build the *pre-migration* shape to prove the
  migration changes it (`AddTrustedToApplicationsMigrationTest`);
- a characterization test pinning what an older installation's table looked like.

## A test that rebuilds schema, without breaking the ones after it

Some tests need a table in its **canonical** shape rather than whatever the suite happens
to have left there. `Integration/Auth/OAuth2/FullAuthorizationCodeFlowTest` is one: it
drives the OAuth2 client against the framework's own authorization server, and the server
compares the client's redirect URI against `applications.callback` character for character
— a column several tests' hand-rolled `applications` does not have.

The pattern is the one `OAuth2ClientSecretRequiredTest` established: **drop that table and
rebuild it from its own migration**, through `BaseTestCase::runMigrations()`.

```php
$this->db->schema()->dropTableIfExists('#PREFIX#applications');
$this->runMigrations([CreateApplicationsTable::class, WidenApplicationsCallback::class], $this->db);
```

**Drop only what nothing else points at.** That is the whole rule, and it is cheap to get
wrong: `usertokens` carries a foreign key to `applications`, so a test that also dropped
and rebuilt `usertokens` left the constraint dangling and **thirty-eight tests with nothing
to do with OAuth2 failed afterwards** — account changes, permissions, token actions — each
pointing at its own tables, none at the test that caused it. Rebuilding the parent is
survivable because it is put back immediately; rebuilding the child is not.

Check before you drop:

```sql
SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_NAME = 'the_table_you_want_to_drop';
```

Everything else follows the rules below: schema and expensive fixtures once per class, rows
per test. An RSA key pair for an OAuth2 server costs a few hundred milliseconds and is
read-only once generated, so it belongs in `setUpBeforeClass()` like any other.

## Writing a test that does not slow the suite down## Writing a test that does not slow the suite down

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

## Two tests, one framework table, two shapes

The trap that costs an afternoon, and it is not exotic: several tests need `usertokens`,
`tokenactions`, `applications` or `sessions`, and each creates the shape *it* needs with

```php
$this->db->query('CREATE TABLE IF NOT EXISTS `tokenactions` (…)');   // a minimal shape
```

`IF NOT EXISTS` means **whichever test runs first decides the columns**, and the loser fails on
an insert naming one the winner did not create. In isolation both pass. Together, one fails, and
which one depends on the filter you happened to use.

Three rules, in order of preference:

1. **Do not create a table you do not read.** The cheapest fix by far. A test whose subject is
   session revocation needs `sessions` and nothing else — creating the other four to satisfy a
   constructor is how the conflict gets introduced.
2. **Drop before you create**, when you do need one:

   ```php
   $this->db->query('DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#sessions'));
   $this->runMigrations([CreateSessionsTable::class], $this->db);   // from database/migrations/
   ```

   Then the shape is yours whatever ran before, and `runMigrations()` — which returns early when
   the table exists — actually runs.
3. **Build from the real migration, not from a hand-written `CREATE TABLE`.** A hand-rolled shape
   drifts from what production ships, and two hand-rolled shapes drift from each other. The
   framework's own migration is the one definition both tests can agree on.
4. **Include the retrofit migrations, not only the creating one.** Several indexes are added by
   `AddMissingIndexesToExistingTables` rather than by the `create_*` migration — `idx_sessions_userid`
   among them. A table rebuilt from part of its history is not the shipped table, and the cost lands
   on *other* tests: every later query against it scans. That was 40 seconds of suite wall clock,
   measured, in files that had nothing to do with the change.
5. **Ask for a name, never spell it.** A table whose name an installation can override —
   `authserver.user_organizations` is one — has a single reader in the framework
   (`Role::membershipTable()`, `Role::organizationColumn()`), and a fixture that writes the literal
   is a third reader with its own opinion. This one bites hardest on MySQL, where a schema qualifier
   is not a schema: the framework folds `authserver.user_organizations` down to
   `authserver_user_organizations`, `hasTable()` knows about the folding and a raw
   `queryBuilder()->table('authserver.user_organizations')` does not — it asks MySQL for a *database*
   called `authserver`. Use the reader, or `schema()->quoteTable()` for raw SQL.

## `#[CoversClass]` decides what a test contributes to coverage

### A test that names one class but exercises seventeen

The cost of getting this wrong, measured. A tool-discovery test asserts the wire contract of every
registered MCP tool — a unique name, a description long enough to choose by, a JSON Schema that
survives a round-trip — and declared `#[CoversClass(McpServiceProvider::class)]`, which is true of
what it asks for the server and false of what it exercises. Every line of tool code it ran was
discarded: 69 tests, 305 assertions, and a project total that moved from **94.05% to 94.05%**, with
the three methods the class was written for still reading zero hits.

Removing the attribute recovered **76 statements** and changed no code. That is the whole finding:
the round had earned them and the attribute threw them away, invisibly, behind a green run.

Which is the case for declaring nothing at all when the subject is a contract rather than a class.
Naming all seventeen tools would have worked and would have been wrong for the same reason the test
is written the way it is — a tool added tomorrow would silently stop counting. `requireCoverageMetadata`
is not enabled here, so a test with no attribute contributes everything it executes.

**`#[CoversClass]` is a filter, not a label.** Reach for it when a test's subject really is one or
two named classes, and leave it off when the test deliberately runs code outside them.

### And a file with no class needs `#[CoversFunction]`

The same rule, in the shape that is easy to miss. `src/Pramnos/DevPanel/adminer-object.php` declares
one global *function* — Adminer looks the hook up with an unqualified string, so it cannot be a
method — and its test carried `#[CoversClass(AdminerBridge::class)]`. Eleven passing tests, and the
coverage report called the file **0%**, because no attribute on that test named it.

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
#[CoversClass(FileAdapter::class)]      // …and no RedisAdapter
class StructuredOperationParityTest extends TestCase
```

That class asserts that the fallback adapters and the native Redis one behave identically — its
Redis rows pass, `hashSet()` provably reaches the server, and `RedisAdapter` still measured
**0 of 328** when the class was run on its own. Adding the missing attribute took it to 90 with no
other change. The same file reads 222 in a full suite run, because *other* tests declare
`RedisAdapter` and contribute their own lines.

So three numbers for one commit — 0, 90 and 222 — and none of them was a measurement fault. If a
file looks less covered than the tests suggest, check the attributes before you go looking for a
flake.

**Add the attribute only where the class really is part of the test's subject.** The parity test *is*
about all three adapters, so declaring all three is accurate. `PageCacheEdgesTest` merely uses a
Redis adapter as a collaborator — declaring it there would misstate what the test is about and
credit `RedisAdapter` with lines nothing in it asserts on. A coverage number inflated that way is
worse than one that is honestly low.

## Read a coverage report by 0% first, not by percentage

A file at **0%** is a different kind of signal from a file at 70%, and it is worth acting on
before anything else: it means the machinery is written, shipped, and has never been executed —
not by a test, and quite possibly not by anybody. Every 0% file in this repository that has been
worked on has produced at least one real defect, because nothing about it had ever been observed.

Which makes the obvious ranking a trap. Sorting by *percentage* with a "files of at least N
statements" filter — the natural way to avoid noise from three-statement classes — hides exactly
these files, because they tend to be small: a reader, a writer and a service provider are 20 to 40
statements each. In this repository that filter concealed eight files at 0% (189 statements) and
462 uncovered statements in total.

So rank twice:

```bash
# 1. everything at zero, whatever its size
# 2. then the largest absolute gaps
```

Percentage is the least useful of the three orderings on its own: a 1,800-statement file at 86% has
more uncovered code than a 40-statement file at 0%, and the 40-statement file is the one where
something has never run.

## Reflection into a private method covers the algorithm, not the tool

`route-list` had thirteen tests for its routes-file parser, all of them green, and every one reached
it the same way:

```php
$routes = (new \ReflectionMethod(RouteListTool::class, 'parseRoutes'))
    ->invoke($tool, $contents);
```

Which is a fine way to test a parser — the cases are pure string-in, array-out, and driving them
through the public surface would mean writing thirty files to disk. It is also why `execute()` was a
straight line of code that had never run. Finding the candidate files, reading them, stamping each
route with the file it came from, applying the filter while reading, and choosing between the two
answer shapes: none of it was touched by any of the thirteen. The report said 87%, the parser was
exhaustively covered, and the tool had never been called.

So the parser tests stayed and one file was added that goes in through `execute()` against real
files, with `projectRoot()` overridden to a directory the test owns. Six tests, and they immediately
described two behaviours nobody had written down:

- with routes on disk and no attribute controllers — **which is every console application, and the
  console is the only kernel that reaches this tool** — the answer is the keyed report, never the
  flat list;
- the keyed report is in discovery order (`app/routes.php`, `routes.php`, `routes/web.php`,
  `routes/api.php`, and within a file the order written), while only the combined answer is sorted
  by URI, because only it merges two sources with no natural order between them.

Both were correct and neither was asserted anywhere, which is the usual state of a code path that has
only ever been read.

The rule this leaves: **test the private method for the algorithm and the public one for the wiring.**
If every test of a class reaches past its entry point, the entry point is untested no matter what the
percentage says — and a `protected` seam like `projectRoot()` exists so that the public test is cheap.

## Making a write fail is harder than it looks

Four scaffolders carry the same guard:

```php
if (!file_put_contents($filename, $stub)) {
    throw new \Exception("Cannot write middleware file: $filename");
}
```

The obvious way to cover it is to put a directory where the file should go, so the write cannot
succeed. It does not work, and the reason is worth knowing before spending an afternoon on it:

- **`file_exists()` is true for a directory.** Every one of these methods checks "already there"
  before it writes, so the directory trips *that* guard and the write is never attempted.
- **`chmod 000` does not stop root.** The suite runs as root in its container, where an unwritable
  file is still writable and an unwritable directory is still enterable.
- **Making the parent a file works only if the parent does not exist yet.** `@mkdir($dir, 0777,
  true)` fails on a path whose component is a file, the failure is discarded, and the write then
  fails for real — but `src/Middleware` and its three siblings already exist in any tree the suite
  has run in once.

Which leaves a read-only mount, a full disk or a quota: real production conditions, and none of them
producible from a test here. So the guards stay and carry `@codeCoverageIgnoreStart` with that
reason written next to them, the same way `Database::prepareInput()`'s missing-extension branch does.

**Mark a branch ignored only after failing to reach it, and write down what you tried.** The comment
is the part that matters: without it the next reader cannot tell an unreachable branch from one
nobody got round to, and the annotation becomes a way of hiding work rather than recording a fact.

What the tests assert instead is the guard that *does* fire — an occupied path is refused, on all
four, with the message naming the kind of file. Which turned out to be the more valuable assertion
anyway: `create:model` used to overwrite an existing model and report success either way.

## An application's `applicationInfo` cannot be set by a property default

`Application::__construct()` assigns `$this->applicationInfo = self::loadApplicationInfo(APP_PATH .
'/app.php')`, so a test double written the obvious way —

```php
class TestApplication extends \Pramnos\Application\Application
{
    public $applicationInfo = [];      // ← overwritten before any test sees it
}
```

— comes back holding the fixture's namespace, not the empty array. Which is why four `: 'App'`
fallback lines in the scaffolders had never executed: every test that reached them supplied a
namespace without meaning to, and the "unconfigured project" case was unreachable by construction.

Assign it after construction instead:

```php
$application = new TestApplication();
$application->applicationInfo = ['theme' => 'default'];   // non-empty, names no namespace
```

Note the value: `[]` would work here too, but a project whose `applicationInfo` is genuinely empty
is a different case from one that has settings and no namespace, and it is the second that the
fallback is written for.

## A warning is an exception here, but only because a test made it one

`MediaObject::addImage()` wraps `copy()` in `try { … } catch (\Exception $ex)`. That looks like
dead code — `copy()` warns and returns `false`, it does not throw — and it is not, because the test
that covers it installs a handler first:

```php
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_WARNING);

try {
    $media->addImage($sourceDirectory, 'test_media_module');
} finally {
    restore_error_handler();
}
```

`ErrorException` extends `Exception`, so the `catch` fires and the arm is reachable. This is the
established way to cover a warning-shaped failure in this suite, and it is worth knowing before
"fixing" one of these guards into a return-value check: the guard is right, the arm is tested, and
rewriting it breaks the test that was covering it.

Two details that follow:

- **`@` does not stop a custom handler.** PHP 8 calls the handler regardless; it is the handler's job
  to consult `error_reporting()`. So `@copy(…)` inside the source does not protect the caller from a
  handler the test installed.
- **The arm is only reachable while the handler is installed.** Which is why the neighbouring
  `unlink()` guard is still uncovered: reaching it needs a copy that succeeds and a delete that
  fails, and as root in a container the second does not happen.

## Two ways to lose a row you just wrote

Both cost an afternoon in `MediaObject`, and neither is about the code under test.

**`load()` reads through the query cache.** It is `query($sql, true, 600, 'media')` — cached for ten
minutes, keyed on the SQL text. A test that writes a row and reads it back gets the cache unless it
flushes:

```php
\Pramnos\Framework\Factory::getDatabase()->cacheflush('media');
```

Worse in a class that recreates its tables per run, because `mediaid` restarts at 1 and the cached
row belongs to a *different test's* media — same id, same SQL, plausible-looking answer.

**`MediaObject` declares no constructor.** So `new MediaObject($id)` accepts the argument, ignores
it, and hands back an empty object — no error, no warning, and `->thumbnails` is the empty default.
`new MediaObject(); $media->load($id);` is the loading form. An assertion that a freshly written row
came back empty is the symptom, and the row is fine.

## `No such file or directory` from a database that worked under a filter

A class that connects fine on its own and fails in the full suite with a socket error is not a
flaky database. It is the singleton: whichever test ran before left
`Database::getInstance()` holding another lane's connection settings, and `Factory::getDatabase()`
hands that same object back rather than building one from the settings just loaded.

Drop the reference first, which is what the integration tests here already do:

```php
Settings::loadSettings($this->settingsFixture());

$reference = &\Pramnos\Database\Database::getInstance();
$reference = null;                       // ← the part that is easy to leave out

$db = \Pramnos\Framework\Factory::getDatabase();
if (!$db->connected) {
    $db->connect();
}
```

The symptom is worth recognising because it points the wrong way: `No such file or directory` is a
socket path, so it reads as "the database is not running" — and under `--filter` the same class
passes, which reads as "the suite is interfering with itself" rather than "this class did not ask
for its own connection".

Wrap the connect in a `try`/`markTestSkipped()` as well. A class whose backend is genuinely absent
should skip, not error: the two lanes here mean every dual-backend class runs twice, and one of the
two may have nothing to talk to.

## A wrong-case namespace is a bug that only an uncovered branch can hide

Thirty-six references in the Cache subsystem were written `\pramnos\Logs\Logger` — lowercase `p`.
PHP resolves class names case-insensitively, so this looks harmless, and it is not:

- **If the class is already loaded**, PHP finds it in its own table and the call works.
- **If it is not**, the autoloader is asked for the literal string `pramnos\Logs\Logger`, and
  Composer's PSR-4 map is keyed on `Pramnos\` — a case-sensitive miss. `Error: Class
  "pramnos\Logs\Logger" not found`.

So whether it works depends on what else the process happened to load first. In these files every
one of them sat inside a `catch`, which is the worst possible place for a load-order-dependent
fatal: the line only runs when something has already gone wrong, and it turns a handled failure
into an unhandled one. The Redis adapter's sixteen `catch` arms exist so that losing the cache
degrades the application rather than stopping it, and every one of them would have raised
`Class not found` instead.

It surfaced the moment a test executed one of those arms in isolation, and not before, because the
covered arms all ran in processes where the Logger was loaded already. That is the general shape:

**A wrong-case reference in a rarely-taken branch cannot be found by running the application.** It
needs either the branch executed in a cold process, or a grep:

```bash
grep -rn '\\pramnos\\' --include='*.php' src/     # should print nothing
```

Worth running after any bulk edit that touches namespaces, and worth remembering when a class that
demonstrably exists is reported as not found.

## A green filtered run is not evidence that your new test ran

Seven tests appended to an existing file, `./dockertest --filter UsersControllerTest`, `OK (62
tests, 183 assertions)`. Read as "they pass". They had not run at all.

The file ends with two classes — the `TestCase` and a `UsersProbe` subclass that exposes protected
methods — and the insertion landed in the second one. A test method on a class that is not a
`TestCase` is just a method: PHPUnit never sees it, nothing fails, and the run is green because the
*other* 62 tests are fine.

The full suite is what said so, and only by its count: 14,853 before and 14,853 after. Same
assertion total, too.

So when adding to an existing file:

- **Check the test count moved**, by the number of methods you added. `OK` on its own says nothing
  about the tests you just wrote.
- **Look at where the file ends.** A helper class, a probe subclass, a namespace block for function
  shims — several files here have all three after the `TestCase`, and "append before the last
  closing brace" puts your work in the wrong one.
- **A new test that has never failed has not been demonstrated to work.** Break it once on purpose
  if it went in green on the first run — the same rule as for any assertion, and this is the failure
  mode it catches.

## `@codeCoverageIgnore` must be the whole comment, or it does the opposite

php-code-coverage 11 matches these annotations by **exact string comparison**:

```php
if ($comment === '// @codeCoverageIgnoreStart' ||
    $comment === '//@codeCoverageIgnoreStart') {
```

So the natural thing to write —

```php
// @codeCoverageIgnoreStart — reached only when the socket is already closed
```

— matches nothing. Which would merely be a no-op, except for the `End` handler:

```php
if ($comment === '// @codeCoverageIgnoreEnd' || ...) {
    if (false === $start) {
        $start = $token[2];
    }
    $this->ignoredLines[$filename] = array_merge(…, range($start, $token[2]));
}
```

`$start` is **not reset after use**. A `Start` that failed to match leaves it holding the line of the
last one that *did*, and the next `End` then ignores everything from there to itself. In a file with
several annotations and explanations on the `Start` lines, whole regions disappear from the report —
and nothing warns you, because the numbers only get better.

Measured here: **453 statements across 32 files** were excluded by accident. `Init.php` alone was
missing 157, `ProjectResync` 122, `MakeCommandBase` 59. Correcting the syntax put them back and the
project total still went **up**, 94.62% to 94.71%, because most of the accidentally-excluded code is
covered — the exclusions were not hiding untested code, they were hiding the size of the codebase.

So:

- **Put the reason on its own line**, above or below:

  ```php
  // Unreachable in the suite: the guard above is true for a directory.
  // @codeCoverageIgnoreStart
  ```

- **A docblock is different.** There the check is `str_contains($docComment->getText(), '@codeCoverageIgnore')`,
  so `* @codeCoverageIgnore Requires a live server` works fine. The exact-match rule is only for `//`
  comments.
- **Check it took effect.** An ignored line is *absent* from `clover.xml`, not present with a count
  of zero:

  ```bash
  grep -c 'line num="268"' coverage/clover.xml    # 0 means ignored
  ```

- **Audit with a grep**, because this fails silently:

  ```bash
  grep -rn '// *@codeCoverageIgnore' --include='*.php' src/ \
      | grep -vE '// @codeCoverageIgnore(Start|End)?$'
  ```

## Assert once on a derived value, not once per item found

A loop of assertions over whatever a method discovered —

```php
foreach ($this->directories() as $directory) {
    $this->assertDirectoryExists($directory);
}
```

— makes the test's contribution to the suite's assertion total depend on how much the checkout
happens to have. Two identical runs reported 44,287 and 44,290 assertions, and the difference was
this: one run found a theme directory the other did not.

Nothing fails, and that is the cost. The assertion total is the cheapest signal there is for "did
this change add assertions or quietly lose some" — the same signal that caught seven tests landing
in the wrong class earlier — and a variable total is a signal you can no longer read.

Assert the property instead of iterating:

```php
$this->assertNotEmpty($directories, 'nothing was found, so the filter proves nothing');
$this->assertSame(
    $directories,
    array_values(array_filter($directories, 'is_dir')),
    'a directory that does not exist was returned'
);
```

Two assertions, always. And note the first one: **a loop over an empty array asserts nothing**, so a
test that iterates discovered data needs a non-empty check whether or not it iterates. PHPUnit says
so — *"This test did not perform any assertions"* — but only when the loop is the *whole* test.

## A test that re-implements the code under test will pass when the code changes

The sharpest version of "a test that replaces a method is not a test of it", because this one had a
good reason and a name that said it was doing the opposite.

`ApiAdmin::search()` caps the omnibox limit at twenty — an endpoint that took the number from the
request would be a denial-of-service endpoint with a friendly name. The test called
`testTheOmniboxLimitIsCapped()`, and its probe did this:

```php
public function search(): mixed
{
    if (($denied = $this->guard('search')) !== null) {
        return $denied;
    }

    // a copy of the line under test
    $this->searchLimit = min(20, max(1, (int) Request::staticGet('limit', 5, 'get', 'int')));

    return Response::json([]);
}
```

The reasoning in its docblock was sound — running six real searches would assert the registry's
behaviour rather than the cap. The consequence was not: **the test asserted its own arithmetic.**
Change the source to `min(500, …)` and it still passes, because the source is never called.

The fix is to record from *below* the thing under test, not in place of it. One protected line in
the controller —

```php
protected function searchRegistry(string $term, int $perSource): array
{
    return \Pramnos\Search\Registry::query($term, $perSource);
}
```

— and the probe overrides that instead. The real `search()` runs, the real cap computes, and the
number asserted is the one the endpoint produced. Two assertions became possible that were not
before: that the term comes from `q`, and that the default is five.

**The test to run on your own probes:** for each method it overrides, ask whether the source could
change and the test still pass. If the override contains a copy of any expression from the source,
the answer is yes.

## When the gap is in the environment, fix the environment

Two branches in this framework had no covered line for a reason that was not about the tests at all.

**APCu.** The migration fingerprint cache prefers APCu and falls back to a marker file. APCu is the
path that runs in production — it is the reason the cache exists — and it was unreachable here
twice over: the extension was not in the image, and `apc.enable_cli` defaults to `0` even once it
is. Both are now set in the `Dockerfile`. Each CLI process gets its own empty cache, so nothing
carries between tests.

**A fetchable address.** `OutboundUrl` refuses every private, loopback, link-local and reserved
address, which is the whole point of it — so nothing a container can reach is fetchable, and its
socket path had never opened. The temptation is to widen the guard for tests: change `self::` to
`static::` so a subclass can override `isPublicAddress()`, or add a flag. **Do not.** A guard that a
test can relax is a guard an application can relax by accident.

Give the environment a legitimate address instead. `docker-compose.yml` puts the test container on a
second network in `203.0.113.0/24` — TEST-NET-3 from RFC 5737, reserved for documentation and routed
nowhere — and PHP's `FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE` does not exclude the documentation
blocks, so an address in it reads as public while the container holding it can still only reach
itself. Apache is already listening, so `http://203.0.113.2/…` is a real fetch of a real file over a
real socket, with the guard untouched.

The tests that need it skip when it is absent, so a checkout run outside this compose file does not
fail on an address it cannot dial:

```php
$probe = @fsockopen('203.0.113.2', 80, $errno, $errstr, 2);
if ($probe === false) {
    $this->markTestSkipped('The outbound fixture address is not configured on this host.');
}
```

The general rule: **before deciding a branch is untestable, ask whether it is the environment that
cannot reach it.** An extension that is not installed, a SAPI setting that defaults off, an address
family a container does not have — all three look exactly like unreachable code, and none of them
is.

## `loadFromConfig()` only adds — reset first

`FeatureRegistry::loadFromConfig()` enables what you pass and **never disables anything**, so

```php
FeatureRegistry::loadFromConfig([]);        // a no-op, not "no features"
```

leaves whatever an earlier test enabled still enabled. Invisible in production, where it runs once
at boot; in a suite it means a test asserting "refused without the `devpanel` feature" can pass
for the wrong reason, or fail for one — which is how it was found.

```php
FeatureRegistry::reset();                       // clears known *and* enabled
FeatureRegistry::loadFromConfig(['devpanel']);  // then declare exactly what this test wants
```

`reset()` exists for this and says so in its docblock. Two things to remember with it:

- after a reset the next call re-registers the built-in defaults, so `getKnown()` is enough to
  have a registry that knows every feature and enables none;
- **restore what the suite runs with in `tearDown()`.** The registry is process-wide, so a test
  that leaves it empty makes the next test's `isEnabled('auth')` answer false, and that test fails
  for a reason that has nothing to do with it.

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

A full run once produced two errors and nothing else:

```
UnexpectedValueException: RecursiveDirectoryIterator::__construct(
  …/scaffolding/themes/tailwind/views): Failed to open directory: No such file or directory
```

The directory is tracked in git, is not ignored, nothing in the repository writes under
`scaffolding/`, and **its mtime on the host predated the run by seventeen days** — so it had
not been removed and put back. What went missing was the container's *view* of it. The
repository is a bind mount, and on macOS Docker Desktop that is VirtioFS (`fakeowner` over
`/run/host_mark/Users`), which under load can answer `ENOENT` for a directory that is on the
disk throughout. It is rare: a watcher stat-ing the same path as fast as the mount allows,
for a whole 16,000-test run, did not reproduce it once.

`Tree::files()` looks **three times**, 50 ms apart, and then raises a `RuntimeException`
naming the path and saying how to tell the two explanations apart.

It was two, on the reasoning that one retry is for a mount that blinked and a loop is a way
to tolerate a directory that is genuinely gone. The reasoning holds and the number was
wrong: a new sweep over the whole of `scaffolding/` — a large tree — lost *both* attempts
twice in eight runs, which is a flaky test rather than a rare one, and a flake is
indistinguishable from a real failure to whoever reads it next. Three is still a small
number chosen for the same reason two was: a directory that is actually missing costs
100 ms and then raises.

### The two shapes it replaces, and why both were wrong

- `new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir))` throws an
  `UnexpectedValueException` that reads like a bug in the test rather than a directory that
  could not be read.
- `glob($dir . '/*')` returns `false`, so the sweep quietly examines nothing and **passes**.
  That is the same failure mode the emptiness assertions on forty-five sweeps exist for; a
  sweep still asserts its list is non-empty, because `Tree::files()` legitimately returns
  `[]` for a directory that is there and holds nothing.

`Tree::matching($pattern)` is the glob half, with the same one retry. The emptiness
assertion alone turned out not to be enough: it makes a blink a red test rather than a
silent pass, which is right, but the red is still a failure nobody can act on — and one
fired in a full run the day after `files()` landed, over a directory whose mtime had not
moved in a month. It returns `[]` for a pattern that genuinely matches nothing, and only
pays the pause when there is nothing to report.

### And `Tree::read($path)` for the file itself

The listing is not the last chance to blink. A sweep lists a directory, then reads each
path it was given — and that read is the call that answers `false` with a warning PHPUnit
turns into an error, on a file whose host mtime has not moved in weeks. It happened twice
in eight runs of a new sweep, after `Tree::files()` had already made the listing safe.

```php
$body = Tree::read($path);   // not file_get_contents()
```

Same attempts, same `RuntimeException` naming the path. An **empty file is `''`, not a
failure**, which is the distinction the retry depends on: reading the two as the same
thing would pause 50 ms on every empty file in a sweep and then raise on one.

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

The scheduler was crashing the server:

```
background worker "Retention Policy [9262]" was terminated by signal 11: Segmentation fault
DETAIL:  Failed process was running: CALL _timescaledb_functions.policy_retention()
LOG:  terminating any other active server processes
```

Seven times in one day, roughly once per full suite run. Each one puts PostgreSQL into
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

**A project scaffolded before this** has its own copy and version control will not
update it — the same caveat as the lock block below. Check the last lines of your
`dockertest`; if there is no `exit $phpunit_status`, add the two pieces above.

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

## Code the framework *writes* is not covered by the guards over code it *runs*

`MissingClassReferenceTest` resolves every `\Pramnos\…` name in `src/`, `LegacyClassReferenceTest`
catches the CMS-era shapes, and between them a class name that does not exist cannot reach a
release — in the framework's own code. Neither sees a single line of what `create:*` and `init`
emit, and that is the larger surface: **the framework ships far more code than it runs.**

The two sweeps that cover it are `EveryStubIsSyntacticallyValidTest` (does it parse) and
`GeneratedCodeNamesRealClassesTest` (do the names resolve). A stub cannot be tokenised the way a
source file can, because it is a template rather than loadable PHP, so the second one works on
text and has to earn its precision:

- **Strip comments first.** A comment naming a class is prose. One explaining *what a line
  replaced* names, by definition, a class that no longer exists.
- **Skip `namespace` and `use` statements.** `namespace App\Controllers;` is not a class, and a
  guard that reports it reports it for ever.
- **Only sweep roots the framework actually depends on.** `App\…` in a stub is the generated
  application's own namespace and cannot resolve here; sweeping it would flag every template.

Both points are the same point: a guard that reports things nobody can fix is a guard somebody
deletes, and the value of a mechanical sweep is entirely in being believed.

### Writing one of these

Verify it bites before you trust it. Break a stub on purpose, watch the test fail with a message
that names the file and the offending name, then restore. A sweep is the one kind of test where
passing on a clean tree and matching nothing at all look identical — and both of the framework's
stub sweeps were, at first draft, unable to fail.

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
