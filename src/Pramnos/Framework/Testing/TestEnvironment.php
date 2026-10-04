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
            // UNITTESTING is always pre-defined by the test bootstrap
            define('UNITTESTING', true); // @codeCoverageIgnore
        }

        // Application::close() calls exit() unless this is defined, in which case it
        // throws instead. Under PHPUnit an exit() is not a failing test: the process
        // stops mid-run, the summary never prints and whatever the dying page wrote
        // — a maintenance page, a 404 — lands in the terminal as if it were test
        // output. Every path that can end a request goes through close(), so without
        // this a single database fault silently truncates the whole suite.
        if (!defined('PRAMNOS_TESTING')) {
            // pre-defined by this framework's own bootstrap
            define('PRAMNOS_TESTING', true); // @codeCoverageIgnore
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

        /*
         * **The settings the rest of the run will use.**
         *
         * This is the half that was missing, and its absence was invisible because each
         * half did its own job correctly. This method recreated `<db>_test` and stopped;
         * `BaseTestCase::setUp()` then called `init()` with no argument, so
         * `Settings::getInstance()` fell back to `app/config/settings.php` — the
         * **development** connection. Every scaffolded project's suite read and wrote the
         * developer's working database, while the test database it had just built sat
         * empty and unused.
         *
         * Nothing reported it because nothing was in a position to: this method knew the
         * test settings and told nobody; `init()` knew it had been given nothing and had
         * a reasonable default. Loading them here is what joins the two, and it works in
         * the order's favour — `Settings::getInstance()` keeps the first instance, so the
         * `init()` that follows finds these already in place.
         */
        \Pramnos\Application\Settings::loadSettings($testSettingsPath);

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

            self::migrate($testSettingsPath);
        }
    }

    /**
     * Bring the test database's schema up to date, once per run.
     *
     * The database is created empty and kept between runs, so this is "everything" the
     * first time and "whatever was added since" every time after — the migration ledger
     * lives in the database and answers that question itself. A suite that rebuilt the
     * schema on every run would pay for a hundred migrations to learn there was nothing
     * to do.
     *
     * Guarded on the application existing, because this class is also used by suites that
     * have no `app.php` — the framework's own does not take this path at all.
     *
     * Failures are reported and not thrown. A schema that could not be brought up will
     * fail the tests that need it, with an error naming the table; killing the bootstrap
     * instead would replace a hundred specific failures with one message about migrations
     * from a developer who was trying to run one unrelated test.
     */
    protected static function migrate(string $testSettingsPath): void
    {
        /*
         * **Derived, not required.**
         *
         * `APP_PATH` is defined by `Application::setDefines()`, and at bootstrap time no
         * application has been constructed — a scaffolded `tests/bootstrap.php` defines
         * `ROOT`, `DS`, `sURL` and `URL`, and nothing else. A guard written as
         * `defined('APP_PATH')` therefore returned immediately in every project that has
         * one, and said nothing: the suite pointed at a test database whose schema was
         * never built. Measured in one project, the same commit either way: **0 tables
         * without it, 26 with**.
         *
         * It passed here because this repository's own bootstrap defines `APP_PATH` — the
         * same blind spot that hid the defect this method was written to fix, one layer
         * further in.
         *
         * `ROOT . /app` is the fallback `Application::readApplicationConfig()` already
         * documents and uses for the same reason, so this is the framework's existing
         * answer rather than a new one.
         */
        $appPath = defined('APP_PATH')
            ? APP_PATH
            : (defined('ROOT') ? ROOT . DIRECTORY_SEPARATOR . 'app' : '');

        if ($appPath === '' || !file_exists($appPath . DIRECTORY_SEPARATOR . 'app.php')) {
            return;
        }

        try {
            $application = \Pramnos\Application\Application::getInstance();
            if ($application === null) {
                return;
            }

            $application->init($testSettingsPath);
            $application->runPendingMigrations();
        } catch (\Throwable $exception) {
            fwrite(
                STDERR,
                "\nThe test database's schema could not be brought up to date:\n  "
                . $exception->getMessage()
                . "\nTests that need those tables will fail; the rest will run.\n\n"
            );
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
            $host = in_array($type, ['postgresql', 'pgsql', 'timescaledb'], true) ? 'postgres' : 'mysql';
        }

        $limits = (array) ($settings['test_database']['rebuild_above'] ?? []);
        if ($limits !== []) {
            self::rebuildIfOvergrown($type, $host, $port, $dbName, $user, $pass, $limits);
        }

        try {
            if (in_array($type, ['postgresql', 'pgsql', 'timescaledb'], true)) {
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

        /*
         * **Created when absent, not dropped and rebuilt on every run.**
         *
         * Dropping was safe while nothing used this database — and nothing did, which
         * is the defect this is half of: the settings were never loaded, so the suite
         * ran against the *development* database and the freshly recreated one was a
         * standing lie. Now that the suite really does use it, dropping it would mean
         * migrating a hundred tables before every run.
         *
         * Keeping it is what makes "run only the new migrations" possible: the ledger
         * survives, so the first run migrates everything and each run after it
         * migrates what was added since. Tests remove the rows they add; the schema is
         * not theirs to reset.
         */
        $exists = $pdo->query(
            "SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote($dbName)
        )->fetchColumn();

        if ($exists === false) {
            self::retryWhileTemplateBusy(function () use ($pdo, $dbName) {
                // template1 must be session-free for the copy, and the sessions on it
                // are not ours to wait for — see retryWhileTemplateBusy().
                $pdo->exec(
                    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
                    . "WHERE datname = 'template1' AND pid <> pg_backend_pid()"
                );
                $pdo->exec("CREATE DATABASE \"$dbName\" WITH TEMPLATE template1");
            });
        }

        /*
         * **No waiting for the disk on commit, in this database only.**
         *
         * A test suite commits thousands of small transactions, and each one waits for
         * its WAL to be flushed. Measured in one project: a test class from 47 s to
         * 28.5 s, the whole suite from 12:00 to 6:45. What it gives up is the last
         * fraction of a second of commits if the server crashes, which for a database
         * rebuilt by the suite itself costs nothing. Set per database, so a development
         * database on the same server keeps full durability; it applies to the sessions
         * opened after it, which is every one the suite opens.
         */
        try {
            $pdo->exec('ALTER DATABASE "' . str_replace('"', '""', $dbName) . '" SET synchronous_commit = off');
        } catch (\PDOException) {
            // Not the database's owner: the suite is only slower, never wrong.
        }

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
     * Drop the test database when a table in it has outgrown its limit.
     *
     * ```php
     * // app/config/testsettings.php
     * 'test_database' => ['rebuild_above' => ['users' => 5000, 'notifications' => 100000]],
     * ```
     *
     * The test database is kept between runs, so whatever a test leaves behind stays, and
     * every later run pays for it: one project's suite went from minutes to 22 under 5,079
     * administrators and 782,645 notifications. Cleanup prevents most of that; this is the
     * floor under it. Past a limit the database is dropped here, and created and migrated
     * from nothing by the steps that follow, at the cost of one full migration.
     *
     * Keys are table names as they are in the database (with the prefix, on MySQL). A table
     * that does not exist counts as empty, and so does a database that does not exist yet.
     *
     * @param array<string, int> $limits table => the most rows it may hold
     */
    protected static function rebuildIfOvergrown($type, $host, $port, $dbName, $user, $pass, array $limits): void
    {
        $isPg = in_array($type, ['postgresql', 'pgsql', 'timescaledb'], true);
        $dsn  = $isPg
            ? "pgsql:host=$host;port=" . ($port ?? 5432) . ";dbname=$dbName;connect_timeout=" . self::CONNECT_TIMEOUT
            : "mysql:host=$host;port=" . ($port ?? 3306) . ";dbname=$dbName";

        try {
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT]);
        } catch (\PDOException) {
            return; // Not there yet: nothing has grown.
        }

        $over = [];
        foreach ($limits as $table => $limit) {
            $quoted = $isPg
                ? implode('.', array_map(static fn ($part) => '"' . str_replace('"', '""', $part) . '"', explode('.', (string) $table)))
                : '`' . str_replace('`', '``', (string) $table) . '`';
            try {
                // Raw: this runs before any application or framework connection exists.
                $rows = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            } catch (\PDOException) {
                continue; // No such table: empty.
            }
            if ($rows > (int) $limit) {
                $over[] = "$table has $rows rows (limit $limit)";
            }
        }
        $pdo = null;

        if ($over === []) {
            return;
        }

        fwrite(STDERR, "\nRebuilding the test database $dbName from nothing: " . implode('; ', $over) . ".\n\n");

        $server = new PDO(
            $isPg ? "pgsql:host=$host;port=" . ($port ?? 5432) . ';dbname=postgres' : "mysql:host=$host;port=" . ($port ?? 3306),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT]
        );
        if ($isPg) {
            // A session still attached would make the drop wait; none of them is a test's to keep.
            $server->exec(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
                . $server->quote($dbName) . ' AND pid <> pg_backend_pid()'
            );
            $server->exec('DROP DATABASE IF EXISTS "' . str_replace('"', '""', $dbName) . '"');
        } else {
            $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbName) . '`');
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

        // Created when absent, not dropped and rebuilt on every run — see the
        // PostgreSQL branch for why. `IF NOT EXISTS` rather than a lookup because
        // MySQL offers it and the two statements would race each other anyway.
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName`");

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
