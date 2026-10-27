<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Notifications;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Notifications\SecondFactorCodeNotification;

/**
 * `storedMailTemplate()` — the operator-overridable template, untouched by the tests this
 * class is otherwise reached through, which assert on `toMail()`'s compiled-in text instead.
 */
#[CoversClass(SecondFactorCodeNotification::class)]
class SecondFactorCodeNotificationTest extends TestCase
{
    public function testStoredMailTemplateCarriesTheCodeMinutesAndSiteName(): void
    {
        // Arrange
        $notification = new SecondFactorCodeNotification('123456', 600, 'Example Site');

        // Act
        $template = $notification->storedMailTemplate();

        // Assert
        $this->assertSame('auth.twofactor_code', $template['category']);
        $this->assertSame([
            'code'     => '123456',
            'minutes'  => 10,
            'sitename' => 'Example Site',
        ], $template['vars']);
    }

    /** A TTL under a minute still rounds up to at least one, never zero. */
    public function testStoredMailTemplateRoundsAShortTtlUpToOneMinute(): void
    {
        // Arrange
        $notification = new SecondFactorCodeNotification('123456', 10, 'Example Site');

        // Act
        $template = $notification->storedMailTemplate();

        // Assert
        $this->assertSame(1, $template['vars']['minutes']);
    }
}
