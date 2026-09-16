<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;

/**
 * A scaffolded project's suite must reach the **test** database, not the developer's.
 *
 * It did not, and neither half was wrong on its own. `TestEnvironment::setup()` read
 * `app/config/testsettings.php`, built `<db>_test` and stopped — it never told anything to
 * use it. `BaseTestCase::setUp()` then called `Application::init()` with no argument, so
 * `Settings::getInstance()` fell back to `app/config/settings.php`: the development
 * connection. Every test in every scaffolded project read and wrote the developer's
 * working data, while the database built for them sat empty.
 *
 * **What made it dangerous rather than untidy** is the advice around it. The Testing Guide
 * recommends factories, seeders and `Model::truncate()` in `tearDown()` — written for a
 * disposable database. A suite that follows it against development data deletes it, and
 * the first symptom is an empty screen rather than a red test.
 *
 * The framework's own suite could never have caught this: its bootstrap points `APP_PATH`
 * at `tests/fixtures/app` and loads that fixture's settings directly, so it has never
 * taken the scaffolded path. This test does, by rendering the scaffolded bootstrap's own
 * settings files into a temporary project and asking `TestEnvironment` what the settings
 * say afterwards.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class ScaffoldedSuiteUsesTheTestDatabaseTest extends TestCase
{
    private string $tmpDir = '';
    private mixed $previousSettings = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-testdb-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/app/config', 0777, true);

        // Settings is a singleton keyed on first load; park whatever the run had.
        $this->previousSettings = Settings::getSetting('database');
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);

        // Put the suite's own connection back for whatever runs next.
        if ($this->previousSettings !== null) {
            Settings::setSetting('database', (array) $this->previousSettings, false);
        }

        parent::tearDown();
    }

    /**
     * After `TestEnvironment::setup()`, the settings name the **test** database.
     *
     * The assertion is on the settings rather than on a query, because the settings are
     * what every later `Database::getInstance()` reads — and what the old code left
     * pointing at development.
     */
    public function testTheSettingsNameTheTestDatabaseAfterSetup(): void
    {
        // Arrange — the two files `init` writes, with the names it gives them.
        $development = 'pramnos_test';
        $test        = $development . '_test';

        file_put_contents(
            $this->tmpDir . '/app/config/testsettings.php',
            $this->settingsFile($test)
        );

        // Act
        \Pramnos\Framework\Testing\TestEnvironment::setup(
            $this->tmpDir . '/app/config/testsettings.php'
        );

        // Assert
        $settings = Settings::getSetting('database');
        $this->assertNotNull($settings, 'the test settings must have been loaded');
        $this->assertSame(
            $test,
            (string) ((array) $settings)['database'],
            'the suite must be pointed at the test database, not the development one'
        );
        $this->assertNotSame($development, (string) ((array) $settings)['database']);
    }

    /**
     * The database is created when it is absent, and kept when it is there.
     *
     * Keeping it is what makes "run only the new migrations" possible: the ledger lives in
     * the database, so a run that dropped it would migrate a hundred tables to discover
     * there was nothing to do. It also used to be dropped on every run and used by
     * nothing, which is the shape of the original defect.
     *
     * Driven through `initializeDatabase()` rather than `setup()`, because `setup()` holds
     * a file lock for the lifetime of the process: it is meant to run once, from a
     * bootstrap, and a second call in the same process finds the lock taken and skips the
     * database entirely. Asking it twice from a test would be testing the lock.
     */
    public function testTheDatabaseIsCreatedOnceAndThenKept(): void
    {
        // Arrange
        $test = 'pramnos_testenv_' . bin2hex(random_bytes(4));
        $settingsPath = $this->tmpDir . '/app/config/testsettings.php';
        file_put_contents($settingsPath, $this->settingsFile($test));

        $environment = new class extends \Pramnos\Framework\Testing\TestEnvironment {
            public static function build(string $path): void
            {
                self::initializeDatabase($path, null);
            }
        };

        $pdo = new \PDO('mysql:host=db;port=3306', 'root', 'secret', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        try {
            // Act — the first run creates it.
            $environment::build($settingsPath);
            $this->assertSame(
                1,
                (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = "
                    . $pdo->quote($test)
                )->fetchColumn(),
                'the first run must create the test database'
            );

            // A marker nothing else would leave, to prove the second run kept it.
            $pdo->exec('CREATE TABLE `' . $test . '`.`kept_between_runs` (id INT)');

            // Act — a second run.
            $environment::build($settingsPath);

            // Assert — still there, so the schema and its migration ledger survive.
            $this->assertSame(
                1,
                (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = "
                    . $pdo->quote($test) . " AND TABLE_NAME = 'kept_between_runs'"
                )->fetchColumn(),
                'a second run must not drop the database it was given'
            );
        } finally {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $test . '`');
        }
    }

    /** The settings file `init` writes, with a database name substituted. */
    private function settingsFile(string $database): string
    {
        return "<?php\nreturn [\n"
            . "    'database' => [\n"
            . "        'type' => 'mysql',\n"
            . "        'hostname' => 'db',\n"
            . "        'user' => 'root',\n"
            . "        'password' => 'secret',\n"
            . "        'database' => '" . $database . "',\n"
            . "        'port' => 3306,\n"
            . "    ],\n];\n";
    }
}
