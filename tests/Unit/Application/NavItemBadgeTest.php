<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavSection;

/**
 * A number beside a navigation label, and what it must never do.
 *
 * `MessagesController::unreadCount()` had existed since the inbox screen shipped and had exactly
 * one caller — the inbox itself, which is the one screen where the number is redundant. Somebody
 * with an unread message had no way of knowing unless they went looking, which for a message
 * somebody sent them is the whole problem.
 *
 * The badge is decoration on a page that is about something else, so every failure mode here
 * ends in "show nothing" rather than in an exception: a navigation item that throws takes every
 * page on the site with it.
 */
#[CoversClass(NavItem::class)]
class NavItemBadgeTest extends TestCase
{
    /**
     * An item with no badge shows nothing, which is almost every item.
     */
    public function testAnItemWithoutABadgeCountsZero(): void
    {
        // Arrange
        $item = new NavItem('user.profile', 'Profile', '/profile', NavSection::User);

        // Act & Assert
        $this->assertSame(0, $item->badgeCount(42));
    }

    /**
     * A signed-out visitor has no count, whatever the closure would say.
     *
     * The navigation is rendered for everybody, and a badge resolved for user 0 is a query
     * against an account that does not exist — on every page, for every crawler.
     */
    public function testASignedOutVisitorHasNoBadge(): void
    {
        // Arrange
        $called = 0;
        $item = $this->item(function (int $userId) use (&$called): int {
            $called++;

            return 7;
        });

        // Act & Assert
        $this->assertSame(0, $item->badgeCount(0));
        $this->assertSame(0, $called, 'and nothing was asked');
    }

    /**
     * The count is resolved when the page renders, not when the item is registered.
     *
     * Navigation is registered once at boot. A number resolved there is the count as it was when
     * the process started — for an unread badge, always wrong and usually zero.
     */
    public function testTheCountIsResolvedAtRenderTime(): void
    {
        // Arrange
        $unread = 0;
        $item = $this->item(static function (int $userId) use (&$unread): int {
            return $unread;
        });

        // Act
        $unread = 3;

        // Assert
        $this->assertSame(3, $item->badgeCount(101));
    }

    /**
     * Asked twice in a page, resolved once.
     *
     * A theme renders the navigation more than once — a header and a mobile menu are two renders
     * of the same list — and a badge is not worth two queries.
     */
    public function testTheCountIsResolvedOncePerAccount(): void
    {
        // Arrange
        $calls = 0;
        $item = $this->item(function (int $userId) use (&$calls): int {
            $calls++;

            return 4;
        }, 'user.memo');

        // Act
        $item->badgeCount(202);
        $item->badgeCount(202);
        $item->badgeCount(202);

        // Assert
        $this->assertSame(1, $calls);
    }

    /**
     * A closure that throws shows nothing rather than taking the page down.
     *
     * The database is unreachable, or the table has not been migrated. A count is decoration on
     * a screen about something else; a navigation item that throws is every page on the site.
     */
    public function testAFailingCountShowsNothing(): void
    {
        // Arrange
        $item = $this->item(static function (int $userId): int {
            throw new \RuntimeException('no database');
        }, 'user.throws');

        // Act & Assert
        $this->assertSame(0, $item->badgeCount(303));
    }

    /**
     * A negative answer is not a badge.
     *
     * Nothing should return one, and «-1 unread» is worse than no badge — it reads as a broken
     * page rather than as a broken count.
     */
    public function testANegativeCountIsClampedToZero(): void
    {
        // Arrange
        $item = $this->item(static fn (int $userId): int => -5, 'user.negative');

        // Act & Assert
        $this->assertSame(0, $item->badgeCount(404));
    }

    /**
     * Over ninety-nine is written `99+`.
     *
     * The difference between a hundred unread and four hundred is not one anybody acts on, and a
     * four-digit badge is wider than the label it sits beside.
     */
    public function testALargeCountIsAbbreviated(): void
    {
        // Arrange
        $item = $this->item(static fn (int $userId): int => 412, 'user.many');

        // Act & Assert
        $this->assertSame('99+', $item->badgeLabel(505));
        $this->assertSame(412, $item->badgeCount(505), 'the number itself is not lost');
    }

    /**
     * Under a hundred is written as itself.
     */
    public function testASmallCountIsWrittenAsItself(): void
    {
        // Arrange
        $item = $this->item(static fn (int $userId): int => 7, 'user.few');

        // Act & Assert
        $this->assertSame('7', $item->badgeLabel(606));
    }

    /**
     * The messages item registers one, or none of this is reached.
     *
     * The recurring failure in this codebase: a capability that works and nothing uses. A badge
     * nothing registers is a feature only its tests have seen.
     */
    public function testTheMessagesItemRegistersABadge(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Assert
        $this->assertMatchesRegularExpression(
            "~'user\.messages'.*?badge:~s",
            $source,
            'the inbox item must carry the unread count, or nothing shows it'
        );
        $this->assertStringContainsString('MessagesController::unreadCount', $source);
    }

    /**
     * And every theme draws it.
     *
     * A badge in one theme and not the other two is a feature that exists depending on which
     * theme somebody scaffolded with.
     */
    public function testEveryThemeDrawsTheBadge(): void
    {
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            // Act
            $header = (string) file_get_contents(
                dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/header.php'
            );

            // Assert
            $this->assertStringContainsString('badgeCount(', $header, $theme);
            $this->assertStringContainsString('badgeLabel(', $header, $theme);
            $this->assertStringContainsString('aria-label', $header,
                $theme . ': a number on its own is announced as a number');
        }
    }

    private function item(\Closure $badge, string $id = 'user.messages'): NavItem
    {
        return new NavItem(
            $id,
            'Messages',
            '/messages',
            NavSection::User,
            requireAuth: true,
            badge: $badge
        );
    }
}
