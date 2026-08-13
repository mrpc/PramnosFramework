---
use_cases:
  - Writing a test for a controller, model or HTTP endpoint
  - Using factories or seeders to build test data
  - Choosing a base test case, or testing without booting the application
---

# Pramnos Testing Guide

Pramnos provides comprehensive testing infrastructure including HTTP testing, factory generation, and seeding.

## HTTP Testing

### Making HTTP Requests

```php
use Pramnos\Testing\HttpTest;

class UserApiTest extends HttpTest
{
    public function testGetUsers()
    {
        $response = $this->get('/api/v1/users');
        
        $response->assertStatus(200);
        $response->assertJsonPath('data.0.username', 'john_doe');
    }
    
    public function testCreateUser()
    {
        $response = $this->post('/api/v1/users', [
            'username' => 'jane_doe',
            'email'    => 'jane@example.com',
            'password' => 'secret123',
        ]);
        
        $response->assertStatus(201);
        $response->assertJsonPath('data.userid', $expect = null);
    }
    
    public function testUpdateUser()
    {
        $response = $this->patch('/api/v1/users/1', [
            'email' => 'newemail@example.com',
        ]);
        
        $response->assertStatus(200);
    }
    
    public function testDeleteUser()
    {
        $response = $this->delete('/api/v1/users/1');
        
        $response->assertStatus(204);
    }
}
```

### Available HTTP Methods

```php
$this->get('/path');
$this->post('/path', $data);
$this->patch('/path', $data);
$this->put('/path', $data);
$this->delete('/path');
$this->head('/path');
```

### Request Headers & Auth

```php
// Add headers
$this->withHeader('Authorization', 'Bearer token_here')
    ->post('/api/users');

// With Bearer token
$this->withToken('token_here')
    ->post('/api/users');

// With basic auth
$this->withBasicAuth('user', 'password')
    ->get('/protected');

// JSON content
$this->json('POST', '/api/users', $data);
```

### Response Assertions

```php
$response = $this->post('/api/users', [...]);

// Status codes
$response->assertStatus(201);
$response->assertCreated();
$response->assertOk();
$response->assertNotFound();
$response->assertUnauthorized();
$response->assertForbidden();

// Headers
$response->assertHeader('Content-Type', 'application/json');

// JSON assertions
$response->assertJson(['success' => true]);
$response->assertJsonPath('data.id', 42);
$response->assertJsonCount(10, 'data');
$response->assertJsonStructure(['data' => ['id', 'name', 'email']]);

// Content
$response->assertSee('User created');
$response->assertDontSee('Error');
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
use Pramnos\Testing\TestCase;

class UserControllerTest extends TestCase
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
class UserApiTest extends \Pramnos\Testing\HttpTest
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
| Creating and dropping schema per test | ≈300 ms per test | Schema once per class; wrap each test in a transaction and roll it back |

DDL is not transactional in MySQL, which is why the split is *schema per class, data per
test* rather than everything in one transaction.

The full measurement, and what is planned from it, is in
[Test suite performance](Pramnos_Test_Suite_Performance.md). To check your own test's
place in the distribution:

```bash
./dockertest --no-coverage --log-junit /var/www/html/var/junit.xml --filter YourTest
```

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
</extensions>
```

| Extension | What leaks without it |
| --- | --- |
| `RequestIdentityIsolation` | An identity sealed by one test stays sealed. A controller test running after a middleware test finds itself signed in as somebody it never authenticated — **135 failures**, in tests that had nothing to do with authentication. |
| `DocumentIsolation` | `Document` is a mutable singleton per type. A test that sets `->type = 'json'` is writing to the shared HTML document, and the next test that renders gets it — **three failures**, each of which appeared only in a full run. |

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

## Reference

**Related Guides:**
- [Pramnos_Test_Suite_Performance.md](Pramnos_Test_Suite_Performance.md) — where the suite's time goes, measured
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
