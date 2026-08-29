<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Push\Subscriptions;

/**
 * The subscription table, against a real database.
 *
 * Three of the behaviours here cannot be asserted anywhere else, because they are not decisions
 * in PHP — they are what the schema does:
 *
 * - the unique index is what makes "this browser again" an update rather than a duplicate, and a
 *   duplicate means every notification is delivered to the same device twice;
 * - `failure_count + 1` is executed by the database, so an expression that is quoted rather than
 *   evaluated fails only here;
 * - and a 410 has to actually remove the row, not merely be classified as fatal.
 *
 * @see \Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable
 */
#[CoversClass(Subscriptions::class)]
#[CoversClass(\Pramnos\Application\Controllers\Push::class)]
#[CoversClass(\Pramnos\Notification\Channels\PushChannel::class)]
class SubscriptionsTest extends TestCase
{
    protected Database $db;

    protected Application $app;

    /** A different endpoint per test, so a leftover row can never make one pass. */
    private string $endpoint;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        // The framework's own tables live in the `pramnos` schema, which MySQL flattens into
        // a `pramnos_` name prefix — so the table to drop is not the one the class names.
        $this->db->query(
            'DROP TABLE IF EXISTS `'
            . $this->db->schema()->resolveTableName('pramnos.pushsubscriptions') . '`'
        );

        foreach (MigrationLoader::loadFromDirectory(
            dirname(__DIR__, 3) . '/database/migrations/framework/notifications',
            $this->app
        ) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable) {
                $migration->up();
            }
        }

        $this->endpoint = 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `'
            . $this->db->schema()->resolveTableName('pramnos.pushsubscriptions') . '`');
    }

    /**
     * A browser that subscribes is stored, once, and readable back.
     */
    public function testASubscriptionIsStoredAndFound(): void
    {
        // Act
        $stored = Subscriptions::store(101, $this->subscription(), 'Firefox/143.0');

        // Assert
        $this->assertTrue($stored);

        $rows = Subscriptions::forUser(101);
        $this->assertCount(1, $rows);
        $this->assertSame($this->endpoint, $rows[0]['endpoint']);
        $this->assertSame(hash('sha256', $this->endpoint), $rows[0]['endpoint_hash']);
        $this->assertSame('Firefox/143.0', $rows[0]['user_agent']);
        $this->assertSame(0, (int) $rows[0]['failure_count']);
    }

    /**
     * The same browser subscribing again is one row, not two.
     *
     * This is the normal shape of a subscribing page, not an edge case: `subscribe()` resolves
     * instantly when permission is already granted, so a page that calls it on load calls it on
     * *every* load. Stored naively that is a row per page view and a notification delivered to
     * the same laptop forty times.
     */
    public function testTheSameBrowserSubscribingAgainIsStillOneRow(): void
    {
        // Arrange
        Subscriptions::store(102, $this->subscription(), 'Chrome/141');

        // Act — the same endpoint, with keys the browser has since rotated
        $again = $this->subscription();
        $again['keys']['p256dh'] = 'BRotatedKeyRotatedKeyRotatedKey';

        Subscriptions::store(102, $again, 'Chrome/142');

        // Assert
        $rows = Subscriptions::forUser(102);
        $this->assertCount(1, $rows, 'one browser, one row');
        $this->assertSame('BRotatedKeyRotatedKeyRotatedKey', $rows[0]['p256dh'],
            'the rotated key has to replace the old one, or every send fails to decrypt');
        $this->assertSame('Chrome/142', $rows[0]['user_agent']);
    }

    /**
     * Two accounts on the same machine keep their own subscriptions.
     *
     * A shared computer, or the same person with two accounts. The endpoint may well be
     * identical; the notifications are not interchangeable.
     */
    public function testTwoAccountsOnOneBrowserAreTwoSubscriptions(): void
    {
        // Act
        Subscriptions::store(103, $this->subscription());
        Subscriptions::store(104, $this->subscription());

        // Assert
        $this->assertCount(1, Subscriptions::forUser(103));
        $this->assertCount(1, Subscriptions::forUser(104));
    }

    /**
     * A 410 removes the subscription.
     *
     * The push service saying the endpoint is gone is the only authoritative signal there is —
     * the browser cannot tell us it was uninstalled.
     */
    public function testGoneMeansDeleted(): void
    {
        // Arrange
        Subscriptions::store(105, $this->subscription());

        // Act
        $survived = Subscriptions::recordResult($this->endpoint, 410);

        // Assert
        $this->assertFalse($survived);
        $this->assertSame([], Subscriptions::forUser(105));
    }

    /**
     * And so does a 404, which is what some services answer instead.
     */
    public function testNotFoundAlsoMeansDeleted(): void
    {
        // Arrange
        Subscriptions::store(106, $this->subscription());

        // Act & Assert
        $this->assertFalse(Subscriptions::recordResult($this->endpoint, 404));
        $this->assertSame([], Subscriptions::forUser(106));
    }

    /**
     * A 429 keeps the subscription and counts one failure.
     *
     * The opposite decision from the one above, on the neighbouring status code. Deleting here
     * silently unsubscribes a live user during exactly the moment the service is under load —
     * and nobody reports it, because it looks like nothing happening.
     */
    public function testBusyMeansKeptAndCounted(): void
    {
        // Arrange
        Subscriptions::store(107, $this->subscription());

        // Act
        $survived = Subscriptions::recordResult($this->endpoint, 429);

        // Assert
        $this->assertTrue($survived);
        $rows = Subscriptions::forUser(107);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['failure_count'],
            'the increment is executed by the database, so it is only real here');
    }

    /**
     * A success clears the count, however bad the run before it was.
     *
     * Without this, a service that had a bad afternoon eventually reaches the failure ceiling and
     * unsubscribes every user it briefly could not reach.
     */
    public function testASuccessClearsTheFailureCount(): void
    {
        // Arrange
        Subscriptions::store(108, $this->subscription());

        for ($i = 0; $i < 5; $i++) {
            Subscriptions::recordResult($this->endpoint, 500);
        }

        $this->assertSame(5, (int) Subscriptions::forUser(108)[0]['failure_count']);

        // Act
        Subscriptions::recordResult($this->endpoint, 201);

        // Assert
        $rows = Subscriptions::forUser(108);
        $this->assertSame(0, (int) $rows[0]['failure_count']);
        $this->assertGreaterThan(0, (int) $rows[0]['last_success_at']);
    }

    /**
     * A subscription that has never once worked is eventually given up on.
     *
     * Ten consecutive failures with no 410 among them: the service has never said this endpoint
     * is gone, and has never accepted anything for it either. Kept for ever it is a full HTTPS
     * round trip added to every future send, permanently.
     */
    public function testASubscriptionThatNeverWorksIsEventuallyDropped(): void
    {
        // Arrange
        Subscriptions::store(109, $this->subscription());

        // Act
        for ($i = 1; $i < Subscriptions::MAX_FAILURES; $i++) {
            $this->assertTrue(
                Subscriptions::recordResult($this->endpoint, 500),
                'failure ' . $i . ' is not yet fatal'
            );
        }

        $survived = Subscriptions::recordResult($this->endpoint, 500);

        // Assert
        $this->assertFalse($survived);
        $this->assertSame([], Subscriptions::forUser(109));
    }

    /**
     * A person revoking a device removes that device only.
     */
    public function testForgettingOneEndpointLeavesTheOthers(): void
    {
        // Arrange
        Subscriptions::store(110, $this->subscription());

        $other = $this->subscription();
        $other['endpoint'] = 'https://updates.push.services.mozilla.com/wpush/v2/' . bin2hex(random_bytes(6));
        Subscriptions::store(110, $other);

        $this->assertCount(2, Subscriptions::forUser(110));

        // Act
        $this->assertTrue(Subscriptions::forget($this->endpoint, 110));

        // Assert
        $rows = Subscriptions::forUser(110);
        $this->assertCount(1, $rows);
        $this->assertSame($other['endpoint'], $rows[0]['endpoint']);
    }

    /**
     * One account cannot revoke another's device.
     *
     * The endpoint is the identifier a revoke request carries, and a request body is whatever the
     * sender put in it. Scoped to the account, a stolen endpoint revokes nothing.
     */
    public function testAnEndpointCannotBeRevokedByTheWrongAccount(): void
    {
        // Arrange
        Subscriptions::store(111, $this->subscription());

        // Act
        Subscriptions::forget($this->endpoint, 999);

        // Assert
        $this->assertCount(1, Subscriptions::forUser(111), 'still subscribed');
    }

    /**
     * The channel reads the same table the endpoint writes to.
     *
     * Two classes, two static calls, one table — and nothing else asserts that the notification
     * side and the subscribe side agree about which. If they ever disagree the symptom is a
     * person who subscribed successfully and is never notified, with no error anywhere.
     */
    public function testTheChannelAndTheEndpointShareOneTable(): void
    {
        // Arrange — stored the way the HTTP endpoint stores it
        $controller = new class extends \Pramnos\Application\Controllers\Push {
            public function __construct() {}

            public function probeStore(int $userId, array $subscription): bool
            {
                return $this->store($userId, $subscription, 'Chrome/141');
            }

            public function probeForget(string $endpoint, int $userId): void
            {
                $this->forget($endpoint, $userId);
            }
        };

        $this->assertTrue($controller->probeStore(113, $this->subscription()));

        // Act — read back the way the notification channel reads it
        $channel = new class extends \Pramnos\Notification\Channels\PushChannel {
            public function probeSubscriptions(int $userId): array
            {
                return $this->subscriptionsFor($userId);
            }

            public function probeRecord(string $endpoint, int $status): void
            {
                $this->record($endpoint, $status);
            }
        };

        // Assert
        $rows = $channel->probeSubscriptions(113);
        $this->assertCount(1, $rows);
        $this->assertSame($this->endpoint, $rows[0]['endpoint']);

        // And a 410 from the channel removes what the endpoint stored
        $channel->probeRecord($this->endpoint, 410);
        $this->assertSame([], $channel->probeSubscriptions(113));

        // Forgetting from the endpoint side is a no-op on an already-removed row, not an error
        $controller->probeForget($this->endpoint, 113);
        $this->assertSame([], Subscriptions::forUser(113));
    }

    /**
     * With the table gone, every method answers rather than throwing.
     *
     * Not a hypothetical: this is what a half-run migration looks like, and a push send is
     * usually one line inside something that mattered more — a password change, a sign-in. An
     * exception here would turn that into a 500 for a notification nobody would have missed.
     */
    public function testAMissingTableIsAnAnswerRatherThanAnException(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `'
            . $this->db->schema()->resolveTableName('pramnos.pushsubscriptions') . '`');

        // Act & Assert
        $this->assertFalse(Subscriptions::store(112, $this->subscription()));
        $this->assertSame([], Subscriptions::forUser(112));
        $this->assertFalse(Subscriptions::forget($this->endpoint, 112));
        $this->assertTrue(
            Subscriptions::recordResult($this->endpoint, 429),
            'a subscription that could not be updated is not thereby dead'
        );
    }

    /**
     * A 410 against a missing table still reports the subscription as gone.
     *
     * The push service has spoken; whether we managed to write the deletion down is a separate
     * problem, and reporting "still alive" would have the caller retry an endpoint that is not
     * coming back.
     */
    public function testGoneIsStillGoneEvenIfTheRowCannotBeRemoved(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `'
            . $this->db->schema()->resolveTableName('pramnos.pushsubscriptions') . '`');

        // Assert
        $this->assertFalse(Subscriptions::recordResult($this->endpoint, 410));
    }

    /** @return array<string, mixed> */
    private function subscription(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys'     => [
                'p256dh' => 'BExampleBrowserPublicKeyExampleBrowserPublicKey',
                'auth'   => 'ExampleAuthSecret',
            ],
        ];
    }
}
