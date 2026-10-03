<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Notification\Channels\PushChannel;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Push\Log;
use Pramnos\Push\Notifications\TestPushNotification;
use Pramnos\Push\Subscriptions;
use Pramnos\Push\TestPush;

/**
 * Test notifications, against the real tables: one per device, logged, and receipted.
 *
 * The push service is not called — the channel a test sends through records what a real send
 * would and answers 201 — because what is under test is everything around the send: one copy
 * per browser with its own receipt address, the push log row it leaves, and the receipt that
 * turns "accepted by the push service" into "arrived on the device".
 */
#[CoversClass(TestPush::class)]
#[CoversClass(TestPushNotification::class)]
class TestPushTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    private int $userId = 0;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        // Rebuilt from its migration every time, so a table left by an older shape cannot pass.
        $this->db->schema()->dropTableIfExists(TestPush::TABLE);

        foreach (MigrationLoader::loadFromDirectory(dirname(__DIR__, 3) . '/database/migrations/framework/notifications', $app) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable
                || $migration instanceof \Pramnos\Framework\Migrations\Notifications\CreatePushLogTable
                || $migration instanceof \Pramnos\Framework\Migrations\Notifications\CreatePushtestsTable
            ) {
                $migration->up();
            }
        }

        $this->userId = 800000 + random_int(1, 99999);
    }

    protected function tearDown(): void
    {
        foreach (['pramnos.pushsubscriptions', 'pramnos.pushlog', TestPush::TABLE] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('userid', $this->userId)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }
    }

    /** Subscribe a browser for the test account; its endpoint hash. */
    private function subscribe(string $agent): string
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(8));
        Subscriptions::store($this->userId, [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'BExampleBrowserPublicKeyExampleBrowserPublicKey', 'auth' => 'ExampleAuthSecret'],
        ], $agent);

        return hash('sha256', $endpoint);
    }

    /**
     * A sender whose channel logs what it would send and answers 201, keeping what it was given.
     */
    private function sender(): RecordingTestPush
    {
        return new RecordingTestPush();
    }

    /**
     * Each browser gets its own test, with its own receipt address, logged as a delivery.
     */
    public function testEachBrowserGetsItsOwnTest(): void
    {
        // Arrange
        $phone  = $this->subscribe('Chrome on Android');
        $laptop = $this->subscribe('Firefox on Linux');
        $sender = $this->sender();

        // Act
        $tests = $sender->send($this->userId);

        // Assert — two tests, two tokens, two receipt addresses
        $this->assertCount(2, $tests);
        $this->assertEqualsCanonicalizing([$phone, $laptop], array_column($tests, 'endpoint_hash'));
        $this->assertNotSame($tests[0]['token'], $tests[1]['token']);
        $this->assertSame('delivered', $tests[0]['outcome']['kind']);
        $this->assertSame(201, $tests[0]['status']);
        foreach ($sender->payloads as $i => $payload) {
            $this->assertStringContainsString('push/testack?token=' . $tests[$i]['token'], $payload['data']['ack']);
            $this->assertSame(TestPushNotification::TAG_PREFIX . $tests[$i]['token'], $payload['tag']);
        }

        // In the push log, findable by its tag
        $logged = Log::recent(1, ['tag' => TestPushNotification::TAG_PREFIX . $tests[0]['token']]);
        $this->assertCount(1, $logged);
        $this->assertSame($tests[0]['token'], TestPush::tokenOf($logged[0]));
    }

    /**
     * A hash limits the test to that browser; one the account does not have sends nothing.
     */
    public function testAHashLimitsTheTestToOneBrowser(): void
    {
        // Arrange
        $phone = $this->subscribe('Chrome on Android');
        $this->subscribe('Firefox on Linux');

        // Act & Assert
        $tests = $this->sender()->send($this->userId, $phone);
        $this->assertCount(1, $tests);
        $this->assertSame('Chrome on Android', $tests[0]['user_agent']);

        $this->assertSame([], $this->sender()->send($this->userId, str_repeat('f', 64)));
    }

    /**
     * Until the device answers, a test is waiting; its receipt records when it arrived, once.
     */
    public function testTheReceiptRecordsWhenTheDeviceGotIt(): void
    {
        // Arrange
        $this->subscribe('Chrome on Android');
        $token = $this->sender()->send($this->userId)[0]['token'];
        $push  = new TestPush();

        // Assert — waiting
        $waiting = $push->status($token);
        $this->assertNull($waiting['received_at']);
        $this->assertFalse($waiting['expired']);
        $this->assertSame($this->userId, $waiting['userid']);

        // Act — the service worker's receipt, twice
        $this->assertTrue($push->acknowledge($token));
        $first = $push->status($token)['received_at'];
        $this->assertTrue($push->acknowledge($token), 'a repeated receipt is still a yes');

        // Assert
        $this->assertGreaterThan(time() - 10, $first);
        $this->assertSame($first, $push->status($token)['received_at'], 'the first receipt stands');
    }

    /**
     * A receipt after the window, or for a token nobody issued, is refused.
     */
    public function testALateOrUnknownReceiptIsRefused(): void
    {
        // Arrange
        $this->subscribe('Chrome on Android');
        $token = $this->sender()->send($this->userId)[0]['token'];
        $this->db->queryBuilder()->table(TestPush::TABLE)->where('token_hash', hash('sha256', $token))->update(['expires_at' => time() - 1]);
        $push = new TestPush();

        // Act & Assert
        $this->assertFalse($push->acknowledge($token));
        $this->assertTrue($push->status($token)['expired']);
        $this->assertFalse($push->acknowledge(str_repeat('0', 32)));
        $this->assertFalse($push->acknowledge('not a token'));
        $this->assertNull($push->status('not a token'));

        // The token is not stored, only its hash: a dump of the table cannot be replayed
        $stored = $this->db->queryBuilder()->table(TestPush::TABLE)->where('userid', $this->userId)->first()->fields;
        $this->assertArrayNotHasKey('token', $stored);
        $this->assertSame(hash('sha256', $token), $stored['token_hash']);
    }

    /**
     * An ordinary push log row is not a test.
     */
    public function testAnOrdinaryRowIsNotATest(): void
    {
        // Act & Assert
        $this->assertNull(TestPush::tokenOf(['tag' => 'signin-approval']));
        $this->assertNull(TestPush::tokenOf([]));
    }
}

/**
 * A TestPush whose channel records instead of sending.
 */
class RecordingTestPush extends TestPush
{
    /** @var list<array<string, mixed>> What each test would have pushed */
    public array $payloads = [];

    /**
     * A channel that logs a 201 for the one subscription, as a delivery would.
     *
     * @param array<string, mixed> $subscription
     */
    protected function channelFor(array $subscription): PushChannel
    {
        $owner = $this;

        return new class ($subscription, $owner) extends PushChannel {
            /** @param array<string, mixed> $only */
            public function __construct(private array $only, private RecordingTestPush $owner)
            {
            }

            /** Log what would have been sent, as delivered. */
            public function send(mixed $notifiable, NotificationInterface $notification): void
            {
                $payload = $notification->toPush($notifiable);
                $this->owner->payloads[] = $payload;
                Log::record((int) $notifiable->userid, (string) $this->only['endpoint_hash'], $payload, 201, $notification::class);
            }
        };
    }
}
