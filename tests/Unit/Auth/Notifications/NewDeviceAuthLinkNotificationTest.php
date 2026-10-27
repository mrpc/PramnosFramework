<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Notifications;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Notifications\NewDeviceAuthLinkNotification;

/**
 * `storedMailTemplate()` — the operator-overridable template, untouched by the tests this
 * class is otherwise reached through, which assert on `toMail()`'s compiled-in text instead.
 */
#[CoversClass(NewDeviceAuthLinkNotification::class)]
class NewDeviceAuthLinkNotificationTest extends TestCase
{
    public function testStoredMailTemplateCarriesTheUrlMinutesDeviceAndSiteName(): void
    {
        // Arrange
        $notification = new NewDeviceAuthLinkNotification(
            'https://example.test/login/authlink?token=abc',
            900,
            'Chrome on Windows',
            'Example Site'
        );

        // Act
        $template = $notification->storedMailTemplate();

        // Assert
        $this->assertSame('auth.new_device_link', $template['category']);
        $this->assertSame([
            'url'      => 'https://example.test/login/authlink?token=abc',
            'minutes'  => 15,
            'device'   => 'Chrome on Windows',
            'sitename' => 'Example Site',
        ], $template['vars']);
    }

    /** A TTL under a minute still rounds up to at least one, never zero. */
    public function testStoredMailTemplateRoundsAShortTtlUpToOneMinute(): void
    {
        // Arrange
        $notification = new NewDeviceAuthLinkNotification('https://example.test/x', 10, 'Chrome');

        // Act
        $template = $notification->storedMailTemplate();

        // Assert
        $this->assertSame(1, $template['vars']['minutes']);
    }
}
