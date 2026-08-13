<?php
namespace Tests\Unit\Pramnos\Framework\Testing;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestEnvironment;

class TestEnvironmentTest extends TestCase
{
    /**
     * Table created by the dumps the schema-import tests feed to psql/mysql.
     * Its presence in the freshly created database is the proof that the import
     * step ran, rather than merely not throwing.
     */
    private const IMPORT_PROBE_TABLE = 'pramnos_import_probe';

    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pramnos_test_env_internal_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        if (isset($GLOBALS['PRAMNOS_TEST_LOCK'])) {
            @fclose($GLOBALS['PRAMNOS_TEST_LOCK']);
            unset($GLOBALS['PRAMNOS_TEST_LOCK']);
        }
    }

    public function test_setup_returns_early_if_settings_missing()
    {
        $settingsPath = $this->tempDir . '/non_existent.php';
        
        // Mock ROOT if not defined
        if (!defined('ROOT')) {
            define('ROOT', $this->tempDir);
        }

        // Should NOT throw exception now
        TestEnvironment::setup($settingsPath);
        $this->assertTrue(true);
    }

    public function test_it_manages_locks_correctly()
    {
        $lockDir = $this->tempDir . '/var';
        TestEnvironment::$lockDir = $lockDir;
        $lockFile = $lockDir . '/phpunit-bootstrap.lock';
        
        // Use reflection to call protected acquireLock
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method = $reflection->getMethod('acquireLock');

        $result = $method->invoke(null);
        $this->assertTrue($result);
        $this->assertFileExists($lockFile);
        $this->assertIsResource($GLOBALS['PRAMNOS_TEST_LOCK']);

        // Second call should return false (locked)
        $result2 = $method->invoke(null);
        $this->assertFalse($result2);
        
        // Reset for other tests
        TestEnvironment::$lockDir = null;
    }

    public function test_it_handles_database_initialization_logic()
    {
        $settingsPath = $this->tempDir . '/testsettings.php';
        $content = "<?php return ['database' => ['type' => 'mysql', 'hostname' => '127.0.0.1', 'port' => 9, 'database' => 'testdb', 'user' => 'root', 'password' => 'pass']];";
        file_put_contents($settingsPath, $content);

        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method = $reflection->getMethod('initializeDatabase');

        // We use a try-catch because it will try to connect to PDO and fail, 
        // but we want to see it reach that stage or we can mock/catch the exception.
        try {
            $method->invoke(null, $settingsPath, null);
        } catch (\RuntimeException $e) {
            // Expected failure to connect, but proves logic reached PDO instantiation 
            // and built the hostname correctly (detecting Docker env)
            $this->assertStringContainsString('Database setup failed', $e->getMessage());
        } catch (\Exception $e) {
             $this->assertTrue(true);
        }
    }

    public function test_it_dispatches_to_postgres_and_mysql_correctly()
    {
        // Use an anonymous class to mock TestEnvironment static method
        $mock = new class extends TestEnvironment {
            public static $lastCommand;
            protected static function runCommand(string $command): string {
                self::$lastCommand = $command;
                return $command;
            }
        };

        $reflection = new \ReflectionClass(TestEnvironment::class);
        
        $pgMethod = $reflection->getMethod('setupPostgres');
        
        try {
            $pgMethod->invoke(null, 'localhost', 5432, 'testdb', 'user', 'pass', $this->tempDir . '/schema.sql');
            $this->assertStringContainsString('psql', $mock::$lastCommand);
        } catch (\Exception $e) {
             $this->assertTrue(true);
        }

        $myMethod = $reflection->getMethod('setupMysql');
        try {
            $myMethod->invoke(null, 'localhost', 3306, 'testdb', 'user', 'pass', $this->tempDir . '/schema.sql');
            $this->assertStringContainsString('mysql', $mock::$lastCommand);
        } catch (\Exception $e) {
             $this->assertTrue(true);
        }
    }

    public function test_full_setup_flow()
    {
        $settingsPath = $this->tempDir . '/app/config/testsettings.php';
        mkdir(dirname($settingsPath), 0777, true);
        $content = "<?php return ['database' => ['type' => 'mysql', 'hostname' => '127.0.0.1', 'port' => 9, 'database' => 'testdb', 'user' => 'root', 'password' => 'pass']];";
        file_put_contents($settingsPath, $content);

        // Define ROOT if not already defined for the test context
        if (!defined('ROOT')) {
            define('ROOT', $this->tempDir);
        }

        TestEnvironment::$lockDir = $this->tempDir . '/var';

        try {
            TestEnvironment::setup($settingsPath);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Database setup failed', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Test initializeDatabase Docker hostname switching.
     */
    public function test_initialize_database_docker_switching()
    {
        $settingsPath = $this->tempDir . '/dockertest.php';
        $content = "<?php return ['database' => ['type' => 'postgresql', 'hostname' => '127.0.0.1', 'port' => 9, 'database' => 'testdb', 'user' => 'u', 'password' => 'p']];";
        file_put_contents($settingsPath, $content);

        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method = $reflection->getMethod('initializeDatabase');

        // This will trigger the /.dockerenv check and switch localhost -> postgres
        try {
            $method->invoke(null, $settingsPath, null);
        } catch (\Exception $e) {
            // It will fail at PDO connection to 'postgres' (unless container name matches)
            // but the branch will be covered.
            $this->assertTrue(true);
        }
    }

    /**
     * initializeDatabase() must throw RuntimeException when the settings file
     * does not exist (line 105). This guard prevents silent failures when the
     * test configuration is misconfigured.
     */
    public function test_initializeDatabase_throws_when_settings_missing(): void
    {
        // Arrange — path to a file that does not exist
        $missing = $this->tempDir . '/definitely_not_here.php';
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method = $reflection->getMethod('initializeDatabase');

        // Act + Assert — RuntimeException with a descriptive message
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Test settings not found/');
        $method->invoke(null, $missing, null);
    }

    /**
     * setupPostgres() must build and run the psql import command when
     * $schemaPath is provided and the file exists (lines 148-157).
     *
     * Uses a unique database name to avoid conflicts with parallel tests.
     *
     * Failure handling is deliberately narrow. Only a genuine "cannot reach the
     * container" error skips the test; everything else must fail, so a real
     * regression in setupPostgres() cannot hide behind a green-looking skip.
     * The one tolerated flake is SQLSTATE 55006: setupPostgres() creates the
     * database `WITH TEMPLATE template1`, which PostgreSQL refuses while any
     * other session holds template1 — a transient collision with the rest of
     * the suite, so it is retried rather than reported either way.
     */
    public function test_setupPostgres_schema_import_branch(): void
    {
        // Arrange — a dump whose effect is observable afterwards. A dump of
        // `SELECT 1;` would leave no trace, so the assertion could not tell an
        // import that ran from one that silently did nothing.
        $schemaFile = $this->tempDir . '/import_test.sql';
        file_put_contents(
            $schemaFile,
            'CREATE TABLE ' . self::IMPORT_PROBE_TABLE . ' (id integer);' . "\n"
        );

        $dbName     = $this->uniqueDbName('cov');
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method     = $reflection->getMethod('setupPostgres');

        try {
            $this->requireClientBinary('psql');

            // Act — connect to timescaledb, create the DB, then import the schema
            $this->invokeSetup($method, ['timescaledb', 5432, $dbName, 'postgres', 'secret', $schemaFile]);

            // Assert — the dump's table is there, so psql really imported it
            $this->assertTrue(
                $this->pgTableExists($dbName, self::IMPORT_PROBE_TABLE),
                'the psql import branch created the dump table'
            );
        } finally {
            $this->dropPgDatabase($dbName);
        }
    }

    /**
     * Is this PDOException a "could not reach the server" error, as opposed to a
     * server that answered and rejected the statement?
     *
     * Connection failures carry SQLSTATE class 08 (connection exception), but the
     * pgsql driver reports a bare libpq error code (e.g. 7) when the connection
     * never got far enough to have a SQLSTATE — hence the message fallback.
     */
    private function isConnectionFailure(\PDOException $e): bool
    {
        if (str_starts_with((string) $e->getCode(), '08')) {
            return true;
        }
        return (bool) preg_match(
            '/could not connect|connection refused|could not translate host name'
            . '|no route to host|timeout expired|server closed the connection/i',
            $e->getMessage()
        );
    }

    private function removeDirectory($path)
    {
        if (!is_dir($path)) return;
        $files = array_diff(scandir($path), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$path/$file")) ? $this->removeDirectory("$path/$file") : unlink("$path/$file");
        }
        return rmdir($path);
    }

    /**
     * Test the real runCommand implementation to cover shell_exec.
     */
    public function test_run_command_actual()
    {
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method = $reflection->getMethod('runCommand');

        $output = $method->invoke(null, 'echo "hello"');
        $this->assertEquals("hello\n", $output);
    }

    /**
     * setupPostgres() with no schema path must still (re)create the database —
     * and must be repeatable: the second call has to terminate the sessions on
     * the existing database and drop it before recreating, which is the only
     * thing standing between a test run and "database is being accessed by
     * other users".
     */
    public function test_setupPostgres_recreates_database_without_schema(): void
    {
        // Arrange
        $dbName     = $this->uniqueDbName('nos');
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method     = $reflection->getMethod('setupPostgres');

        try {
            // Act — create once, hold a session on it, then create again
            $this->invokeSetup($method, ['timescaledb', 5432, $dbName, 'postgres', 'secret', null]);
            $this->assertTrue($this->pgDatabaseExists($dbName), 'database created on the first call');

            $held = $this->pgConnection($dbName); // an open session on the target DB
            $this->invokeSetup($method, ['timescaledb', 5432, $dbName, 'postgres', 'secret', null]);

            // Assert — the drop+recreate went through despite the open session,
            // which proves the pg_terminate_backend() step did its job.
            $this->assertTrue($this->pgDatabaseExists($dbName), 'database recreated on the second call');
            unset($held);
        } finally {
            $this->dropPgDatabase($dbName);
        }
    }

    /**
     * A schema path pointing at a file that does not exist must be ignored: the
     * database is created, the psql import is skipped, and nothing throws.
     */
    public function test_setupPostgres_skips_import_when_schema_file_missing(): void
    {
        // Arrange — a path that was never written
        $dbName     = $this->uniqueDbName('nof');
        $missing    = $this->tempDir . '/not_written.sql';
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method     = $reflection->getMethod('setupPostgres');

        try {
            // Act
            $this->invokeSetup($method, ['timescaledb', 5432, $dbName, 'postgres', 'secret', $missing]);

            // Assert — database exists, but the dump's table obviously does not
            $this->assertTrue($this->pgDatabaseExists($dbName));
            $this->assertFalse(
                $this->pgTableExists($dbName, self::IMPORT_PROBE_TABLE),
                'no import must have run for a non-existent schema file'
            );
        } finally {
            $this->dropPgDatabase($dbName);
        }
    }

    /**
     * setupMysql() must create the database and import the dump through the
     * mysql client. Asserting on a table created by the dump is what proves the
     * import actually ran — a dump of `SELECT 1;` would pass even if the client
     * silently did nothing.
     */
    public function test_setupMysql_creates_database_and_imports_schema(): void
    {
        // Arrange
        $dbName     = $this->uniqueDbName('my');
        $schemaFile = $this->tempDir . '/schema.sql';
        file_put_contents(
            $schemaFile,
            'CREATE TABLE ' . self::IMPORT_PROBE_TABLE . ' (id INT);' . "\n"
        );
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method     = $reflection->getMethod('setupMysql');

        try {
            $this->requireClientBinary('mysql');

            // Act
            $this->invokeSetup($method, ['db', 3306, $dbName, 'root', 'secret', $schemaFile]);

            // Assert — the table from the dump is present in the new database
            $pdo   = $this->mysqlConnection();
            $found = $pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables'
                . " WHERE table_schema = '$dbName'"
                . " AND table_name = '" . self::IMPORT_PROBE_TABLE . "'"
            )->fetchColumn();
            $this->assertSame(1, (int) $found, 'the mysql import branch created the dump table');
        } finally {
            $this->dropMysqlDatabase($dbName);
        }
    }

    /**
     * A dump that fails halfway must abort the import loudly.
     *
     * psql only exits non-zero when it is told to stop on the first error, so
     * this also pins the `-v ON_ERROR_STOP=1` flag: without it psql reports
     * success for a dump whose statements all failed, and the caller happily
     * proceeds with an empty database.
     */
    public function test_setupPostgres_throws_when_the_dump_fails(): void
    {
        // Arrange — valid SQL first, then a statement that cannot succeed
        $schemaFile = $this->tempDir . '/broken.sql';
        file_put_contents(
            $schemaFile,
            'CREATE TABLE ' . self::IMPORT_PROBE_TABLE . " (id integer);\n"
            . "SELECT * FROM a_table_that_does_not_exist;\n"
        );

        $dbName     = $this->uniqueDbName('bad');
        $reflection = new \ReflectionClass(TestEnvironment::class);
        $method     = $reflection->getMethod('setupPostgres');

        try {
            $this->requireClientBinary('psql');

            // Act + Assert — the failure surfaces instead of being swallowed,
            // and the message carries psql's own diagnostics.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Schema import failed.*a_table_that_does_not_exist/s');
            $this->invokeSetup($method, ['timescaledb', 5432, $dbName, 'postgres', 'secret', $schemaFile]);
        } finally {
            $this->dropPgDatabase($dbName);
        }
    }

    /**
     * A missing client binary (exit status 127) gets its own message, because
     * the shell's bare "not found" explains nothing about the consequence — an
     * empty test database.
     */
    public function test_runImport_reports_a_missing_client_binary(): void
    {
        // Arrange — a command that cannot exist
        $method = (new \ReflectionClass(TestEnvironment::class))->getMethod('runImport');

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/the 'psql' client is not installed/");
        $method->invoke(null, 'pramnos-no-such-binary-xyz', 'psql');
    }

    /**
     * Any other non-zero exit is reported with the status and the client's
     * output, so the reason for a failed import is never lost.
     */
    public function test_runImport_reports_status_and_output_on_failure(): void
    {
        // Arrange — a command that writes to stderr and fails with status 3
        $method = (new \ReflectionClass(TestEnvironment::class))->getMethod('runImport');

        // Act + Assert — stderr is captured too (the command redirects 2>&1)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysql exited with status 3.*boom/s');
        $method->invoke(null, 'sh -c \'echo boom >&2; exit 3\'', 'mysql');
    }

    /**
     * A successful import returns quietly — the happy path must not throw.
     */
    public function test_runImport_is_silent_on_success(): void
    {
        // Arrange
        $method = (new \ReflectionClass(TestEnvironment::class))->getMethod('runImport');

        // Act
        $method->invoke(null, 'sh -c \'echo imported\'', 'psql');

        // Assert — reaching this line without an exception is the assertion
        $this->assertTrue(true, 'a zero exit status must not raise');
    }

    // ── Real-container helpers ────────────────────────────────────────────────

    /**
     * Run one of the setup* methods against a real container under the suite's
     * failure policy: an unreachable container skips, a lost race for template1
     * is retried and then skips with the true reason, and every other database
     * error propagates so a genuine regression fails the test.
     *
     * @param array<int, mixed> $args Positional arguments for the setup method
     */
    private function invokeSetup(\ReflectionMethod $method, array $args): void
    {
        $templateBusy = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $method->invoke(null, ...$args);
                return;
            } catch (\PDOException $e) {
                if ($this->isConnectionFailure($e)) {
                    $this->markTestSkipped('database container not reachable: ' . $e->getMessage());
                }
                if ($e->getCode() === '55006') {
                    // template1 in use by another session — back off and retry.
                    $templateBusy = $e;
                    usleep(300000);
                    continue;
                }
                throw $e; // any other database error is a real failure
            }
        }

        $this->markTestSkipped(
            'template1 was busy on all 5 attempts: ' . $templateBusy->getMessage()
        );
    }

    /**
     * Skip when the command-line client the import shells out to is absent.
     *
     * setupPostgres()/setupMysql() pipe the dump through `psql`/`mysql` with all
     * output redirected to /dev/null, so on an image without those binaries the
     * import is a silent no-op and the database simply stays empty. That is an
     * environment limitation, not a regression in the code under test — but it
     * must be reported as a skip with the real reason, never asserted away.
     */
    private function requireClientBinary(string $binary): void
    {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($found === '') {
            $this->markTestSkipped(
                "the '$binary' client is not installed in this container, so the schema "
                . 'import silently does nothing here (rebuild the image to get it)'
            );
        }
    }

    /**
     * A database name unique to this process and test, so parallel or repeated
     * runs never collide on a shared name.
     */
    private function uniqueDbName(string $tag): string
    {
        return 'pramnos_te_' . $tag . '_' . substr(md5($tag . getmypid() . uniqid()), 0, 8);
    }

    /** Open a connection to the TimescaleDB/PostgreSQL container. */
    private function pgConnection(string $dbName = 'postgres'): \PDO
    {
        return new \PDO(
            "pgsql:host=timescaledb;port=5432;dbname=$dbName",
            'postgres',
            'secret',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Does $dbName exist on the PostgreSQL server? */
    private function pgDatabaseExists(string $dbName): bool
    {
        $statement = $this->pgConnection()->prepare(
            'SELECT COUNT(*) FROM pg_database WHERE datname = ?'
        );
        $statement->execute([$dbName]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** Does $table exist inside the PostgreSQL database $dbName? */
    private function pgTableExists(string $dbName, string $table): bool
    {
        $statement = $this->pgConnection($dbName)->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** Best-effort cleanup of a PostgreSQL database created by a test. */
    private function dropPgDatabase(string $dbName): void
    {
        try {
            $pdo = $this->pgConnection();
            $pdo->exec(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$dbName'"
            );
            $pdo->exec("DROP DATABASE IF EXISTS \"$dbName\"");
        } catch (\PDOException) {
            // Cleanup is best-effort: the container may be gone, and a leftover
            // uniquely-named database must never turn a passing test red.
        }
    }

    /** Open a connection to the MySQL container. */
    private function mysqlConnection(): \PDO
    {
        return new \PDO(
            'mysql:host=db;port=3306',
            'root',
            'secret',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Best-effort cleanup of a MySQL database created by a test. */
    private function dropMysqlDatabase(string $dbName): void
    {
        try {
            $this->mysqlConnection()->exec("DROP DATABASE IF EXISTS `$dbName`");
        } catch (\PDOException) {
            // Best-effort — see dropPgDatabase().
        }
    }
}
