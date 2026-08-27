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
    /** The Docker-substituted host, so the substitution can be asserted without a socket. */
    public function publicResolvedHost() { return $this->resolvedHost(); }
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

    /**
     * The MySQL DSN carries the configured host, database and port.
     *
     * Asserted on the string rather than inferred from a connection failure. The old
     * shape pointed at a hostname that could not resolve and read the host out of the
     * error — which meant this was one of the three slowest tests in the suite, at
     * **8.00 seconds**, all of it spent in `getaddrinfo()` giving up.
     */
    public function test_it_builds_correct_dsn()
    {
        // Arrange & Act
        $dsn = $this->buildDsn('mysql', 'testhost', 'testdb', 3306);

        // Assert
        $this->assertStringContainsString('mysql:host=testhost', $dsn);
        $this->assertStringContainsString('dbname=testdb', $dsn);
        $this->assertStringContainsString('port=3306', $dsn);
    }

    /**
     * The PostgreSQL DSN uses the pgsql driver and carries a connect timeout.
     *
     * PostgreSQL takes the timeout in the DSN rather than as a driver attribute, so a
     * missing one here is invisible until something hangs.
     */
    public function test_it_builds_postgres_dsn()
    {
        // Arrange & Act
        $dsn = $this->buildDsn('postgresql', 'pghost', 'pgdb', null);

        // Assert
        $this->assertStringContainsString('pgsql:host=pghost', $dsn);
        $this->assertStringContainsString('dbname=pgdb', $dsn);
        $this->assertStringNotContainsString('port=', $dsn, 'no port was configured');
        $this->assertStringContainsString('connect_timeout=', $dsn);
    }

    /**
     * Inside a container, `localhost` is not the database.
     *
     * The substitution is the whole behaviour: `localhost` in a container means the
     * container itself, so the config is rewritten to the service name. It used to be
     * observable only by failing to connect — 8.00 seconds of resolver timeout to
     * discover a string substitution.
     */
    public function test_docker_hostname_switching()
    {
        // Arrange — a case whose answer differs inside and outside Docker
        $dockTestCase = new DummyBaseTestCase('test');
        $dockTestCase->mockedIsDocker = true;

        $reflection = new \ReflectionClass(BaseTestCase::class);
        $prop = $reflection->getProperty('dbConfig');
        $prop->setValue(null, [
            'hostname' => 'localhost',
            'database' => 'testdb',
            'user' => 'root',
            'password' => '',
            'type' => 'mysql'
        ]);

        // Act
        $host = $dockTestCase->publicResolvedHost();

        // Assert
        $this->assertSame('mysql', $host);
        $this->assertNotSame('localhost', $host);
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
     * The human-check helper returns fields a real verification accepts.
     *
     * The helper exists so that an application whose forms have
     * `auth.security.human_check` switched on can test them with the check *on* — the
     * alternative is switching it off for the test run, which leaves the shipped
     * configuration untested, and a check that refuses every visitor then reaches
     * production with a green suite.
     *
     * So what it has to get right is exactly this: the pair it returns must satisfy
     * `HumanCheck::verify()`, including the detail that the hashed payload is the challenge
     * without its signature.
     */
    public function test_solved_human_check_fields_verify()
    {
        // Act
        $fields = $this->solvedHumanCheckFields();

        // Assert
        $this->assertTrue(
            (new \Pramnos\Security\HumanCheck(1))->verify(
                $fields['human_challenge'],
                $fields['human_solution']
            ),
            'a solution this helper produced must be one the class accepts'
        );
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
        $prop->setValue(null, [
            'type' => 'invalid',
            // An IP literal: a made-up hostname costs 8.00s in getaddrinfo() here, and
            // what this test asserts is the exception, not the resolver.
            'hostname' => '127.0.0.1',
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
        $propConfig->setValue(null, [
            'type' => 'postgresql',
            'hostname' => 'timescaledb',
            'database' => 'pramnos_test',
            'user' => 'postgres',
            'password' => 'secret'
        ]);

        // Nullify the current instance's pdo property
        $propPdo = $reflection->getProperty('pdo');
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
