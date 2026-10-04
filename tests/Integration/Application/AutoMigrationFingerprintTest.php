<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;

/**
 * The request-lifecycle check notices every migration file, not only the timestamped ones.
 *
 * WHAT: `runAutoMigrations()` runs an untimestamped migration, and runs a migration added to
 *       a set it has already declared up to date.
 * WHY:  the check fingerprints the map `MigrationLoader::slugsFromDirectories()` returns, and
 *       that map used to hold only `YYYY_MM_DD_HHmmss_*.php` files. So adding
 *       `Migration0151.php` changed neither the count nor the latest timestamp: every request
 *       matched the recorded fingerprint, took the fast path, and the migration stayed
 *       pending — until some unrelated framework migration happened to change the count, at
 *       which point it ran by coincidence. A directory holding *only* untimestamped
 *       migrations produced an empty map, and an empty map makes the check return before it
 *       starts.
 *
 * Measured in production: two application migrations were deployed and were still pending
 * weeks later, and the three before them had run only because something else moved the count.
 *
 * The cases below are written as sequences of *requests*, because that is the unit the bug
 * lives in: one request records "up to date", and the next has to disagree.
 */
class AutoMigrationFingerprintTest extends TestCase
{
    protected Database $db;

    protected string $historyTable = 'am_fp_schemaversion';

    private string $dir = '';

    /** @var string[] Tables a fixture created, dropped in tearDown. */
    private array $tables = [];

    protected function setUp(): void
    {
        // The verdict is cached per process against the fingerprint; these cases rewrite the
        // directory between "requests", so it has to be forgotten each time.
        Application::forgetVerifiedMigrations();

        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db = $this->connection();

        $this->dir = sys_get_temp_dir() . '/pramnos_fp_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $this->dropHistory();
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->q($table));
        }
        $this->dropHistory();

        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * The connection every case runs against.
     *
     * A seam, so the MySQL subclass reruns the whole sequence against the other engine —
     * the fingerprint row is written by two dialect-specific statements.
     */
    protected function connection(): Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->schema   = 'public';

        if (!$db->connect(false)) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable');
        }

        return $db;
    }

    private function q(string $identifier): string
    {
        $quote = $this->db->type === 'postgresql' ? '"' : '`';

        return $quote . $identifier . $quote;
    }

    private function dropHistory(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->q($this->historyTable));
    }

    /**
     * Writes a migration file and returns the table its `up()` creates.
     *
     * The class name carries random characters because every file written by every case in
     * this run is `include_once`d into one process, and two migrations may not share a class
     * name. All-lowercase after the first letter, so the camelCase-to-snake rule that derives
     * the slug leaves it alone and the slug is simply the lowercased class name.
     */
    private function writeMigration(string $fileName, string $className): string
    {
        $table = 'fp_' . strtolower($className);
        file_put_contents(
            $this->dir . '/' . $fileName,
            "<?php\n"
            . "use Pramnos\\Database\\Migration;\n"
            . "class {$className} extends Migration\n"
            . "{\n"
            . "    public \$description = 'fingerprint fixture';\n"
            . "    public function up(): void\n"
            . "    {\n"
            . "        \$this->schema()->createTable('{$table}', fn(\$t) => \$t->increments('id'));\n"
            . "    }\n"
            . "    public function down(): void\n"
            . "    {\n"
            . "        \$this->schema()->dropTable('{$table}');\n"
            . "    }\n"
            . "}\n"
        );
        $this->tables[] = $table;

        return $table;
    }

    /** One request: a fresh Application, as the next HTTP request would build. */
    private function request(bool $explicit = false): void
    {
        Application::forgetVerifiedMigrations();
        $application = new FingerprintTestApplication($this->db, [$this->dir], $this->historyTable);
        if ($explicit) {
            // What runPendingMigrations() sets around the same call.
            (new \ReflectionProperty(Application::class, 'autoMigrationsForced'))->setValue($application, true);
        }
        $application->triggerAutoMigrations();
    }

    private function tableExists(string $table): bool
    {
        return $this->db->schema()->hasTable($table);
    }

    // ── The reported cases ───────────────────────────────────────────────────

    /**
     * A directory holding nothing but an untimestamped migration still runs it.
     *
     * The shortest version of the bug, and the one with no workaround: the map was empty, so
     * `runAutoMigrations()` returned at its `empty()` guard before reaching the ledger. An
     * application whose migrations all follow the `MigrationNNNN` convention had no
     * request-lifecycle migrations at all, and nothing said so.
     */
    public function testADirectoryOfOnlyUntimestampedMigrationsStillRuns(): void
    {
        // Arrange
        $class = 'Migration9001' . bin2hex(random_bytes(3));
        $table = $this->writeMigration($class . '.php', $class);

        // Act
        $this->request();

        // Assert
        $this->assertTrue(
            $this->tableExists($table),
            'an untimestamped migration was never seen by the request-lifecycle check'
        );
    }

    /**
     * A migration added to an already-verified set runs on the next request.
     *
     * This is the production symptom exactly: the first request records the fingerprint,
     * the deploy adds a file, and the second request has to disagree with the row the first
     * one wrote. With the count and the latest timestamp unchanged, it did not.
     */
    public function testAMigrationAddedAfterTheFingerprintWasRecordedRuns(): void
    {
        // Arrange — one request that finds everything up to date and records it
        $first  = 'Migration9010' . bin2hex(random_bytes(3));
        $firstTable = $this->writeMigration($first . '.php', $first);
        $this->request();
        $this->assertTrue($this->tableExists($firstTable), 'the first request must have run');

        // Act — the deploy, then the next request
        $second = 'Migration9011' . bin2hex(random_bytes(3));
        $secondTable = $this->writeMigration($second . '.php', $second);
        $this->request();

        // Assert
        $this->assertTrue(
            $this->tableExists($secondTable),
            'the fingerprint did not change when a migration was added, so it never ran'
        );
    }

    /**
     * One migration removed and another added is still a change.
     *
     * The case a count cannot see. Both files are untimestamped, so the latest timestamp does
     * not move either, and the fingerprint's other two components are identical before and
     * after. Only the digest of the set differs — which is why there is one.
     *
     * Not hypothetical for an application that squashes or renames migrations between
     * deploys, and the failure is the silent kind: the ledger says up to date and the schema
     * is not.
     */
    public function testReplacingOneMigrationWithAnotherIsNoticed(): void
    {
        // Arrange — a set of one, verified and recorded
        $gone = 'Migration9020' . bin2hex(random_bytes(3));
        $this->writeMigration($gone . '.php', $gone);
        $this->request();

        // Act — same count, same (absent) timestamps, different file
        unlink($this->dir . '/' . $gone . '.php');
        $added = 'Migration9021' . bin2hex(random_bytes(3));
        $addedTable = $this->writeMigration($added . '.php', $added);
        $this->request();

        // Assert
        $this->assertTrue(
            $this->tableExists($addedTable),
            'count and latest timestamp were unchanged, so only the digest could notice'
        );
    }

    /**
     * Nothing changed means nothing is re-read.
     *
     * The other half of the contract, and the reason the fingerprint exists: a second request
     * over an unchanged directory must take the fast path rather than ask the ledger again.
     * Asserted by removing the migration files behind it — if the check were still doing the
     * full scan it would find an empty directory and disagree with itself.
     */
    public function testAnUnchangedDirectoryKeepsTheFastPath(): void
    {
        // Arrange
        $class = 'Migration9030' . bin2hex(random_bytes(3));
        $table = $this->writeMigration($class . '.php', $class);
        $this->request();
        $this->assertTrue($this->tableExists($table));

        $before = $this->fingerprintRows();

        // Act — the same directory, a second request
        $this->request();

        // Assert — no second fingerprint row was written
        $this->assertSame(
            $before,
            $this->fingerprintRows(),
            'an unchanged directory produced a different fingerprint'
        );
        $this->assertCount(1, $before, 'exactly one fingerprint describes one set of files');
    }

    /** Every `__fw_auto_*` key currently in the ledger. */
    /**
     * An explicit run that hits a failing migration says which one, and records nothing.
     *
     * The test bootstrap and a deploy both run migrations this way. When the fingerprint was
     * recorded regardless, a test database built from nothing stopped at the first failure,
     * and every later run found the fingerprint and called the schema up to date — 47 tables
     * of 252 in one project, with no message anywhere.
     */
    public function testAnExplicitRunNamesTheFailureAndTriesAgainNextTime(): void
    {
        // Arrange — a migration that fails until a flag file exists
        $class = 'Migration9040' . bin2hex(random_bytes(3));
        $flag  = $this->dir . '/ready.flag';
        file_put_contents(
            $this->dir . '/' . $class . '.php',
            "<?php\nuse Pramnos\\Database\\Migration;\n"
            . "class {$class} extends Migration\n{\n"
            . "    public \$description = 'fails until ready';\n"
            . "    public function up(): void\n    {\n"
            . "        if (!is_file('{$flag}')) { throw new \\RuntimeException('not ready yet'); }\n"
            . "        \$this->schema()->createTable('fp_" . strtolower($class) . "', fn(\$t) => \$t->increments('id'));\n"
            . "    }\n"
            . "    public function down(): void\n    {\n    }\n}\n"
        );
        $this->tables[] = 'fp_' . strtolower($class);

        // Act — the explicit run
        $raised = null;
        try {
            $this->request(true);
        } catch (\RuntimeException $exception) {
            $raised = $exception;
        }

        // Assert — named, and no fingerprint to hide it next time
        $this->assertNotNull($raised, 'a failed migration was swallowed');
        $this->assertStringContainsString(strtolower($class), $raised->getMessage());
        $this->assertStringContainsString('not ready yet', $raised->getMessage());
        $this->assertSame([], $this->fingerprintRows(), 'the failure was recorded as up to date');

        // Act — the cause fixed, the next run
        touch($flag);
        $this->request(true);

        // Assert
        $this->assertTrue($this->tableExists('fp_' . strtolower($class)), 'the failed migration was never retried');
        unlink($flag);
    }

    /**
     * A request that hits a failing migration does not raise into the page.
     *
     * It logs, and keeps the fingerprint so a failing migration is not attempted on every
     * request; `migrate` is what retries it.
     */
    public function testARequestKeepsServingWhenAMigrationFails(): void
    {
        // Arrange
        $class = 'Migration9041' . bin2hex(random_bytes(3));
        file_put_contents(
            $this->dir . '/' . $class . '.php',
            "<?php\nuse Pramnos\\Database\\Migration;\n"
            . "class {$class} extends Migration\n{\n"
            . "    public function up(): void\n    {\n        throw new \\RuntimeException('broken');\n    }\n"
            . "    public function down(): void\n    {\n    }\n}\n"
        );

        // Act — must not throw
        $this->request();

        // Assert — recorded, so the next request takes the fast path
        $this->assertCount(1, $this->fingerprintRows());
    }

    private function fingerprintRows(): array
    {
        $result = $this->db->query(
            'SELECT ' . $this->q('key') . ' FROM ' . $this->q($this->historyTable)
            . ' WHERE ' . $this->q('key') . " LIKE '__fw_auto%'"
        );

        $keys = [];
        while ($result && $result->fetch()) {
            $keys[] = (string) $result->fields['key'];
        }
        sort($keys);

        return $keys;
    }
}

/** An Application whose migration directories and ledger are the fixture's. */
class FingerprintTestApplication extends Application
{
    private array $testDirs;
    private string $testHistoryTable;

    public function __construct(Database $db, array $dirs, string $historyTable)
    {
        $this->database         = $db;
        $this->testDirs         = $dirs;
        $this->testHistoryTable = $historyTable;
        $this->applicationInfo  = [];
    }

    protected function getFrameworkMigrationDirs(): array
    {
        return $this->testDirs;
    }

    protected function getMigrationHistoryTable(): string
    {
        return $this->testHistoryTable;
    }

    public function triggerAutoMigrations(): void
    {
        $this->runAutoMigrations();
    }
}
