<?php
namespace Pramnos\Framework\Testing;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Handles test environment setup and database management.
 * 
 * This class provides utility methods to initialize the testing environment, 
 * including database creation/import and process synchronization for parallel test runs.
 */
class TestEnvironment
{
    /**
     * How long to wait for a database that accepts a connection and then hangs.
     *
     * Not for an unresolvable hostname: that blocks in `getaddrinfo()` before a socket
     * exists — 8.00 seconds flat in this project's container. See
     * BaseTestCase::CONNECT_TIMEOUT.
     */
    private const CONNECT_TIMEOUT = 1;

    /**
     * Directory for the bootstrap lock file.
     * @var string|null
     */
    public static $lockDir;

    /**
     * Set up the test environment.
     * 
     * This method should be called from the test suite's bootstrap.php file.
     * It ensures the ROOT constant is defined, acquires a bootstrap lock, 
     * and initializes the test database.
     * 
     * @param string $testSettingsPath Path to the test settings file
     * @param string|null $schemaPath Path to an SQL dump file to import (optional)
     * @throws RuntimeException
     */
    public static function setup($testSettingsPath, $schemaPath = null)
    {
        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true); // @codeCoverageIgnore — UNITTESTING is always pre-defined by the test bootstrap
        }

        // Application::close() calls exit() unless this is defined, in which case it
        // throws instead. Under PHPUnit an exit() is not a failing test: the process
        // stops mid-run, the summary never prints and whatever the dying page wrote
        // — a maintenance page, a 404 — lands in the terminal as if it were test
        // output. Every path that can end a request goes through close(), so without
        // this a single database fault silently truncates the whole suite.
        if (!defined('PRAMNOS_TESTING')) {
            define('PRAMNOS_TESTING', true); // @codeCoverageIgnore — pre-defined by this framework's own bootstrap
        }

        /**
         * Hash passwords at the cheapest cost bcrypt accepts.
         *
         * The production cost is deliberately slow — that is its entire job — and a
         * suite pays it on every fixture. Measured in this framework: **143 ms per
         * hash**, and two-factor enrolment hashes ten backup codes, so a single
         * `startSetup()` + `completeSetup()` costs 1.4 s before the test does
         * anything. At cost 4 the same hash is 0.71 ms.
         *
         * This framework's own bootstrap has set it since the suite was profiled.
         * The bootstrap it *scaffolds* did not, so every project paid the full cost
         * — one project's 188 integration tests took 69 s, of which 62 s was 23
         * tests that enrol two-factor authentication.
         *
         * Nothing about the algorithm changes: a hash made at cost 4 is verified by
         * the same `password_verify()` the application calls. Set the variable
         * yourself if a test is genuinely about the cost.
         */
        if (getenv(\Pramnos\Auth\PasswordHash::COST_ENV) === false) {
            putenv(\Pramnos\Auth\PasswordHash::COST_ENV . '=4');
        }

        if (!defined('ROOT')) {
            // @codeCoverageIgnoreStart
            // ROOT is always defined before tests run; this guard exists only for
            // misuse detection outside the normal test bootstrap.
            throw new RuntimeException('ROOT constant must be defined before calling TestEnvironment::setup()');
            // @codeCoverageIgnoreEnd
        }

        // Return early if test settings don't exist (e.g. running framework core unit tests)
        if (!file_exists($testSettingsPath)) {
            return;
        }

        // Acquire lock to prevent race conditions in parallel tests
        $lockAcquired = self::acquireLock();

        if ($lockAcquired) {
            try {
                self::initializeDatabase($testSettingsPath, $schemaPath);
            } catch (\Exception $e) {
                // If DB setup fails, we want to know, but maybe not kill the process 
                // if it's a sub-process or if some tests don't need it.
                // For now, we'll re-throw for the primary process to ensure visibility.
                throw $e;
            }
        }
    }

    /**
     * Acquire an exclusive file lock for the bootstrap process.
     * 
     * Uses non-blocking exclusive locking to allow multiple PHPUnit processes 
     * (e.g., when using Process Isolation) to synchronize their initialization.
     * 
     * @return bool True if the lock was successfully acquired (primary process)
     * @throws RuntimeException
     */
    protected static function acquireLock()
    {
        $dir = self::$lockDir ?? (defined('ROOT') ? ROOT . '/var' : sys_get_temp_dir());
        $lockFile = $dir . '/phpunit-bootstrap.lock';
        
        if (!file_exists(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            // @codeCoverageIgnoreStart
            // fopen on sys_get_temp_dir() never returns false in a normal test environment.
            throw new RuntimeException('Unable to open lock file: ' . $lockFile);
            // @codeCoverageIgnoreEnd
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false; // Sub-process already detected an existing lock
        }

        // Store handle in globals to keep lock until the process termination
        $GLOBALS['PRAMNOS_TEST_LOCK'] = $handle;
        return true;
    }

    /**
     * Drop and recreate the test database and import the schema dump.
     * 
     * @param string      $testSettingsPath
     * @param string|null $schemaPath
     * @throws RuntimeException
     */
    protected static function initializeDatabase($testSettingsPath, $schemaPath)
    {
        if (!file_exists($testSettingsPath)) {
            throw new RuntimeException("Test settings not found at: $testSettingsPath");
        }

        $settings = include($testSettingsPath);
        $dbConfig = $settings['database'];
        $type = $dbConfig['type'] ?? 'mysql';
        $host = $dbConfig['hostname'];
        $port = $dbConfig['port'] ?? null;
        $dbName = $dbConfig['database'];
        $user = $dbConfig['user'];
        $pass = $dbConfig['password'];

        // Docker detection for standard hostnames
        if ($host === 'localhost' && file_exists('/.dockerenv')) {
            $host = ($type === 'postgresql' || $type === 'pgsql') ? 'postgres' : 'mysql';
        }

        try {
            if ($type === 'postgresql' || $type === 'pgsql') {
                self::setupPostgres($host, $port, $dbName, $user, $pass, $schemaPath);
            } else {
                self::setupMysql($host, $port, $dbName, $user, $pass, $schemaPath);
            }
        } catch (PDOException $e) {
            throw new RuntimeException("Database setup failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Recreate a PostgreSQL test database.
     */
    protected static function setupPostgres($host, $port, $dbName, $user, $pass, $schemaPath)
    {
        $dsn = "pgsql:host=$host;port=" . ($port ?? 5432) . ";dbname=postgres"
            . ';connect_timeout=' . self::CONNECT_TIMEOUT;
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        // Clean existing sessions and drop DB
        $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$dbName'");
        $pdo->exec("DROP DATABASE IF EXISTS \"$dbName\"");

        self::retryWhileTemplateBusy(function () use ($pdo, $dbName) {
            // template1 must be session-free for the copy, and the sessions on it
            // are not ours to wait for — see retryWhileTemplateBusy().
            $pdo->exec(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
                . "WHERE datname = 'template1' AND pid <> pg_backend_pid()"
            );
            $pdo->exec("CREATE DATABASE \"$dbName\" WITH TEMPLATE template1");
        });

        // Import dump via psql if provided. ON_ERROR_STOP makes psql exit non-zero
        // on the first failing statement — without it a dump can fail statement by
        // statement and still report success.
        if ($schemaPath && file_exists($schemaPath)) {
            $command = sprintf(
                'PGPASSWORD=%s psql -v ON_ERROR_STOP=1 -h %s -p %s -U %s -d %s -f %s',
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($port ?? 5432),
                escapeshellarg($user),
                escapeshellarg($dbName),
                escapeshellarg($schemaPath)
            );
            self::runImport($command, 'psql');
        }
    }

    /**
     * Run a CREATE DATABASE attempt, retrying while its template is still busy.
     *
     * PostgreSQL refuses to copy a template database that has any session
     * attached to it, and on TimescaleDB something always does: the extension
     * runs one background-worker scheduler per database, template1 included, and
     * that worker reconnects on a schedule of its own. So the copy fails with
     * SQLSTATE 55006 at random — the test suite could not even bootstrap, and
     * only sometimes.
     *
     * Terminating template1's sessions is not a fix by itself, because the
     * scheduler can be back before the next statement runs. The terminate and the
     * copy have to be retried together, which is what $attempt does. Any other
     * error is rethrown immediately; a busy template is the only thing worth
     * waiting on.
     *
     * @param callable $create      Terminates the template's sessions and copies it
     * @param int      $attempts    How many times to try before giving up
     * @param int      $waitMicroseconds Pause between attempts
     */
    protected static function retryWhileTemplateBusy(
        callable $create,
        int $attempts = 10,
        int $waitMicroseconds = 200000
    ): void {
        for ($attempt = 1;; $attempt++) {
            try {
                $create();
                return;
            } catch (PDOException $e) {
                if ($attempt >= $attempts || !self::isTemplateBusy($e)) {
                    throw $e;
                }
                if ($waitMicroseconds > 0) {
                    usleep($waitMicroseconds);
                }
            }
        }
    }

    /**
     * Is this the "source database is being accessed by other users" error?
     *
     * SQLSTATE 55006 is object_in_use. PDO reports it both as the exception code
     * and as errorInfo[0]; either is enough.
     */
    protected static function isTemplateBusy(PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '55006'
            || (string) $e->getCode() === '55006';
    }

    /**
     * Recreate a MySQL test database.
     */
    protected static function setupMysql($host, $port, $dbName, $user, $pass, $schemaPath)
    {
        $dsn = "mysql:host=$host;port=" . ($port ?? 3306);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        $pdo->exec("DROP DATABASE IF EXISTS `$dbName` ");
        $pdo->exec("CREATE DATABASE `$dbName`");

        // Import dump via mysql if provided
        if ($schemaPath && file_exists($schemaPath)) {
            $command = sprintf(
                'MYSQL_PWD=%s mysql -h %s -P %s -u %s %s < %s',
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($port ?? 3306),
                escapeshellarg($user),
                escapeshellarg($dbName),
                escapeshellarg($schemaPath)
            );
            self::runImport($command, 'mysql');
        }
    }

    /**
     * Run a schema-import command and turn any failure into an exception.
     *
     * The import shells out to the database's command-line client, and a test
     * database that was created but never populated is far worse than a loud
     * failure: every later test fails somewhere else, for reasons that have
     * nothing to do with the real cause. So the exit status is checked, and the
     * client's own output (stderr included) is carried into the message.
     *
     * Exit status 127 is singled out because it has one meaning here — the
     * client binary is not installed in this environment — and its bare shell
     * message ("psql: not found") explains nothing on its own.
     *
     * @param  string $command The full shell command to run
     * @param  string $client  Client binary name, for the error message
     * @throws RuntimeException When the client exits with a non-zero status
     */
    protected static function runImport(string $command, string $client): void
    {
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        if ($status === 0) {
            return;
        }

        if ($status === 127) {
            throw new RuntimeException(
                "Schema import failed: the '$client' client is not installed in this "
                . 'environment, so the dump could not be imported and the test database '
                . 'would have been left empty.'
            );
        }

        $detail = trim(implode("\n", $output));
        throw new RuntimeException(
            "Schema import failed: $client exited with status $status"
            . ($detail !== '' ? ": $detail" : '')
        );
    }

    /**
     * Run a shell command.
     *
     * @param string $command
     * @return string
     */
    protected static function runCommand(string $command): string
    {
        return (string)shell_exec($command . ' 2>&1');
    }
}
