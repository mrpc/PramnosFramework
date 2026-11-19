<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\TestCase;
use Pramnos\Html\NotificationBell;
use Pramnos\Http\RequestIdentity;

/**
 * The notification bell panel's optional link to the notification preferences page.
 *
 * An application that already declares `notifications.types`/`Preferences` (FW-488) has a
 * settings page, but nothing in the bell pointed at it — a person had to already know it
 * existed. `settingsUrl` adds a gear icon next to "mark all read"; without it (the default)
 * nothing changes, since many themes have no such page to link to.
 */
class NotificationBellTest extends TestCase
{
    protected function setUp(): void
    {
        // A signed-in id > 1 is what render() requires to draw anything at all.
        RequestIdentity::seal((object) ['userid' => 42], 'test');
    }

    protected function tearDown(): void
    {
        RequestIdentity::reset();
    }

    /** No `settingsUrl` — the default — draws no gear link, so no theme's markup changes underneath it. */
    public function testNoSettingsLinkByDefault(): void
    {
        // Act
        $html = NotificationBell::render();

        // Assert
        $this->assertStringNotContainsString('pf-notify-settings', $html);
    }

    /** `settingsUrl` draws a gear link, inside the head, pointed at that address, labelled by default. */
    public function testSettingsUrlDrawsALabelledGearLink(): void
    {
        // Act
        $html = NotificationBell::render(['settingsUrl' => '/account/notifications']);

        // Assert — the link exists, carries the data hook, the href, and a default label
        $this->assertStringContainsString('data-pf-notifications-settings', $html);
        $this->assertStringContainsString('href="/account/notifications"', $html);
        $this->assertStringContainsString('aria-label="Notification settings"', $html);
        // Assert — it sits inside .pf-notify-head, next to "mark all read", not elsewhere
        $headPos     = strpos($html, 'pf-notify-head');
        $readAllPos  = strpos($html, 'pf-notify-read-all');
        $settingsPos = strpos($html, 'pf-notify-settings');
        $this->assertLessThan($readAllPos, $headPos);
        $this->assertLessThan($settingsPos, $readAllPos);
    }

    /** A custom `settingsLabel` replaces the default wording in both the link's label and its text. */
    public function testCustomSettingsLabelIsUsed(): void
    {
        // Act
        $html = NotificationBell::render([
            'settingsUrl'   => '/account/notifications',
            'settingsLabel' => 'Ρυθμίσεις ειδοποιήσεων',
        ]);

        // Assert
        $this->assertStringContainsString('aria-label="Ρυθμίσεις ειδοποιήσεων"', $html);
        $this->assertStringNotContainsString('aria-label="Notification settings"', $html);
    }

    /** An empty `settingsUrl` is treated the same as not passing it — no link, and no empty `href`. */
    public function testEmptySettingsUrlDrawsNoLink(): void
    {
        // Act
        $html = NotificationBell::render(['settingsUrl' => '']);

        // Assert
        $this->assertStringNotContainsString('pf-notify-settings', $html);
    }
}
