<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Policy;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Policy\PolicyEngine;

/**
 * Retention policies delete old rows, keep new ones, and do it in bounded statements.
 *
 * `PolicyEngine` is what gives every non-TimescaleDB backend a retention policy at all:
 * without the extension, `SchemaBuilder::addRetentionPolicy()` registers a row here
 * rather than a chunk-drop job. So a table with a declared retention on MySQL is pruned
 * by this method and by nothing else.
 *
 * It used to issue one unbounded `DELETE`. That passes a correctness assertion and is
 * still the bug: on a table with real backlog it is a single statement holding locks for
 * as long as it takes, inside a daemon, against a table the application is still writing
 * to. The batching tests below are the point of this class rather than an extra.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class PolicyEngineRetentionMySQLTest extends TestCase
{
    protected static ?Database $sharedDb = null;
    protected static string $table = 'pramnos_retention_probe';

    protected Database $db;
    protected ?Database $previousSingleton = null;
    protected PolicyEngine $engine;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
        Application::getInstance();

        static::$sharedDb = static::openConnection();
        if (static::$sharedDb !== null) {
            static::createPolicyTableOn(static::$sharedDb);
            static::createTableOn(static::$sharedDb);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$sharedDb !== null) {
            static::$sharedDb->query('DROP TABLE IF EXISTS ' . static::$table);
            static::$sharedDb = null;
        }
    }

    protected function setUp(): void
    {
        if (static::$sharedDb === null) {
            $this->markTestSkipped('Database container not reachable');
        }

        $this->db = static::$sharedDb;

        // PolicyEngine takes its connection from the application and resolves the policy
        // table through the schema builder on it, so the singleton has to be this one.
        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        $app            = Application::getInstance();
        $app->database  = $this->db;
        $this->engine   = new PolicyEngine($app);

        $this->db->query('DELETE FROM ' . static::$table);
        $this->clearPolicies();
    }

    protected function tearDown(): void
    {
        $this->clearPolicies();

        $singleton = &Factory::getDatabase();
        $singleton = $this->previousSingleton;
    }

    protected static function openConnection(): ?Database
    {
        $settings = Settings::getSetting('database');
        if (!$settings) {
            return null;
        }

        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 3306;

        try {
            $db->connect(true);
        } catch (\Throwable) {
            return null;
        }

        return $db->connected ? $db : null;
    }

    /**
     * Make sure the policy store exists.
     *
     * Created here rather than assumed, because other integration classes drop the
     * `pramnos` schema and its MySQL equivalents as part of their own cleanup — so
     * whether this table is present depends on what ran before, which is not a thing a
     * test should depend on. Columns mirror the framework migration; only the ones
     * PolicyEngine reads and writes are needed.
     */
    protected static function createPolicyTableOn(Database $db): void
    {
        $name = $db->schema()->resolveTableName('pramnos.framework_policies');

        $db->query(
            'CREATE TABLE IF NOT EXISTS ' . $name . ' ('
            . 'policyid INT AUTO_INCREMENT PRIMARY KEY, '
            . 'policy_type VARCHAR(50) NOT NULL, '
            . 'target VARCHAR(255) NOT NULL, '
            . 'config JSON NULL, '
            . 'enabled TINYINT(1) NOT NULL DEFAULT 1, '
            . 'last_run TIMESTAMP NULL, '
            . 'next_run TIMESTAMP NULL, '
            . 'last_result TEXT NULL, '
            . 'last_error TEXT NULL, '
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB'
        );
    }

    protected static function createTableOn(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS ' . static::$table);
        $db->query(
            'CREATE TABLE ' . static::$table . ' ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'created_at DATETIME NOT NULL, '
            . 'INDEX idx_probe_created (created_at)'
            . ') ENGINE=InnoDB'
        );
    }

    /** Remove every policy this class registered, whatever a test did. */
    protected function clearPolicies(): void
    {
        $policies = $this->db->schema()->resolveTableName('pramnos.framework_policies');
        $this->db->query(
            'DELETE FROM ' . $policies . " WHERE target = '" . static::$table . "'"
        );
    }

    /**
     * Insert $count rows, all $daysAgo days old.
     *
     * A thousand per statement: seeding is not what is under test, and one row at a time
     * would make the fixture the slowest thing in the suite.
     */
    protected function seed(int $count, int $daysAgo): void
    {
        $stamp = date('Y-m-d H:i:s', strtotime('-' . $daysAgo . ' days'));
        $rows  = array_fill(0, $count, "('" . $stamp . "')");

        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->db->query(
                'INSERT INTO ' . static::$table . ' (created_at) VALUES ' . implode(',', $chunk)
            );
        }
    }

    protected function rowCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS cnt FROM ' . static::$table);

        return (int) ($result->fields['cnt'] ?? -1);
    }

    /** @param array<string,mixed> $config */
    protected function registerRetention(array $config = []): void
    {
        $this->engine->register('retention', static::$table, $config + [
            'interval'    => '30 days',
            'time_column' => 'created_at',
        ]);
    }

    // -------------------------------------------------------------------------
    // Correctness
    // -------------------------------------------------------------------------

    /**
     * Rows older than the interval go; newer ones stay.
     *
     * The floor. Everything else here is about *how* the deleting happens.
     */
    public function testOldRowsGoAndNewRowsStay(): void
    {
        // Arrange
        $this->seed(10, 60);
        $this->seed(5, 2);
        $this->registerRetention();

        // Act
        $results = $this->engine->run();

        // Assert
        $this->assertSame('ok', $results[0]['status'] ?? 'missing');
        $this->assertSame(5, $this->rowCount(), 'only the recent rows survive');
    }

    /**
     * A table with nothing old enough is left alone, and still reports success.
     *
     * By far the common case — a policy runs on schedule against a table that has not
     * aged past its interval — so it must cost one existence check and no delete at all.
     */
    public function testATableWithNothingOldEnoughIsUntouched(): void
    {
        // Arrange
        $this->seed(20, 1);
        $this->registerRetention();

        // Act
        $results = $this->engine->run();

        // Assert
        $this->assertSame('ok', $results[0]['status'] ?? 'missing');
        $this->assertSame(20, $this->rowCount());
    }

    /**
     * An empty table is not an error.
     *
     * The existence check has to answer "nothing to do" rather than fall into the loop,
     * because a policy on a table that is emptied by other means runs on every schedule
     * tick for ever.
     */
    public function testAnEmptyTableIsNotAnError(): void
    {
        // Arrange
        $this->registerRetention();

        // Act
        $results = $this->engine->run();

        // Assert
        $this->assertSame('ok', $results[0]['status'] ?? 'missing');
        $this->assertSame(0, $this->rowCount());
    }

    // -------------------------------------------------------------------------
    // Boundedness — the reason this class exists
    // -------------------------------------------------------------------------

    /**
     * One pass deletes at most `batch` rows, and no more.
     *
     * This is the assertion that fails against the unbounded `DELETE` the method used to
     * issue, and it is the only one that does — an unbounded delete also empties a table
     * correctly, which is precisely why the original went unnoticed.
     *
     * `max_batches => 1` makes a single pass observable: 25 expired rows, a batch of 10,
     * one pass, so exactly 15 must remain. A statement without a `LIMIT` would leave
     * none.
     */
    public function testASinglePassDeletesAtMostOneBatch(): void
    {
        // Arrange
        $this->seed(25, 60);
        $this->registerRetention(['batch' => 10, 'max_batches' => 1]);

        // Act
        $this->engine->run();

        // Assert
        $this->assertSame(15, $this->rowCount(),
            'one pass with a batch of 10 must delete 10 rows, not all 25');
    }

    /**
     * Enough passes clear the whole backlog.
     *
     * The other half: bounding each statement must not become an accidental cap on the
     * work. Three batches of 10 against 25 expired rows has to finish the job.
     */
    public function testEnoughPassesClearTheBacklog(): void
    {
        // Arrange
        $this->seed(25, 60);
        $this->seed(4, 1);
        $this->registerRetention(['batch' => 10, 'max_batches' => 10]);

        // Act
        $this->engine->run();

        // Assert
        $this->assertSame(4, $this->rowCount(), 'only the recent rows survive');
    }

    /**
     * The batch cap leaves a remainder rather than running for ever.
     *
     * A table with months of backlog is deliberately not cleared in one run: the engine
     * runs on a schedule, so the rest goes next time. Holding the daemon for an hour on
     * its first execution looks exactly like a hang, and gets it killed.
     */
    public function testTheBatchCapLeavesTheRestForTheNextRun(): void
    {
        // Arrange
        $this->seed(50, 60);
        $this->registerRetention(['batch' => 5, 'max_batches' => 3]);

        // Act
        $this->engine->run();

        // Assert
        $this->assertSame(35, $this->rowCount(), '3 passes × 5 rows, and no more');
    }

    /**
     * A second run finishes what the first left.
     *
     * The scheduled-catch-up path, which is what makes the cap above safe rather than a
     * silent under-prune.
     */
    public function testASecondRunFinishesTheJob(): void
    {
        // Arrange
        $this->seed(20, 60);
        $this->registerRetention(['batch' => 10, 'max_batches' => 1]);

        // Act
        $this->engine->run();
        $afterFirst = $this->rowCount();
        $this->engine->run();

        // Assert
        $this->assertSame(10, $afterFirst);
        $this->assertSame(0, $this->rowCount());
    }

    /**
     * A nonsensical batch size falls back to the default instead of deleting nothing.
     *
     * Both numbers end up inside a `LIMIT`, and a batch of `0` is a loop that deletes
     * nothing for two hundred passes — a policy reporting `ok` while pruning nothing at
     * all, which is the quietest possible failure. Config files are exactly where a `0`,
     * a `-1` or a string arrives.
     */
    public function testANonsensicalBatchFallsBackToTheDefault(): void
    {
        // Arrange
        $this->seed(12, 60);
        $this->registerRetention(['batch' => 0]);

        // Act
        $this->engine->run();

        // Assert
        $this->assertSame(0, $this->rowCount(),
            'a batch of 0 must fall back to the default, not prune nothing');
    }
}
