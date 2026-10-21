<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Messaging\AddStatusDateIndexToMails;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The index the outbox deadline sweep needs, `mails(status, date)`, added only where nothing
 * already leads with `status`.
 *
 * `mail:flush` runs `UPDATE mails … WHERE status = 2 AND date < ?` every minute. On a `mails` with
 * no index on `status` that reads the whole history — nine seconds a minute on 35 million rows,
 * with nothing queued. Both backends: {@see MailsStatusDateIndexPostgreSQLTest}.
 */
class MailsStatusDateIndexTest extends BaseTestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
        ], $this->db);
        require_once ROOT . '/database/migrations/framework/messaging/2026_10_21_000007_add_status_date_index_to_mails.php';
    }

    protected function tearDown(): void
    {
        // The shared `mails` keeps the shape every other test expects: its status index, and not
        // this one.
        $schema = $this->db->schema();
        if ($schema->hasIndex('mails', 'idx_mails_status_date')) {
            $schema->alterTable('mails', fn ($table) => $table->dropIndex('idx_mails_status_date'));
        }
        if (!$schema->hasIndexOn('mails', ['status'])) {
            $schema->alterTable('mails', fn ($table) => $table->index(['status'], 'idx_mails_status'));
        }

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** The migration, on this connection. */
    private function migration(): AddStatusDateIndexToMails
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        return new AddStatusDateIndexToMails($app);
    }

    /**
     * A `mails` with no index on `status` — one that predates the framework's migrations — gets
     * `(status, date)`; running it again changes nothing, and `down()` takes it away.
     */
    public function testATableWithNoStatusIndexGetsOne(): void
    {
        // Arrange — the table as an older installation has it
        $schema = $this->db->schema();
        if ($schema->hasIndex('mails', 'idx_mails_status')) {
            $schema->alterTable('mails', fn ($table) => $table->dropIndex('idx_mails_status'));
        }

        // Act
        $this->migration()->up();
        $this->migration()->up();
        $added = $this->db->schema()->hasIndexOn('mails', ['status', 'date']);
        $this->migration()->down();

        // Assert
        $this->assertTrue($added, 'the sweep has no index to use');
        $this->assertFalse($this->db->schema()->hasIndex('mails', 'idx_mails_status_date'), 'down() left it');
    }

    /**
     * Where an index already leads with `status` — the framework's own `idx_mails_status` — the
     * migration builds nothing: a second index over tens of millions of rows is a maintenance
     * window for no gain.
     */
    public function testATableWithAStatusIndexIsLeftAlone(): void
    {
        // Arrange
        $this->assertTrue($this->db->schema()->hasIndexOn('mails', ['status']), 'precondition: the framework table has it');

        // Act
        $this->migration()->up();

        // Assert
        $this->assertFalse($this->db->schema()->hasIndex('mails', 'idx_mails_status_date'));
    }
}
