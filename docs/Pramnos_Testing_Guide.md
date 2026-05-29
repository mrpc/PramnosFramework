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

## Reference

For complete documentation on testing, see:

- [v1.2 New Features — HTTP Testing Infrastructure](1.2-new-features.md#45-phase-20-http-testing-infrastructure)
- [v1.2 New Features — Factories](1.2-new-features.md#51-factory--pramnos-database-factory)
- [v1.2 New Features — Seeders](1.2-new-features.md#52-seeder--pramnos-database-seeder-updated)

**Topics covered in detailed reference:**

- HTTP test client API and all methods
- Response assertions and JSON validation
- Factory definition and customization
- Seeder generation and execution
- Database state management
- Mocking and stubbing
- Concurrent test execution
- Performance profiling in tests
- Coverage reporting
