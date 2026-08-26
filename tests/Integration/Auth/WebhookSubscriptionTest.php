<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\WebhookService;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The webhook subscription and queue, against a real database.
 *
 * WHAT: that an application can subscribe to every documented event type, and
 *       that an event with no user attached can be queued.
 * WHY:  both were impossible, and both failed in ways nothing surfaced.
 *
 *       `permissions_changed` — the event the whole instant-invalidation design
 *       rests on — was absent from the `webhook_type` CHECK constraint, so no
 *       endpoint could be registered for it. `queueEvent()` looks up endpoints by
 *       type before inserting anything, so the event was dropped with nowhere to
 *       send it. The documented mechanism could not be switched on at all.
 *
 *       And `user_id` on the events table is nullable with a foreign key, while
 *       `queueEvent()` declared the parameter `int`. There was no way to queue an
 *       event that is not about a particular person: `null` would not type-check
 *       and `0` violates the key.
 *
 * These have to be integration tests. A unit test cannot see a CHECK constraint,
 * and a CHECK constraint was the bug.
 */
#[CoversClass(WebhookService::class)]
class WebhookSubscriptionTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;

    /** Physical table names (the applications schema maps to a prefix on MySQL). */
    private string $endpoints;
    private string $events;

    /** The application row these endpoints belong to. */
    private int $appId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL only; the QueryBuilder abstracts the driver.');
        }

        $prefix          = $this->db->prefix;
        $this->endpoints = $prefix . 'applications_oauth2_webhook_endpoints';
        $this->events    = $prefix . 'applications_oauth2_webhook_events';

        // A minimal pair of tables carrying the parts under test: the type
        // constraint, and a nullable user_id. Built by hand rather than by the
        // migration because the real one also wants the applications and users
        // tables and a PL/pgSQL function this engine has no use for.
        $this->db->query("DROP TABLE IF EXISTS `{$this->events}`");
        $this->db->query("DROP TABLE IF EXISTS `{$this->endpoints}`");

        $types = "'user_deauthorized','token_revoked','gdpr_request',"
            . "'user_profile_changed','device_deauthorized','account_deleted',"
            . "'scope_changed','permissions_changed'";

        $this->db->query(
            "CREATE TABLE `{$this->endpoints}` (
                `webhook_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `appid`           INT NOT NULL,
                `endpoint_url`    VARCHAR(512) NOT NULL,
                `webhook_type`    VARCHAR(50) NOT NULL CHECK (`webhook_type` IN ({$types})),
                `secret_key`      VARCHAR(255) NOT NULL,
                `is_active`       TINYINT NOT NULL DEFAULT 1,
                `retry_count`     INT NOT NULL DEFAULT 3,
                `timeout_seconds` INT NOT NULL DEFAULT 30,
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_app_type` (`appid`, `webhook_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE `{$this->events}` (
                `event_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `webhook_id`      INT NOT NULL,
                `event_type`      VARCHAR(50) NOT NULL,
                `user_id`         INT NULL,
                `device_code`     VARCHAR(128) NULL,
                `token_id`        INT NULL,
                `payload`         TEXT NOT NULL,
                `status`          VARCHAR(20) NOT NULL DEFAULT 'pending',
                `attempts`        INT NOT NULL DEFAULT 0,
                `max_attempts`    INT NOT NULL DEFAULT 3,
                `next_attempt_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_error`      TEXT NULL,
                `sent_at`         TIMESTAMP NULL,
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->appId = 4242;
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `{$this->events}`");
        $this->db->query("DROP TABLE IF EXISTS `{$this->endpoints}`");
    }

    /** Register one endpoint and return its id. */
    private function subscribe(string $type): int
    {
        $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_endpoints')
            ->insert([
                'appid'        => $this->appId,
                'endpoint_url' => 'https://app.example.com/hooks/' . $type,
                'webhook_type' => $type,
                'secret_key'   => bin2hex(random_bytes(32)),
                'is_active'    => 1,
            ]);

        $row = $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_endpoints')
            ->select(['webhook_id'])
            ->where('appid', $this->appId)
            ->where('webhook_type', $type)
            ->first();

        return (int) $row->fields['webhook_id'];
    }

    /**
     * `permissions_changed` can be subscribed to.
     *
     * The event the integration guide's whole invalidation story rests on. Before
     * the constraint was widened this insert was refused, so no endpoint existed,
     * so `queueEvent()` found nothing to send to and dropped the event.
     */
    public function testPermissionsChangedCanBeSubscribedTo(): void
    {
        // Act
        $webhookId = $this->subscribe('permissions_changed');

        // Assert
        $this->assertGreaterThan(0, $webhookId, 'the endpoint must have been stored');
    }

    /**
     * Every documented event type can be subscribed to.
     *
     * One assertion per type, because the constraint is a list and a list is
     * exactly the kind of thing that goes one entry out of date.
     *
     * @param string $type A documented event type
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('documentedEventTypes')]
    public function testEveryDocumentedTypeCanBeSubscribedTo(string $type): void
    {
        // Act
        $webhookId = $this->subscribe($type);

        // Assert
        $this->assertGreaterThan(0, $webhookId, $type . ' must be an allowed webhook_type');
    }

    /** @return array<string, array{0: string}> */
    public static function documentedEventTypes(): array
    {
        $types = [
            'user_deauthorized', 'token_revoked', 'gdpr_request',
            'user_profile_changed', 'device_deauthorized', 'account_deleted',
            'scope_changed', 'permissions_changed',
        ];

        return array_combine($types, array_map(static fn (string $t): array => [$t], $types));
    }

    /**
     * An event with no user attached is queued.
     *
     * A test ping, or anything describing the application rather than a person.
     * `user_id` is nullable in the schema and the parameter used to be `int`, so
     * there was no way to express this: `null` would not type-check, and `0`
     * violates the foreign key the real table carries.
     */
    public function testAnEventWithNoUserIsQueued(): void
    {
        // Arrange
        $webhookId = $this->subscribe('token_revoked');
        $service   = new WebhookService($this->db);

        // Act
        $queued = $service->queueEvent('token_revoked', null, ['test' => true]);

        // Assert
        $this->assertSame(1, $queued, 'one row per subscribed endpoint');

        $row = $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_events')
            ->select(['user_id', 'status', 'webhook_id'])
            ->where('webhook_id', $webhookId)
            ->first();

        $this->assertSame(1, $row->numRows);
        $this->assertNull($row->fields['user_id'], 'no user means NULL, not zero');
        $this->assertSame('pending', $row->fields['status']);
    }

    /**
     * An event for a user carries that user's id.
     *
     * The other half of the previous test: widening the parameter must not have
     * turned the common case into a null.
     */
    public function testAnEventForAUserCarriesTheUserId(): void
    {
        // Arrange
        $this->subscribe('gdpr_request');
        $service = new WebhookService($this->db);

        // Act
        $service->queueEvent('gdpr_request', 99, ['request' => 'erasure']);

        // Assert
        $row = $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_events')
            ->select(['user_id'])
            ->where('event_type', 'gdpr_request')
            ->first();

        $this->assertSame(99, (int) $row->fields['user_id']);
    }

    /**
     * An event nobody subscribed to is not queued.
     *
     * One row per *subscribed* endpoint, so an event type with no endpoint is a
     * no-op rather than an orphan row that can never be delivered.
     */
    public function testAnEventWithNoSubscriberIsNotQueued(): void
    {
        // Arrange — a subscription to something else
        $this->subscribe('token_revoked');
        $service = new WebhookService($this->db);

        // Act
        $queued = $service->queueEvent('account_deleted', 5, []);

        // Assert
        $this->assertSame(0, $queued);
        $this->assertSame(
            0,
            $this->db->queryBuilder()
                ->table('applications.oauth2_webhook_events')
                ->where('event_type', 'account_deleted')
                ->count()
        );
    }

    /**
     * An inactive endpoint receives nothing.
     *
     * Deactivating is how an application pauses delivery without losing its
     * secret; an event queued for a paused endpoint would arrive the moment it
     * came back, which is not what pausing means.
     */
    public function testAnInactiveEndpointReceivesNothing(): void
    {
        // Arrange
        $webhookId = $this->subscribe('token_revoked');
        $this->db->queryBuilder()
            ->table('applications.oauth2_webhook_endpoints')
            ->where('webhook_id', $webhookId)
            ->update(['is_active' => 0]);

        $service = new WebhookService($this->db);

        // Act
        $queued = $service->queueEvent('token_revoked', 5, []);

        // Assert
        $this->assertSame(0, $queued);
    }
}
