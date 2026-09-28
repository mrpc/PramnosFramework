<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The legacy migration loader empties the queue and flushes the column cache.
 *
 * `Application::runMigration()` is the other way a migration runs: `upgrade()` uses it, and
 * `exec()` reaches `upgrade()` on its own when the version check fails. It is not
 * `MigrationRunner`, and it had neither of the two things that path learned.
 *
 * - **`addQuery()` queues, and nothing emptied the queue here.** A legacy migration that
 *   queued its statements and returned was recorded in `schemaversion` and changed nothing
 *   — the same defect `MigrationRunner` was fixed for, in the caller nobody looked at. Two
 *   callers, one fix, and only one of them had it.
 * - **`getColumns()` caches a table's schema for an hour**, so a migration that added a
 *   column left every reader answering without it. A payload indexing a row by name then
 *   emits an undefined-key warning ahead of the body, which makes the JSON unparseable: the
 *   screen says it could not load anything and nothing reports a failure.
 *
 * Asserted against the real database and the real loader, because both claims are about
 * what this method does with a migration on disk — which is the part a unit test of the
 * migration class cannot reach.
 */
#[CoversClass(Application::class)]
class LegacyMigrationPathFlushesColumnsTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;

    private string $table = '';

    private string $migrationFile = '';

    private string $className = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        \Pramnos\Application\Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );
        Application::getInstance();

        $this->db = \Pramnos\Framework\Testing\Connection::fresh();

        if (!$this->db->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        $this->table = 'lmp_probe_' . bin2hex(random_bytes(4));
        $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        $this->db->query(
            'CREATE TABLE `' . $this->table . '` (`id` INT NOT NULL, `name` VARCHAR(50) NULL)'
        );

        $this->db->query('CREATE TABLE IF NOT EXISTS `schemaversion` (`key` VARCHAR(100) NULL)');
    }

    protected function tearDown(): void
    {
        if ($this->migrationFile !== '' && is_file($this->migrationFile)) {
            @unlink($this->migrationFile);
        }
        if ($this->table !== '') {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        }
    }

    /**
     * Write a legacy migration to disk, where `runMigration()` looks for it.
     *
     * It requires the file by name under `APP_PATH/Migrations`, which under the test
     * bootstrap is a fixture directory — so this writes a real one and removes it after.
     */
    private function writeMigration(string $body): void
    {
        $this->className = 'LmpProbe' . bin2hex(random_bytes(4));
        $dir = APP_PATH . DS . 'Migrations';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->migrationFile = $dir . DS . $this->className . '.php';

        file_put_contents($this->migrationFile, <<<PHP_MIGRATION
<?php
namespace TestApp\\Migrations;

use Pramnos\\Database\\Migration;

final class {$this->className} extends Migration
{
    public \$version = '{$this->className}';

    public function up(): void
    {
{$body}
    }

    public function down(): void
    {
    }
}
PHP_MIGRATION);
    }

    /** The application the loader runs against, carrying this test's connection. */
    private function application(): Application
    {
        $app = Application::getInstance();
        $app->database = $this->db;
        $app->applicationInfo['namespace'] = 'TestApp';

        return $app;
    }

    /** The column names `getColumns()` currently answers with, cache and all. */
    private function cachedColumns(): array
    {
        $result = $this->db->getColumns($this->table);

        if ($result === false) {
            return [];
        }

        $names = [];
        while ($result->fetch()) {
            $names[] = (string) ($result->fields['Field'] ?? '');
        }

        return $names;
    }

    /**
     * A queued statement runs, and the cache no longer hides the column it added.
     *
     * Both halves in one migration because that is how they meet: the queue has to run
     * before there is anything for the flush to matter about.
     */
    public function testAQueuedStatementRunsAndTheCacheIsFlushed(): void
    {
        // Arrange — warm the cache with the old shape
        $this->assertSame(['id', 'name'], $this->cachedColumns());

        $this->writeMigration(
            "        \$this->addQuery('ALTER TABLE `{$this->table}` ADD COLUMN `queued` INT NULL');"
        );

        // Act
        $this->application()->runMigration($this->className);

        // Assert — the statement ran…
        $direct = $this->db->query('SHOW COLUMNS FROM `' . $this->table . '` LIKE \'queued\'');
        $this->assertSame(1, (int) ($direct->numRows ?? 0), 'the queued statement never ran');

        // …and nothing is still answering with the list from before it
        $this->assertContains(
            'queued',
            $this->cachedColumns(),
            'the column cache is still holding the pre-migration list'
        );
    }

    /**
     * A migration that raises still flushes.
     *
     * The case that matters most and the reason the flush is in a `finally`: some of its
     * DDL ran, the ledger has no row, and the cache is now wrong about a table nobody
     * knows changed. Leaving it would be the worst of the three outcomes.
     */
    public function testAMigrationThatRaisesStillFlushes(): void
    {
        // Arrange
        $this->assertSame(['id', 'name'], $this->cachedColumns());

        $this->writeMigration(
            "        \$this->DB()->query('ALTER TABLE `{$this->table}` ADD COLUMN `half` INT NULL');\n"
            . "        throw new \\RuntimeException('half way');"
        );

        // Act
        $raised = false;

        try {
            $this->application()->runMigration($this->className);
        } catch (\Throwable) {
            $raised = true;
        }

        // Assert — captured and checked outside the catch
        $this->assertTrue($raised, 'the migration was expected to raise');
        $this->assertContains(
            'half',
            $this->cachedColumns(),
            'a migration that failed half way left the cache describing a table it had changed'
        );
    }
}
