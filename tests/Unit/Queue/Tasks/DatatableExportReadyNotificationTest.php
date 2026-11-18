<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue\Tasks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\Tasks\DatatableExportReadyNotification;

/**
 * Unit tests for Pramnos\Queue\Tasks\DatatableExportReadyNotification.
 *
 * `via()`'s push branch needs a real `pramnos.pushsubscriptions` row to prove
 * (`Subscriptions::exist()` queries the database) — covered end to end by
 * {@see \Pramnos\Tests\Integration\Queue\DatatableExportTaskMySQLTest}'s own
 * mail-delivery test instead; a userid of 0 here exercises the mail-only
 * branch with no database at all, since `exist()` short-circuits on it.
 */
#[CoversClass(DatatableExportReadyNotification::class)]
class DatatableExportReadyNotificationTest extends TestCase
{
    /** No account id (a sealed identity with nothing to check) means mail only — never push. */
    public function testViaIsMailOnlyWithNoUserId(): void
    {
        // Arrange
        $notification = new DatatableExportReadyNotification('12 rows exported', '/export/download/9');

        // Act
        $channels = $notification->via((object) ['userid' => 0, 'email' => 'x@example.com']);

        // Assert
        $this->assertSame(['mail'], $channels);
    }

    /** The mail body carries the message and a working link to the download. */
    public function testToMailCarriesTheMessageAndTheDownloadLink(): void
    {
        // Arrange
        $notification = new DatatableExportReadyNotification('12 rows exported', '/export/download/9');

        // Act
        $mail = $notification->toMail((object) ['userid' => 0, 'email' => 'x@example.com']);

        // Assert
        $this->assertStringContainsString('12 rows exported', $mail['body']);
        $this->assertStringContainsString('/export/download/9', $mail['body']);
    }

    /** The push payload carries the message, the link, and a stable tag (one notice replaces the last). */
    public function testToPushCarriesTheMessageAndLink(): void
    {
        // Arrange
        $notification = new DatatableExportReadyNotification('12 rows exported', '/export/download/9');

        // Act
        $push = $notification->toPush((object) ['userid' => 7]);

        // Assert
        $this->assertSame('12 rows exported', $push['body']);
        $this->assertSame('/export/download/9', $push['url']);
        $this->assertSame('datatable-export', $push['tag']);
    }

    /**
     * `queueable()` — composed in this request, handed to the outbox, so one
     * slow SMTP round trip never holds up the worker that just finished an
     * export. The same reasoning as the raw `Email::queue()` call this replaced.
     */
    public function testQueueableIsTrue(): void
    {
        // Arrange
        $notification = new DatatableExportReadyNotification('12 rows exported', '/export/download/9');

        // Act + Assert
        $this->assertTrue($notification->queueable());
    }
}
