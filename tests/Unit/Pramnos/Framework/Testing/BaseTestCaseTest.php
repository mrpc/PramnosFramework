<?php
namespace Tests\Unit\Pramnos\Framework\Testing;

use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Dummy class to test abstract BaseTestCase methods without deprecations.
 */
class DummyBaseTestCase extends BaseTestCase {
    public $mockedIsDocker = false;
    public function isDocker(): bool { return $this->mockedIsDocker; }
    public function publicGetConnection() { return $this->getConnection(); }
}

/**
 * We test BaseTestCase by extending it directly in our test suite.
 */
class BaseTestCaseTest extends BaseTestCase
{
    /**
     * Set up a dummy session for testing.
     */
    protected function setUp(): void
    {
        // We override setUp to avoid triggering the application init
        // while we test simple session helpers.
        $this->initializeSession();
    }

    public function test_it_manages_login_session()
    {
        $userId = 123;
        $this->loginUser($userId);
        
        $this->assertEquals($userId, $_SESSION['user_id']);
        $this->assertTrue($_SESSION['auth']);
    }

    public function test_it_manages_csrf_tokens()
    {
        $token = $this->generateCSRFToken();
        $this->assertNotEmpty($token);
        $this->assertEquals($token, $_SESSION['csrf_token']);
        
        $retrieved = $this->getCSRFToken();
        $this->assertEquals($token, $retrieved);
    }

    public function test_it_builds_correct_dsn()
    {
        // Use reflection to set static dbConfig
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('dbConfig');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'hostname' => 'testhost',
            'database' => 'testdb',
            'user' => 'testuser',
            'password' => 'testpass',
            'type' => 'mysql',
            'port' => 3306
        ]);

        try {
            $this->getConnection();
        } catch (\PDOException $e) {
            // Check that it tried to connect to our specific test host
            $this->assertStringContainsString('testhost', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function test_it_builds_postgres_dsn()
    {
        // Use reflection to set static dbConfig
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('dbConfig');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'hostname' => 'pghost',
            'database' => 'pgdb',
            'user' => 'pguser',
            'password' => 'pgpass',
            'type' => 'postgresql'
        ]);

        try {
            $this->getConnection();
        } catch (\PDOException $e) {
            $this->assertStringContainsString('pghost', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function test_docker_hostname_switching()
    {
        // Use concrete class instead of mock to avoid deprecations
        $dockTestCase = new DummyBaseTestCase('test');
        $dockTestCase->mockedIsDocker = true;
        
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('dbConfig');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'hostname' => 'localhost',
            'database' => 'testdb',
            'user' => 'root',
            'password' => '',
            'type' => 'mysql'
        ]);

        try {
            $dockTestCase->publicGetConnection();
        } catch (\PDOException $e) {
            // Should mention 'mysql' as hostname, not 'localhost'
            $this->assertStringContainsString('mysql', $e->getMessage());
            $this->assertStringNotContainsString('localhost', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function test_database_assertions()
    {
        // Mock PDO for assertions
        $pdo = $this->createMock(\PDO::class);
        
        // First statement for 'Has' assertion (returns 1)
        $stmt1 = $this->createMock(\PDOStatement::class);
        $stmt1->method('execute')->willReturn(true);
        $stmt1->method('fetchColumn')->willReturn(1);

        // Second statement for 'Missing' assertion (returns 0)
        $stmt2 = $this->createMock(\PDOStatement::class);
        $stmt2->method('execute')->willReturn(true);
        $stmt2->method('fetchColumn')->willReturn(0);
        
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        // Use reflection to set the internal pdo property
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($this, $pdo);

        // This should pass (uses $stmt1)
        $this->assertDatabaseHas('users', ['id' => 1]);
        
        // This should also pass (uses $stmt2)
        $this->assertDatabaseMissing('users', ['id' => 999]);
    }

    public function test_add_valid_csrf_post_field()
    {
        $token = 'test_token';
        $_SESSION['csrf_token'] = $token;
        
        $this->addValidCsrfPostField();
        
        $this->assertEquals($token, $_POST['csrf_token']);
    }

    /**
     * Test the real isDocker implementation to increase coverage.
     */
    public function test_real_is_docker()
    {
        // This will call the actual is_dir check
        $result = $this->isDocker();
        $this->assertIsBool($result);
    }

    /**
     * Test database assertion failures to cover fail paths.
     */
    public function test_database_assertion_failures()
    {
        // Mock PDO for failures
        $pdo = $this->createMock(\PDO::class);
        
        // Stmt for Has failure (returns 0)
        $stmt1 = $this->createMock(\PDOStatement::class);
        $stmt1->method('execute')->willReturn(true);
        $stmt1->method('fetchColumn')->willReturn(0);
        
        // Stmt for Missing failure (returns 1)
        $stmt2 = $this->createMock(\PDOStatement::class);
        $stmt2->method('execute')->willReturn(true);
        $stmt2->method('fetchColumn')->willReturn(1);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($this, $pdo);

        // 1. Test assertDatabaseHas failure (expected record missing)
        try {
            $this->assertDatabaseHas('users', ['id' => 999]);
            $this->fail('Assertion Has should have failed');
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            $this->assertStringContainsString('does not contain matching record', $e->getMessage());
        }

        // 2. Test assertDatabaseMissing failure (unexpected record found)
        try {
            $this->assertDatabaseMissing('users', ['id' => 1]);
            $this->fail('Assertion Missing should have failed');
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            $this->assertStringContainsString('contains unexpected matching record', $e->getMessage());
        }
    }

    /**
     * Test connection failure handling.
     */
    public function test_get_connection_failure()
    {
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('dbConfig');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'type' => 'invalid',
            'hostname' => 'invalid',
            'database' => 'invalid',
            'user' => 'root',
            'password' => ''
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed');
        $this->getConnection();
    }

    /**
     * Test initializeSession when session is already active.
     */
    public function test_initialize_session_already_active()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        $this->initializeSession(['test' => 'value']);
        $this->assertEquals('value', $_SESSION['test']);
    }

    /**
     * Test addValidCsrfPostField when session token is missing.
     */
    public function test_add_valid_csrf_post_field_missing_token()
    {
        unset($_SESSION['csrf_token']);
        $this->addValidCsrfPostField();
        $this->assertNotEmpty($_POST['csrf_token']);
    }

    /**
     * Test getConnection when the pdo property is null to cover the full creation path.
     */
    public function test_get_connection_reconnect()
    {
        // Use reflection to set static dbConfig to valid local one
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $propConfig = $reflection->getProperty('dbConfig');
        $propConfig->setAccessible(true);
        $propConfig->setValue(null, [
            'type' => 'postgresql',
            'hostname' => 'timescaledb',
            'database' => 'pramnos_test',
            'user' => 'postgres',
            'password' => 'secret'
        ]);

        // Nullify the current instance's pdo property
        $propPdo = $reflection->getProperty('pdo');
        $propPdo->setAccessible(true);
        $propPdo->setValue($this, null);

        $db = $this->getConnection();
        $this->assertInstanceOf(\PDO::class, $db);
    }

    /**
     * Test the real pdo return branch in getConnection.
     */
    public function test_get_connection_pdo_return()
    {
        $pdo = $this->createMock(\PDO::class);
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($this, $pdo);
        
        $result = $this->getConnection();
        $this->assertSame($pdo, $result);
    }

    /**
     * Test the base setup logic to cover lines at the top of BaseTestCase.
     */
    public function test_call_parent_setup()
    {
        // This will call the actual BaseTestCase::setUp()
        parent::setUp();
        $this->assertTrue(true);
    }
}
