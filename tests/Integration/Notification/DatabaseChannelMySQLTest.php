<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Notification\Channels\DatabaseChannel;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotifiableTrait;
use Pramnos\Notification\NotificationInterface;

/**
 * Integration tests for DatabaseChannel against a live MySQL 8.0 database.
 *
 * Tests verify that dispatching a notification actually inserts a row into the
 * `notifications` table with the correct UUID, type, notifiable identifiers,
 * JSON payload, and null read_at timestamp.
 *
 * Isolation: setUp creates the notifications table via the framework migration;
 * tearDown drops it so every test starts from a clean slate.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class DatabaseChannelMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->app = $this->makeApp();

        $this->dropNotificationsTable();
        $this->runNotificationsMigration();
    }

    protected function tearDown(): void
    {
        $this->dropNotificationsTable();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Dispatching a notification must insert exactly one row into the
     * notifications table with the correct type and notifiable identifiers.
     */
    public function testSendInsertsRowWithCorrectTypeAndNotifiable(): void
    {
        // Arrange
        $channel  = new DatabaseChannel($this->db);
        $user     = new IntegrationNotifiable(42);
        $notif    = new IntegrationDatabaseNotification(['message' => 'Test notification']);

        // Act
        $channel->send($user, $notif);

        // Assert — one row inserted
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM notifications");
        $this->assertSame(1, (int) $result->fields['cnt'], 'Must insert exactly one notification row');

        $row = $this->db->query("SELECT * FROM notifications LIMIT 1")->fields;
        $this->assertSame(IntegrationDatabaseNotification::class, $row['type'],
            'type must be the FQCN of the notification class');
        $this->assertSame(IntegrationNotifiable::class, $row['notifiable_type'],
            'notifiable_type must be the FQCN of the notifiable class');
        $this->assertSame(42, (int) $row['notifiable_id'],
            'notifiable_id must match the entity primary key');
    }

    /**
     * The data column must contain valid JSON that reproduces the array
     * returned by the notification's toDatabase() method.
     */
    public function testSendStoresJsonDataFromToDatabase(): void
    {
        // Arrange
        $channel  = new DatabaseChannel($this->db);
        $user     = new IntegrationNotifiable(7);
        $payload  = ['message' => 'Invoice #42 paid', 'amount' => 99.99, 'invoice_id' => 42];
        $notif    = new IntegrationDatabaseNotification($payload);

        // Act
        $channel->send($user, $notif);

        // Assert — data is valid JSON matching the payload
        $row  = $this->db->query("SELECT data FROM notifications LIMIT 1")->fields;
        $data = json_decode($row['data'], true);
        $this->assertSame($payload, $data, 'data column must contain the JSON-encoded toDatabase() payload');
    }

    /**
     * The read_at column must be NULL after initial insert — notifications
     * are unread until the application explicitly marks them.
     */
    public function testSendLeavesReadAtAsNull(): void
    {
        // Arrange
        $channel = new DatabaseChannel($this->db);
        $user    = new IntegrationNotifiable(1);
        $notif   = new IntegrationDatabaseNotification(['msg' => 'hi']);

        // Act
        $channel->send($user, $notif);

        // Assert
        $row = $this->db->query("SELECT read_at FROM notifications LIMIT 1")->fields;
        $this->assertNull($row['read_at'],
            'read_at must be NULL immediately after dispatch — notification starts unread');
    }

    /**
     * The id column must be a valid UUID v4 (36-char string, xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx).
     */
    public function testSendStoresUuidV4AsId(): void
    {
        // Arrange
        $channel = new DatabaseChannel($this->db);
        $user    = new IntegrationNotifiable(5);
        $notif   = new IntegrationDatabaseNotification(['x' => 1]);

        // Act
        $channel->send($user, $notif);

        // Assert
        $row = $this->db->query("SELECT id FROM notifications LIMIT 1")->fields;
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $row['id'],
            'id must be a valid UUID v4'
        );
    }

    /**
     * Sending multiple notifications inserts multiple rows, each with a
     * distinct UUID.
     */
    public function testSendMultipleNotificationsProducesDistinctRows(): void
    {
        // Arrange
        $channel = new DatabaseChannel($this->db);
        $user    = new IntegrationNotifiable(10);

        // Act
        $channel->send($user, new IntegrationDatabaseNotification(['n' => 1]));
        $channel->send($user, new IntegrationDatabaseNotification(['n' => 2]));
        $channel->send($user, new IntegrationDatabaseNotification(['n' => 3]));

        // Assert — three rows with distinct UUIDs
        $rows = $this->db->query("SELECT id FROM notifications ORDER BY created_at ASC")->fetchAll();
        $ids  = array_column($rows, 'id');
        $this->assertCount(3, $ids, 'Three dispatches must produce three rows');
        $this->assertSame(3, count(array_unique($ids)), 'Each row must have a distinct UUID');
    }

    /**
     * When the notification has no toDatabase() method, nothing is written to
     * the table — the channel must silently skip.
     */
    public function testSendSkipsWhenNotificationHasNoToDatabaseMethod(): void
    {
        // Arrange
        $channel = new DatabaseChannel($this->db);
        $user    = new IntegrationNotifiable(1);
        $notif   = new NoDatabaseNotification();

        // Act
        $channel->send($user, $notif);

        // Assert — table remains empty
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM notifications");
        $this->assertSame(0, (int) $result->fields['cnt'],
            'DatabaseChannel must skip when notification has no toDatabase()');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function runNotificationsMigration(): void
    {
        $dir        = dirname(__DIR__, 3) . '/database/migrations/framework/notifications';
        $migrations = MigrationLoader::loadFromDirectory($dir, $this->app);
        foreach ($migrations as $m) {
            $m->up();
        }
    }

    protected function dropNotificationsTable(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `notifications`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// =============================================================================
// Stubs
// =============================================================================

/** Notifiable stub with a fixed primary key. */
class IntegrationNotifiable implements NotifiableInterface
{
    use NotifiableTrait;

    public string $email = '';

    public function __construct(public int $userid) {}
}

/** Notification with a toDatabase() implementation. */
class IntegrationDatabaseNotification implements NotificationInterface
{
    public function __construct(private array $data) {}

    public function via(mixed $notifiable): array         { return ['database']; }
    public function toDatabase(mixed $notifiable): array  { return $this->data; }
}

/** Notification without toDatabase(). */
class NoDatabaseNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['database']; }
}
