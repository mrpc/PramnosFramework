<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification\Channels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Notification\Channels\BroadcastChannel;
use Pramnos\Notification\NotificationInterface;

/**
 * Unit tests for BroadcastChannel.
 *
 * The BroadcastingManager is injected so no real broadcast driver is required.
 * Tests verify channel/event/payload routing and the skip condition.
 */
#[CoversClass(BroadcastChannel::class)]
class BroadcastChannelTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/pramnos_broadcast_channel_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * BroadcastChannel must call BroadcastingManager::broadcast() with the
     * channel, event, and payload from toBroadcast().
     */
    public function testSendCallsBroadcastWithCorrectArguments(): void
    {
        // Arrange — use a real manager with a LogDriver so calls are recorded
        $manager  = new BroadcastingManager();
        $driver   = new \Pramnos\Broadcasting\Drivers\LogDriver($this->logFile);
        $manager->addDriver($driver);
        $manager->setDefault($driver->name());

        $channel = new BroadcastChannel($manager);
        $notif   = new BroadcastNotification('users.42', 'invoice.paid', ['amount' => 150]);
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert — the log driver must have recorded one entry
        $entries = $driver->getEntries();
        $this->assertCount(1, $entries, 'BroadcastChannel must call broadcast() once');
        $this->assertSame('users.42',     $entries[0]['channel']);
        $this->assertSame('invoice.paid', $entries[0]['event']);
        $this->assertSame(['amount' => 150], $entries[0]['payload']);
    }

    /**
     * When the toBroadcast() data omits 'channel' and 'event' keys, BroadcastChannel
     * must fall back to the defaults: channel='notifications', event='notification.created'.
     */
    public function testSendUsesDefaultChannelAndEventWhenOmitted(): void
    {
        // Arrange
        $manager = new BroadcastingManager();
        $driver  = new \Pramnos\Broadcasting\Drivers\LogDriver($this->logFile);
        $manager->addDriver($driver);
        $manager->setDefault($driver->name());

        $channel = new BroadcastChannel($manager);
        $notif   = new MinimalBroadcastNotification(['msg' => 'hello']);
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert
        $entries = $driver->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('notifications',         $entries[0]['channel'],
            "Missing 'channel' key must default to 'notifications'");
        $this->assertSame('notification.created',  $entries[0]['event'],
            "Missing 'event' key must default to 'notification.created'");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Skip condition
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the notification does NOT implement toBroadcast(), no broadcast
     * must be emitted — the channel silently skips.
     */
    public function testSendSkipsWhenNotificationHasNoToBroadcastMethod(): void
    {
        // Arrange
        $manager = new BroadcastingManager();
        $driver  = new \Pramnos\Broadcasting\Drivers\LogDriver($this->logFile);
        $manager->addDriver($driver);
        $manager->setDefault($driver->name());

        $channel = new BroadcastChannel($manager);
        $notif   = new NoBroadcastNotification();
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert
        $this->assertEmpty($driver->getEntries(),
            'BroadcastChannel must skip when notification has no toBroadcast()');
    }
}

// =============================================================================
// Stubs
// =============================================================================

/** Notification with explicit channel, event, and payload. */
class BroadcastNotification implements NotificationInterface
{
    public function __construct(
        private string $channel,
        private string $event,
        private array $payload
    ) {}

    public function via(mixed $notifiable): array { return ['broadcast']; }

    public function toBroadcast(mixed $notifiable): array
    {
        return [
            'channel' => $this->channel,
            'event'   => $this->event,
            'payload' => $this->payload,
        ];
    }
}

/** Notification returning only a payload — no channel/event keys. */
class MinimalBroadcastNotification implements NotificationInterface
{
    public function __construct(private array $data) {}

    public function via(mixed $notifiable): array          { return ['broadcast']; }
    public function toBroadcast(mixed $notifiable): array  { return $this->data; }
}

/** Notification without a toBroadcast() method. */
class NoBroadcastNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['broadcast']; }
}
