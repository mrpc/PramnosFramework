---
use_cases:
  - Testing the API and the site in one suite without one answering for the other
  - Writing a test for a controller, model or HTTP endpoint
  - Using factories or seeders to build test data
  - Choosing a base test case, or testing without booting the application
  - Creating users in a test without leaving them in the test database
---

# Pramnos Testing Guide

Pramnos provides comprehensive testing infrastructure including HTTP testing, factory generation, and seeding.

The testing pages:

- **Testing** (this page)
- [Databases in tests](Pramnos_Testing_Databases_Guide.md)
- [A fast suite, and coverage that means something](Pramnos_Testing_Speed_Coverage_Guide.md)
- [Pitfalls: tests that pass for the wrong reason](Pramnos_Testing_Pitfalls_Guide.md)
- [Process state, and ./dockertest](Pramnos_Testing_Runner_Guide.md)
- [Testing JavaScript](Pramnos_Testing_JavaScript_Guide.md)

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

**Every request starts fresh.** Before each call the client resets the routing state, the
document and the request identity. So a second `get()` does not inherit the caller that the
first request's middleware sealed (a bearer token, a session exchange), just as on a server,
where each request is a new process. If a test needs a signed-in request, establish the
identity through the request itself: a session via `loginUser()`, or a header.

There is no HTTP base class with request methods on `$this`: a test extends `BaseTestCase`
and makes its requests through a `TestClient`.

The path is routed the way the server routes it. `TestClient` sets `REQUEST_URI` and the
`$_GET['r']` that classic MVC routing reads, so `/admin/users` reaches the admin Users
controller rather than falling through to the default one. An assertion that a guarded
address refuses a guest is therefore made against that address, not against the home page.

### What a request writes

Anything the code under test `echo`es during a request is part of the response, and
`TestClient` captures it — placed in front of the body, which is the order a browser
receives it in.

```php
// A controller that writes straight to the output stream
$body = (string) $client->get('/Legacy')->getResponse()->getBody();
$this->assertStringContainsString('what it echoed', $body);
```

That includes the `<script>window.location=…</script>` fallback that
`Application::redirect()` writes before ending a request: it lands in the response, not
between PHPUnit's progress dots, and a controller that echoes its body can be asserted on.

### One client, many requests

A web request builds an `Application`, serves one URL and ends, so a good deal of
per-request state is per-request only by accident. A `TestClient` keeps one
application across every call, and so does a worker or any long-running server —
which is where that shows.

`Application::beginRequest()` re-derives it, and `TestClient` calls it for you
before each request:

```php
$client->get('/admin');       // admin theme, Dashboard as the default controller
$client->get('/');            // site theme again, not the admin one left from the last request
```

It also resets `Request`'s statics. Those matter for the same reason: routing state
is only recomputed when there is a path to route, so without the reset a request to `/`
would serve whatever controller the request before it had resolved.

Each request also gets a fresh document, because what a page adds to one (content, enqueued
scripts, the head) belongs to that page. What the application *registered* in `init()`
(`registerScript()`/`registerStyle()` in `registerVendorLibraries()`) is carried onto it,
none of it loaded yet, as a real request starts with it. So a test can assert that a page
loads its chart library.

If you handle several requests in one process yourself, call `beginRequest()` per
request. A single-request process needs nothing: the constructor calls it.

### The site and the API in one suite

A suite usually tests both applications: the site and the API. They share a process, so
which one is *current* (the one `Application::getInstance()` and a bare `new TestClient()`
answer with) matters.

- **A bare `new TestClient()` drives the current application.** In a test that builds the
  API first (`new \App\Api()`), that is the API; otherwise it is the site.
- **Every test starts with the site current.** `BaseTestCase::setUp()` calls
  `Application::resetCurrentInstance()`, so an API built by one test does not answer for
  the next test's requests.
- **The API is registered as `api`, the site as `default`.** Building one never replaces the
  other: `Application::instanceNamed('default')` is still the site after the API is built.
- **During a request, the client's application is the current one.** Code the request runs
  that asks for "the application" gets the one handling it, then the previous one is put
  back (`makeCurrentInstance()` / `restoreCurrentInstance()`).

To be explicit, pass the application: `new TestClient(new \App\Api())` for the API,
`new TestClient(Application::instanceNamed('default'))` for the site. A test that does not
extend `BaseTestCase` and mixes the two can call `Application::resetCurrentInstance()` in its
own `setUp()`.

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

It sets `logged` and `uid`, the keys `Session::staticIsLogged()` reads, and also leaves
the web-session token a real sign-in creates, so a same-origin call to the application's
own API works from the signed-in test as it does from a page.

### CSS selector assertions

`assertSelectorExists()` and friends need two packages:

```bash
composer require --dev symfony/dom-crawler symfony/css-selector
```

`pramnos init` puts both in the project's `require-dev`. A project that lacks them
adds them with the command above: they are dev dependencies of the framework, and a
dependency's dev dependencies are never installed, so without them the three selector
assertions throw a missing-class error. They stay out of `require`: nothing in
production parses HTML.

### Available request methods

```php
$client->get('/path', $headers);
$client->post('/path', $data, $headers);
$client->put('/path', $data, $headers);
$client->delete('/path', $data, $headers);
$client->call('POST', '/path', $parameters, $headers);
$client->submitForm('Save', $data);          // finds the form, fills it, posts it
```

The data travels as a real request's would. For POST it is in `$_POST` and in the body. For
PUT, PATCH and DELETE it is **only in the body**, because PHP never fills `$_POST` for those
methods. A handler reads it with `$request->body()`, or
`Request::rawBody()`. A handler that reads `$_POST` on a PUT fails here as it would in
production.

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
hundred are the point. Without it, adopting the sweep can make measured coverage *fall*,
because the screens it adds to the count are counted at their emptiest.

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
the framework's controller, drawing from seventeen of the framework's tables. Without a
row in each, most of its panels render only their empty state.

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

Two request objects after a reset would be a real fault: `Api::exec()` hands the factory's
request to the middleware pipeline, and `ApiAuthMiddleware` reads that request's own URI. A
stale one with no URI makes an endpoint listed in `public_api_paths` answer
`403 APIKeyMissing` in tests while it works in production.

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

Use one of these two rather than a regular expression over the generated HTML: the
field's name is a private property of the session and its value comes from a method that
is not public, so these are the supported ways to reach them.


## Factories

### Model factories

A model factory builds instances of an `OrmModel`. It extends
`Pramnos\Support\ModelFactory`, names the model it produces and returns the attributes
from `definition()`:

```php
namespace App\Models;

use Pramnos\Support\Faker;
use Pramnos\Support\ModelFactory;

class PostFactory extends ModelFactory
{
    protected string $model = Post::class;

    public function definition(): array
    {
        $faker = Faker::create();

        return [
            'title'  => $faker->sentence(),
            'email'  => $faker->email(),
            'active' => 1,
        ];
    }
}
```

`Faker::create()` returns an instance (the default locale is `el_GR`), and the generators
are methods on it: `$faker->userName()`, `$faker->email()`, `$faker->unique()->safeEmail()`.

### Using a model factory

The model finds its factory by convention (`{Model}Factory` in the same namespace), or
through a `protected static string $factory` property on the model:

```php
$post  = Post::factory()->create();                  // one, saved
$posts = Post::factory()->count(10)->create();       // ten, saved; an array
$post  = Post::factory()->create(['active' => 0]);   // with overrides
$post  = Post::factory()->make();                    // one model instance, not saved
$posts = Post::factory()->state(['active' => 0])->count(3)->create();
```

`create()` calls `save()` on each model. With a count above one, `make()` and `create()`
return an array of models.

### Row factories for seeders

`Pramnos\Database\Factory` is the table-level counterpart: it declares `$table` and
`definition()`, and `make()` / `create()` return column arrays rather than models, with
`create()` inserting them. It is what a seeder's `$this->factory()` builds.

```php
use Pramnos\Database\Factory;

class UserRowFactory extends Factory
{
    protected string $table = 'users';

    public function definition(): array
    {
        return [
            'username' => $this->faker->userName(),
            'email'    => $this->faker->unique()->safeEmail(),
        ];
    }
}
```

## Seeders

### Writing a seeder

A seeder extends `Pramnos\Database\Seeder` and implements `run(): void`. The return type
is part of the parent's signature, so a `run()` without it fails when the class loads.

```php
<?php
// database/seeds/UsersSeeder.php

use Pramnos\Database\Seeder;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Fifty rows through a row factory
        $this->factory(UserRowFactory::class)->count(50)->create();

        // Or one specific row
        $this->insert('#PREFIX#users', [
            'username' => 'admin',
            'email'    => 'admin@example.com',
        ]);

        // Another seeder, composed rather than repeated
        $this->call(PostsSeeder::class);
    }
}
```

`db:seed` takes the class name from the file name (`UsersSeeder.php` → `UsersSeeder`), in the
file's namespace when it declares one.

### Running seeders

```bash
php vendor/bin/pramnos db:seed                       # every seeder in database/seeds/
php vendor/bin/pramnos db:seed UsersSeeder           # one, by class name
php vendor/bin/pramnos db:seed --path=/other/seeds   # another directory
```

In a test, run a seeder directly:

```php
(new UsersSeeder())->run();
```

## Test Cases

### Setup & Teardown

```php
use Pramnos\Framework\Testing\BaseTestCase;

class UserControllerTest extends BaseTestCase
{
    public function testAnAdministratorSeesTheUsers(): void
    {
        // Arrange — erased, with everything that belongs to it, when the test ends
        $adminId = $this->createTestUser(['usertype' => 99, 'password' => 'correct horse']);

        // A user the code under test creates is handed over the same way
        $memberId = $this->trackTestUser($this->signUp('someone@example.test'));
        // ...
    }
}
```

**A user a test creates is erased when the test ends.** `createTestUser()` inserts a user with
a unique username and email. Any column of `users` can be set, and `password` is hashed the
way the framework hashes it. `trackTestUser()` hands over a user that the code under test
created. `BaseTestCase::tearDown()` erases them all through `Pramnos\Auth\AccountErasure`,
the same erase an account deletion performs. That covers the framework's tables (tokens,
roles, memberships, notifications, push subscriptions…) and the application's own rows,
through its `account.data_erase` listeners. An application therefore describes once what
belongs to a person, and its tests clean up by that description.

A `tearDown()` of your own must call `parent::tearDown()`. A failed erase is raised as a
test error. A user that cannot be removed is carried by every later run, and that is how a
suite slows down without anything failing.

Never empty a shared table in `tearDown()` (`truncate()`, `DELETE` without a `WHERE`). It
removes other tests' fixtures, and on a misconfigured run it removes the development
database's rows.

### Rows a test class leaves behind

Users are created in many places that a test does not see, such as a sign-up the code under
test performs or a fixture inserted by hand. So `BaseTestCase` also watches tables. When a
class starts, it notes the highest key in each watched table. When the class ends, every row
above that key that is still there is removed: a user through `AccountErasure`, any other
row by a `DELETE`. When the suite ends, it prints which classes left what:

```
Rows left behind by test classes, removed when each class ended:
  Tests\SignUpTest: users 3
Create users with createTestUser() or hand them over with trackTestUser().
```

`users` is watched by default. An application adds its own tables, each one keyed by an
increasing number, in its base test case:

```php
protected static array $watchedTables = [
    '#PREFIX#users' => 'userid',
    'organizations' => 'organization_id',
];
```

The watch starts in `setUp()`, on a connection that is already open. A test class whose
`setUp()` does not call the parent's can start it with `$this->watchForRowsLeftBehind()`.
A class that defines `tearDownAfterClass()` must call `parent::tearDownAfterClass()`.

### Rebuilding an overgrown test database

The test database is kept between runs. Cleanup stops it from growing, but a table that
grew before the cleanup existed, or that is not watched, can still slow every run down.
Give it a limit in `app/config/testsettings.php`:

```php
'test_database' => ['rebuild_above' => ['users' => 5000, 'notifications' => 100000]],
```

Before the run, `TestEnvironment` counts those tables. When one is over its limit, it drops
the test database, says so on STDERR, and builds it from nothing, which costs one full
migration. Keys are the table names in the database, with the prefix on MySQL. A table or a
database that does not exist counts as empty. The setting is off unless you set it.

Whenever `TestEnvironment` creates the test database — the first run, after a rebuild, or after
you dropped it yourself — it first forgets what was remembered under the database's name: the
`var/migrations/*.verified` markers (and their APCu entries), which would otherwise say the
migrations are done, and the connection's SQL cache, which would otherwise answer the settings of
the database that is gone. Both are keyed on the connection's name, so neither can tell a new
database from the old one by itself.

### Writes are real

`BaseTestCase` does not wrap a test in a transaction: what the code under test writes is
in the database, where the assertions below can see it. Cleanup is by ownership instead.
Users go through `createTestUser()` / `trackTestUser()`, and other rows through the watched
tables above. A class that owns its tables extends `DatabaseTestCase` (see
[Owning a schema](Pramnos_Testing_Databases_Guide.md#owning-a-schema-without-paying-for-it-per-test)).

### Database Assertions

```php
// Check record exists
$this->assertDatabaseHas('users', [
    'email'  => 'john@example.com',
    'active' => 1,
]);

// Check record doesn't exist
$this->assertDatabaseMissing('users', [
    'email' => 'deleted@example.com',
]);

// Count records
$count = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
    ->table('#PREFIX#users')->where('active', 1)->count();
```

## Complete Example

```php
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Testing\TestClient;

class ProfileApiTest extends BaseTestCase
{
    private int $userId;
    private TestClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Erased, with everything that belongs to it, when the test ends
        $this->userId = $this->createTestUser(['email' => 'someone@example.test']);
        $this->client = new TestClient();
    }

    public function testAUserCanViewTheirProfile(): void
    {
        $this->client
            ->get('/api/v1/profile', ['Authorization' => 'Bearer ' . $this->tokenFor($this->userId)])
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'someone@example.test');
    }

    public function testAUserCanUpdateTheirProfile(): void
    {
        $this->client
            ->call('PATCH', '/api/v1/profile', ['email' => 'new@example.test'],
                ['Authorization' => 'Bearer ' . $this->tokenFor($this->userId)])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['userid' => $this->userId, 'email' => 'new@example.test']);
    }

    public function testAGuestIsRefused(): void
    {
        $this->client->get('/api/v1/profile')->assertStatus(401);
    }
}
```

`tokenFor()` stands for however the application issues an access token. The client has
`get`, `post`, `put`, `delete`, `call` (any method, `PATCH` included) and `submitForm`;
the response's assertions are the ones in the table above.

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
