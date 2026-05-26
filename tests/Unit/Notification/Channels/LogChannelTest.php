<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification\Channels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\Channels\LogChannel;
use Pramnos\Notification\NotificationInterface;

/**
 * Unit tests for LogChannel.
 *
 * A temporary file is used instead of the default LOGS path so tests are
 * fully isolated from the runtime environment and clean up after themselves.
 */
#[CoversClass(LogChannel::class)]
class LogChannelTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/pramnos_log_channel_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Writing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LogChannel must append a JSON line to the log file on every send() call.
     *
     * Two dispatches → two lines in the file. Each line must be valid JSON
     * containing the notification class name and notifiable class name.
     */
    public function testSendAppendsJsonLineToLogFile(): void
    {
        // Arrange
        $channel = new LogChannel($this->logFile);
        $notif   = new LogNotification();
        $user    = new \stdClass();

        // Act — send twice
        $channel->send($user, $notif);
        $channel->send($user, $notif);

        // Assert — two lines, each valid JSON
        $lines = array_filter(explode("\n", file_get_contents($this->logFile)));
        $this->assertCount(2, $lines, 'Two send() calls must produce exactly two log lines');

        $parsed = json_decode(reset($lines), true);
        $this->assertIsArray($parsed, 'Each log line must be valid JSON');
        $this->assertArrayHasKey('time',         $parsed, 'Log line must contain "time"');
        $this->assertArrayHasKey('notification', $parsed, 'Log line must contain "notification"');
        $this->assertArrayHasKey('notifiable',   $parsed, 'Log line must contain "notifiable"');
    }

    /**
     * The log line's "notification" key must equal the full class name of the
     * notification that was dispatched.
     */
    public function testSendLogLineContainsNotificationClassName(): void
    {
        // Arrange
        $channel = new LogChannel($this->logFile);
        $notif   = new LogNotification();
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert
        $parsed = json_decode(file_get_contents($this->logFile), true);
        $this->assertSame(LogNotification::class, $parsed['notification'],
            "'notification' field must be the FQCN of the notification class");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data extraction — toLog() vs toDatabase() fallback
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the notification implements toLog(), its return value must be used
     * as the "data" field in the log line.
     */
    public function testSendUsesToLogMethodWhenAvailable(): void
    {
        // Arrange
        $channel = new LogChannel($this->logFile);
        $notif   = new NotificationWithToLog(['custom_key' => 'custom_value']);
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert
        $parsed = json_decode(file_get_contents($this->logFile), true);
        $this->assertSame(['custom_key' => 'custom_value'], $parsed['data'],
            'toLog() return value must appear verbatim as the data field');
    }

    /**
     * When the notification has no toLog() but has toDatabase(), the
     * toDatabase() output must be used as the "data" field.
     */
    public function testSendFallsBackToDatabaseMethodWhenNoToLog(): void
    {
        // Arrange
        $channel = new LogChannel($this->logFile);
        $notif   = new NotificationWithDatabase(['db_key' => 'db_value']);
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert
        $parsed = json_decode(file_get_contents($this->logFile), true);
        $this->assertSame(['db_key' => 'db_value'], $parsed['data'],
            'toDatabase() result must be the fallback for the data field');
    }

    /**
     * When neither toLog() nor toDatabase() exist, the "data" field must be
     * an empty JSON object — never null or an error.
     */
    public function testSendUsesEmptyObjectWhenNoDataMethod(): void
    {
        // Arrange
        $channel = new LogChannel($this->logFile);
        $notif   = new BareNotification();
        $user    = new \stdClass();

        // Act
        $channel->send($user, $notif);

        // Assert
        $parsed = json_decode(file_get_contents($this->logFile), true);
        $this->assertSame([], $parsed['data'],
            'data field must be an empty object when no toLog()/toDatabase() method exists');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getLogPath()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getLogPath() must return the path that was passed to the constructor.
     */
    public function testGetLogPathReturnsConstructorPath(): void
    {
        // Arrange + Act
        $channel = new LogChannel('/tmp/test.log');

        // Assert
        $this->assertSame('/tmp/test.log', $channel->getLogPath());
    }
}

// =============================================================================
// Stubs
// =============================================================================

/** Plain notification — no data methods. */
class LogNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['log']; }
}

/** Notification with toLog(). */
class NotificationWithToLog implements NotificationInterface
{
    public function __construct(private array $data) {}

    public function via(mixed $notifiable): array    { return ['log']; }
    public function toLog(mixed $notifiable): mixed  { return $this->data; }
}

/** Notification with toDatabase() but no toLog(). */
class NotificationWithDatabase implements NotificationInterface
{
    public function __construct(private array $data) {}

    public function via(mixed $notifiable): array           { return ['log']; }
    public function toDatabase(mixed $notifiable): array    { return $this->data; }
}

/** Notification with neither toLog() nor toDatabase(). */
class BareNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['log']; }
}
