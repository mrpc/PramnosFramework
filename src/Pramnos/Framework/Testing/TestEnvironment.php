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
            define('UNITTESTING', true);
        }

        if (!defined('ROOT')) {
            throw new RuntimeException('ROOT constant must be defined before calling TestEnvironment::setup()');
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
            throw new RuntimeException('Unable to open lock file: ' . $lockFile);
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
        $dsn = "pgsql:host=$host;port=" . ($port ?? 5432) . ";dbname=postgres";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Clean existing sessions and drop DB
        $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$dbName'");
        $pdo->exec("DROP DATABASE IF EXISTS \"$dbName\"");
        $pdo->exec("CREATE DATABASE \"$dbName\" WITH TEMPLATE template1");

        // Import dump via psql if provided
        if ($schemaPath && file_exists($schemaPath)) {
            $command = sprintf(
                'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -f %s > /dev/null 2>&1',
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($port ?? 5432),
                escapeshellarg($user),
                escapeshellarg($dbName),
                escapeshellarg($schemaPath)
            );
            self::runCommand($command);
        }
    }

    /**
     * Recreate a MySQL test database.
     */
    protected static function setupMysql($host, $port, $dbName, $user, $pass, $schemaPath)
    {
        $dsn = "mysql:host=$host;port=" . ($port ?? 3306);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $pdo->exec("DROP DATABASE IF EXISTS `$dbName` ");
        $pdo->exec("CREATE DATABASE `$dbName`");

        // Import dump via mysql if provided
        if ($schemaPath && file_exists($schemaPath)) {
            $command = sprintf(
                'MYSQL_PWD=%s mysql -h %s -P %s -u %s %s < %s > /dev/null 2>&1',
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($port ?? 3306),
                escapeshellarg($user),
                escapeshellarg($dbName),
                escapeshellarg($schemaPath)
            );
            self::runCommand($command);
        }
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
