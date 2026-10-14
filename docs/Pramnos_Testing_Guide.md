---
use_cases:
  - Testing the API and the site in one suite without one answering for the other
  - Writing a test for a controller, model or HTTP endpoint
  - Using factories or seeders to build test data
  - Choosing a base test case, or testing without booting the application
  - Changing the debug toolbar's JavaScript, or any asset the framework ships
  - Running the linter or the JavaScript tests
  - Writing unit tests for an application's own page scripts
  - Testing a page script that uses jQuery, fetch or the framework's pf-*.js
  - Asserting that an action broadcast a realtime event
  - Creating users in a test without leaving them in the test database
  - Running the framework's own suite in parallel, or a test that fails only in parallel
  - Finding why a test passes on its own and fails in the full suite
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

### Writes are real

`BaseTestCase` does not wrap a test in a transaction: what the code under test writes is
in the database, where the assertions below can see it. Cleanup is by ownership instead.
Users go through `createTestUser()` / `trackTestUser()`, and other rows through the watched
tables above. A class that owns its tables extends `DatabaseTestCase` (see
[Owning a schema](#owning-a-schema-without-paying-for-it-per-test)).

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
run alone, which is the worst way for a defect to behave.

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
it is expensive. `applications` is the standing example: production registers the redirect
URI in `callback`, and only a *view* aliases it as `redirect_uri`. A hand-written fixture
with a `redirect_uri` column sets a field nothing consults, takes the "no redirect URI
registered" branch, and passes.

The cost argument does not survive measurement. On this project's MySQL container, for
`applications`:

| | per call |
| --- | --- |
| hand-rolled `DROP` + `CREATE` | 9.56 ms |
| canonical migrations, `drop` + `up()` | 12.18 ms |
| canonical migrations, table already present | **0.23 ms** |

A migration's `up()` opens with `if (hasTable()) return;`, so the second class to ask pays
0.23 ms instead of building its own 9.56 ms copy.

`Schema::table()` takes a **name**, not a list of migrations, because the list is the part
that gets written wrong — `applications` is created by one migration and then altered by
seven more (`systemuser`, a wider `callback`, `trusted`, token lifetimes…), and a list that
misses one builds a table production does not have. Add a table by putting its recipe in `Schema::RECIPES`. `Schema::ensure([...])` takes
an explicit list for the cases that genuinely need one.

**A stub is replaced, not kept.** For the tables in `Schema::SENTINELS` — `applications`,
`usertokens`, `users`, `userdetails`, `settings`, `authserver.permissions` and
`authserver.oauth2_device_codes` — `Schema::table()` checks for a column every real copy has
(`apikey`, `token`, `username`, `fieldname`, `setting`, `granted_by`, `user_id`). A table that exists without it is a stub that some test
built and left behind, so it is dropped and rebuilt. Otherwise every migration's `up()` would
return early because the table exists, and the next insert would fail on a column the stub
never had.

**Calling it from `setUp()` costs one query.** The first call in a process runs the recipe
and records the table's shape: its columns with their types and nullability, its indexes,
its constraints and its triggers. Later calls compare that shape with the table, in one
catalogue query. A table that was dropped is built again. A table that a test changed after
it was built (a column added or retyped, an index or foreign key dropped, a trigger
installed) is dropped and built again. Running the recipe over it would not be enough,
because every create migration returns early on a table that exists.

So a test may change a recipe table freely, and the next call puts it back. Rows go with
it: a table that comes back has no rows.

Tables a recipe depends on are built first: `usertokens` builds `users` and `applications`,
whose rows its foreign keys point at.

**`User::setupDb()` is these recipes.** It builds `users`, `userdetails` and `usertokens` through
`Schema::table()`, then adds the two group tables and the Guest row at `userid` 1. It is
deprecated for new tests: call `Schema::table()` for the tables a test reads.

**Both create the PostgreSQL schema first.** A migration names the schema its table lives in
(`create_authserver_schema`, `create_pramnos_schema`, `create_applications_schema`) in
`$dependencies`, and `Schema::ensure()` runs that one before it. The migration tests drop
these schemas. Without that ordering, a test that builds `authserver.loginlockouts` would
fail with `schema "authserver" does not exist`, but only in a run where a migration test had
gone first.

**Ensure every table the code under test reads, not only the one it writes.** `User::load()`
reads `userdetails`, `Apikey::getData()` resolves its owner through `users`, and a push
retention policy on MySQL is a row in `pramnos.framework_policies`. A table the test did not
ensure is one that an earlier test in the same run may have dropped.

**Seeding a row means seeding it the way production does.** The canonical `usertokens` has
`token`, `deviceinfo` and `scope` as `TEXT NOT NULL`, and MySQL gives a TEXT column no
default — so a seed that omits one is refused, and a stub that declares them nullable hides
that. A production writer that omits one fails the same way under strict mode, which is
exactly what a fixture built from the migration lets a test catch.

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
— a column a hand-rolled `applications` may not have.

The pattern: **drop that table and rebuild it from its migrations**. `Schema::table()` runs
the table's whole recipe, so nothing in its history is left out:

```php
$this->db->schema()->dropTableIfExists('#PREFIX#applications');
Schema::table('applications', $this->db);
```

`BaseTestCase::runMigrations([...], $this->db)` does the same from an explicit list of
migration classes, when a test needs a shape no recipe describes.

**Drop only what nothing else points at.** That is the whole rule, and it is cheap to get
wrong: `usertokens` carries a foreign key to `applications`, so a test that also drops and
rebuilds `usertokens` leaves the constraint dangling, and tests with nothing to do with
OAuth2 fail afterwards — account changes, permissions, token actions — each pointing at its
own tables, none at the test that caused it. Rebuilding the parent is survivable because it
is put back immediately; rebuilding the child is not.

Check before you drop:

```sql
SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_NAME = 'the_table_you_want_to_drop';
```

Everything else follows the rules below: schema and expensive fixtures once per class, rows
per test. An RSA key pair for an OAuth2 server costs a few hundred milliseconds and is
read-only once generated, so it belongs in `setUpBeforeClass()` like any other.

## MariaDB, when a change is dialect-sensitive

```bash
./dockertest --nocoverage --mariadb
```

Starts the profile-gated `mariadb` service (MariaDB 10.11) and runs only the tests in the
`mariadb` group against it. It is **not** part of a normal run: `phpunit.xml` excludes the group,
and `docker-compose up -d` leaves the service off, so the main suite never waits on a third
database.

Run it after touching DDL — a migration, the schema grammar, a `CHECK` — because MariaDB differs
from MySQL where MySQL shows nothing: it refuses `DROP CHECK`, keeps a CHECK written on a column
as part of the column, and takes a column's CHECK only after its COMMENT. A test joins the group
by extending the MySQL one with `#[Group('mariadb')]` and a data provider that points at host
`mariadb` — see `MariaDbOauthGrantsMigrationTest`.

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

**A test that changes the shape calls `$this->schemaChanged()`.** That covers adding a
column, dropping a table and installing a trigger: anything the next test would otherwise
inherit. The next `setUp()` then drops the owned tables and runs the DDL again. Without
it, the tests after the change pass or fail depending on the order they run in. If every
test in a class changes the shape, call it from `tearDown()`.

Foreign keys between owned tables are handled: the drops and deletes run with
`FOREIGN_KEY_CHECKS = 0` on MySQL, and `ownedTables()` is ordered so PostgreSQL is satisfied
without disabling anything. Override `setUp()`/`tearDown()` freely — just call `parent::`.

**Why it is worth converting a class.** Recreating tables per test is most of each test's
time in a class that never asserts anything about a schema; `QueryBuilderMySQLTest` runs on
this base for that reason, about thirty times faster than with per-test DDL and with the
same assertions.

### The one thing that will bite you

**Auto-increment counters do not restart between tests.** A fixture that writes a
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

Five rules, in order of preference:

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
   on *other* tests: every later query against it scans, and the slowdown shows up in files
   that have nothing to do with the change.
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
four, with the message naming the kind of file. That is the more valuable assertion anyway: it
is what stops `create:model` from overwriting an existing model and reporting success.

## An application's `applicationInfo` cannot be set by a property default

`Application::__construct()` assigns `$this->applicationInfo = self::loadApplicationInfo(APP_PATH .
'/app.php')`, so a test double written the obvious way —

```php
class TestApplication extends \Pramnos\Application\Application
{
    public $applicationInfo = [];      // ← overwritten before any test sees it
}
```

— comes back holding the fixture's namespace, not the empty array. A test written that way
supplies a namespace without meaning to, and the scaffolders' `: 'App'` fallback for an
unconfigured project is unreachable by construction.

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

Neither is about the code under test.

**`MediaObject::load()` reads through the query cache.** It is `query($sql, true, 600, 'media')` — cached for ten
minutes, keyed on the SQL text. A test that writes a row and reads it back gets the cache unless it
flushes:

```php
\Pramnos\Framework\Factory::getDatabase()->cacheflush('media');
```

Worse in a class that recreates its tables per run, because `mediaid` restarts at 1 and the cached
row belongs to a *different test's* media — same id, same SQL, plausible-looking answer.

**The constructor loads only a positive id.** `new MediaObject($id)` calls `load($id)` when the
id is above zero; `0`, `''` and `null` give an empty object with no error and no warning, and
`->thumbnails` is the empty default. A test that passes an id it has not resolved yet (an
unset variable, a route segment that did not parse) gets the empty object, and an assertion that a
freshly written row came back empty is the symptom while the row is fine.

## `No such file or directory` from a database that worked under a filter

A class that connects fine on its own and fails in the full suite with a socket error is not a
flaky database. It is the singleton: whichever test ran before left
`Database::getInstance()` holding another lane's connection settings, and `Factory::getDatabase()`
hands that same object back rather than building one from the settings just loaded.

Take the connection from `Connection::fresh()` (see
[above](#take-the-connection-from-connectionfresh-not-from-the-factory)), or drop the
reference first:

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

A reference written `\pramnos\Logs\Logger` — lowercase `p` — looks harmless, because PHP
resolves class names case-insensitively. It is not:

- **If the class is already loaded**, PHP finds it in its own table and the call works.
- **If it is not**, the autoloader is asked for the literal string `pramnos\Logs\Logger`, and
  Composer's PSR-4 map is keyed on `Pramnos\` — a case-sensitive miss. `Error: Class
  "pramnos\Logs\Logger" not found`.

So whether it works depends on what else the process happened to load first. Inside a `catch`
it does the most damage: the line only runs when something has already gone wrong, and it turns
a handled failure into an unhandled one. A cache adapter's `catch` arms exist so that losing the
cache degrades the application rather than stopping it, and a wrong-case logger reference in them
raises `Class not found` instead. Tests that run those arms in processes where the class is
already loaded do not see it. That is the general shape:

**A wrong-case reference in a rarely-taken branch cannot be found by running the application.** It
needs either the branch executed in a cold process, or a grep:

```bash
grep -rn '\\pramnos\\' --include='*.php' src/     # should print nothing
```

Worth running after any bulk edit that touches namespaces, and worth remembering when a class that
demonstrably exists is reported as not found.

## A green filtered run is not evidence that your new test ran

Tests appended to an existing file, then `./dockertest --filter UsersControllerTest`, and
`OK`. That reads as "they pass", and it can mean they did not run at all.

A file can end with two classes — the `TestCase` and a probe subclass that exposes protected
methods — and an insertion "before the last closing brace" lands in the second one. A test
method on a class that is not a `TestCase` is just a method: PHPUnit never sees it, nothing
fails, and the run is green because the *other* tests are fine. Only the counts give it away:
the test and assertion totals do not move.

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

The effect can be hundreds of statements across a codebase, and it does not always flatter: when
most of the accidentally-excluded code is covered, correcting the syntax makes the total go *up*.
The exclusions hide the size of the codebase as much as untested code.

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
happens to have: two identical runs report different totals when one finds a directory the other
does not.

Nothing fails, and that is the cost. The assertion total is the cheapest signal there is for "did
this change add assertions or quietly lose some" — the same signal that catches tests landing in
the wrong class, above — and a variable total is a signal you cannot read.

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

The sharpest version of "a test that replaces a method is not a test of it", because it can have a
good reason and a name that says it is doing the opposite.

`ApiAdmin::search()` caps the omnibox limit at twenty — an endpoint that took the number from the
request would be a denial-of-service endpoint with a friendly name. A test of that cap whose probe
overrides `search()` looks like this:

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

The reasoning is sound — running six real searches would assert the registry's behaviour rather
than the cap. The consequence is not: **the test asserts its own arithmetic.** Change the source to
`min(500, …)` and it still passes, because the source is never called.

Record from *below* the thing under test, not in place of it. That is why the controller has one
protected line —

```php
protected function searchRegistry(string $term, int $perSource): array
{
    return \Pramnos\Search\Registry::query($term, $perSource);
}
```

— and the probe overrides that instead. The real `search()` runs, the real cap computes, and the
number asserted is the one the endpoint produced. It also lets the test assert that the term
comes from `q` and that the default is five.

**The test to run on your own probes:** for each method it overrides, ask whether the source could
change and the test still pass. If the override contains a copy of any expression from the source,
the answer is yes.

## When the gap is in the environment, fix the environment

Some branches have no covered line for a reason that is not about the tests at all.

**APCu.** The migration fingerprint cache prefers APCu and falls back to a marker file. APCu is the
path that runs in production — it is the reason the cache exists — and it needs two things in the
test image: the extension, and `apc.enable_cli=1`, because the setting defaults to `0` for the CLI.
The `Dockerfile` sets both. Each CLI process gets its own empty cache, so nothing carries between
tests.

**A fetchable address.** `OutboundUrl` refuses every private, loopback, link-local and reserved
address, which is the whole point of it — so nothing a container ordinarily reaches is fetchable,
and its socket path cannot open. The temptation is to widen the guard for tests: change `self::` to
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
for the wrong reason, or fail for one.

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

## Testing the JavaScript of an application

An application tests the scripts it writes for its pages with the framework's runner and helpers,
not with copies of them:

```bash
php bin/<app> test:js                    # node --test over every *.test.js in tests/js
php bin/<app> test:js tests/js/notes.test.js
php bin/<app> lint:js                    # ESLint, the framework's rules, over assets/js and tests/js
php bin/<app> lint:js --fix
```

`test:js` reads its directory from `js_tests_dir` in `app/app.php` (default `tests/js`, or
`--dir`) and finds `*.test.js` in it and below. Both commands run Node **where the console runs**.
In a project in Docker that is the container, as `./dockerbash` or the generated CLI wrapper
gives it, so the tests run against the container's Node, as PHPUnit runs against its PHP. Where
there is no Node, they say so.

Nothing to install. `node --test` and the helpers use only Node. `lint:js` uses the project's
`node_modules/.bin/eslint` when there is one, and otherwise `npx` fetches ESLint 9.

### The helpers: `require('pramnos-testing')`

`test:js` puts the framework's helpers on `NODE_PATH`, so every test has them by name:

| Helper | What it gives a test |
|---|---|
| `createDom({ url, meta, readyState })` | `{ window, document }`: elements with attributes, classes, `dataset`, children and listeners. Events bubble to the document and the window. There are `localStorage`/`sessionStorage`, and a `location` whose assignments land in `window.navigatedTo`. `meta: { csrf: 'x' }` puts `<meta name="csrf">` in the head. |
| `loadScript(file, window)` | Runs a classic browser script, a `<script src>`, with the window as its globals. Returns the context: top-level `var`s and what the script put on `window`. `{ source: true }` takes the code itself. |
| `stubFetch(routes)` | A `fetch` that answers from a table: `'GET /api/notes'` or `'/api/notes'` maps to a body, to `{ status, body }`, or to `(request) => …`. Anything else answers 404. `fetch.calls` records every request. |
| `installApiHeaders(window)` | `window.pfApiHeaders`, as `pf-utils.js` defines it: the CSRF token from the page's `<meta name="csrf">`. |
| `installJquery(window)` | A jQuery-shaped `$` for legacy code. It covers `on`/`off`/`trigger` with delegation, `val`, `text`, `html`, `attr`, `data`, the class methods, `find`, `closest`, `each`, and `$.ajax`/`$.get`/`$.post` through the stubbed `fetch`. |

Selectors: `tag`, `#id`, `.class`, `[attr]`, `[attr="v"]` (and `^=` `$=` `*=`), compounds,
descendants, `>` and comma lists. A selector outside that throws rather than matching nothing.
Assigned `innerHTML` is kept as a string, not parsed: build the elements a test needs with
`createElement`.

```js
// tests/js/notes.test.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createDom, loadScript, stubFetch, installApiHeaders } = require('pramnos-testing');

test('saving a note posts it with the page token', async () => {
    // Arrange — the page the script expects
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    window.fetch = stubFetch({ 'POST /api/notes': { status: 201, body: { id: 7 } } });
    installApiHeaders(window);
    const button = document.body.appendChild(document.createElement('button'));
    button.setAttribute('data-save-note', '');

    // Act
    loadScript('www/assets/js/notes.js', window);
    button.click();
    await new Promise((done) => setImmediate(done));

    // Assert
    assert.equal(window.fetch.calls[0].method, 'POST');
    assert.equal(window.fetch.calls[0].headers['X-CSRF-Token'], 't0ken');
});
```

### A script that extends the framework's `pf-*.js`

Load the framework's script first, from where the page loads it, then the application's. They
share the window as they share a page:

```js
loadScript('www/assets/js/pf-utils.js', window);        // as published by project:resync --js
loadScript('www/assets/js/admin-extras.js', window);    // the application's, which uses window.pfApiHeaders
```

Test the published copy, not the one under `vendor/`. The page runs the published copy, so a stale
one is the bug the test should see.

### Linting with the framework's rules

The rules are defects only, never a style: a redeclared variable, an undefined name, unreachable
code. Without an `eslint.config.mjs`, `lint:js` applies the framework's whole configuration.
An application that needs its own globals, a legacy jQuery page for instance, writes one that
extends it:

```js
// eslint.config.mjs
import { browser, tests } from './vendor/mrpc/pramnosframework/resources/js/pramnos-testing/eslint.base.mjs';

export default [
    { ignores: ['www/assets/vendor/**'] },
    browser(['www/assets/js/**/*.js'], { jQuery: 'readonly', $: 'readonly' }),
    tests(['tests/js/**/*.js']),
];
```

## The JavaScript the framework ships

One browser asset — `src/Pramnos/Debug/assets/debugbar.js`, a few thousand lines, served on every
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
semicolons, no indentation — because `debugbar.js` was written long before the config: reformatting it
would bury the next real change in noise, and a `--fix` sweep across the whole file is precisely the
diff nobody can review.

The rule the config exists for is `no-redeclare`. A `var` declared twice in that asset is
legal JavaScript and passes every test here, yet a consuming project's stricter tooling can
refuse the file — and with it every panel test that loads it.

!!! warning "Do not write a unit test for this class of mistake"
    A test that scans for duplicate `var` declarations flags `var rows` in six unrelated
    functions, because it matches an **identifier** rather than a redeclaration.
    `no-redeclare` understands scope; a grep never will.

    The same reasoning applies to `no-undef`, `no-dupe-keys` and the rest: they are answers a
    parser can give and a test cannot.

### `CLIENT_TABS` is deliberately absent

The three tabs a `CLIENT_TABS` lookup table would name *are* special-cased in `debugbar.js`, by
explicit `tab.key === …` checks in three places. Such a constant would duplicate that knowledge
rather than replace it, and wiring it up would be a refactor of behavioural code, so the note is
kept in the file instead.

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
passing on a clean tree and matching nothing at all look identical.

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
