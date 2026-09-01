<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\DevPanelController;

/**
 * Where «Back» goes — 15 uncovered statements, and an open redirect if it is wrong.
 *
 * The panel is opened from the screen you are debugging, usually deep in the administration area and
 * often with a filter in the query string. Back used to go to the site root, which is almost never
 * where anybody came from: you read one number, press Back, and the way back to what you were doing
 * is gone.
 *
 * So the referrer is remembered — and the moment a referrer becomes a link on a page, it stops being
 * a convenience and becomes a security decision. A recorded foreign URL would be rendered into an
 * anchor on a page an administrator is about to click, with the panel's own appearance vouching for
 * it. That is an open redirect with a trust badge on it.
 *
 * Three properties, each of which was a bug in some earlier version of this idea:
 *
 * - **only a URL on this site is kept**, checked on the way in *and* again on the way out. The second
 *   check is not redundant: the session outlives the request that wrote it, so a value that stopped
 *   being ours — a changed site URL, a session restored from elsewhere — must not become a link.
 * - **the panel's own pages are not «somewhere you came from».** The tabs across the top are
 *   same-panel navigation, so recording them would replace the original screen with the last tab
 *   visited. Adminer counts as the panel too — it is one of the tabs now, and recording it sent Back
 *   from the panel *into* Adminer, the two bouncing off each other with the real origin lost.
 * - **it is escaped.** The value goes straight into an `href`, and it arrived in a header.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelReturnUrlTest extends TestCase
{
    /**
     * The site's own base, taken from `sURL` rather than written down.
     *
     * The method under test derives it from `sURL`, so a literal here tests whether this file and
     * the bootstrap happen to agree — which they did not: `sURL` is not localhost, and four
     * assertions were really checking that a *non-matching* referrer is refused.
     */
    private string $base = '';

    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->base = defined('sURL') ? rtrim((string) sURL, '/') : '';
        $this->assertNotSame('', $this->base, 'sURL is undefined, so nothing here can be on-site');

        $this->savedSession = $_SESSION ?? [];
        $_SESSION = [];
        unset($_SERVER['HTTP_REFERER']);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        unset($_SERVER['HTTP_REFERER']);
    }

    /** Arrive with a referrer and ask where Back should go. */
    private function backFrom(?string $referrer, string $mount = 'devpanel'): string
    {
        if ($referrer === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $referrer;
        }

        return DevPanelController::returnUrlFor($mount);
    }

    /** What the panel's own Back button computes, through the instance method. */
    private function remembered(?string $referrer, string $mount = 'devpanel'): string
    {
        if ($referrer === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $referrer;
        }

        $controller = (new \ReflectionClass(DevPanelController::class))->newInstanceWithoutConstructor();

        return (string) (new \ReflectionMethod(DevPanelController::class, 'rememberedReturnUrl'))
            ->invoke($controller, $this->base, $mount);
    }

    // ── Remembering where you came from ───────────────────────────────────────

    /**
     * A screen on this site is remembered, and Back goes there.
     *
     * The whole point: the query string comes with it, because a filtered listing is the screen the
     * person was actually looking at and dropping the filter loses the thing they were debugging.
     */
    public function testAScreenOnThisSiteIsRememberedWithItsQueryString(): void
    {
        // Act
        $back = $this->backFrom($this->base . '/admin/Users?usertype=80&page=3');

        // Assert
        $this->assertStringContainsString('/admin/Users', $back);
        $this->assertStringContainsString('usertype=80', $back, 'the filter was dropped');
        $this->assertStringContainsString('page=3', $back);
    }

    /**
     * It survives moving between the panel's tabs.
     *
     * Recorded once, on the way in. The tabs are same-panel navigation, so a visitor who opens the
     * panel from a screen and then looks at Database, Cache and Users still returns to the screen —
     * which is the behaviour that makes the button worth pressing at all.
     */
    public function testItSurvivesMovingBetweenTabs(): void
    {
        // Arrange — arriving from a real screen
        $this->backFrom($this->base . '/admin/Roles/edit?id=4');

        // Act — then three tabs of the panel itself
        foreach (['db', 'cache', 'users'] as $tab) {
            $this->backFrom($this->base . '/devpanel/' . $tab);
        }
        $back = $this->backFrom($this->base . '/devpanel/mcp');

        // Assert
        $this->assertStringContainsString(
            '/admin/Roles/edit',
            $back,
            'a tab replaced the screen the visitor actually came from'
        );
    }

    /**
     * Adminer counts as the panel, not as somewhere you came from.
     *
     * It is one of the tabs. Recording it sent Back from the panel *into* Adminer and from Adminer
     * back into the panel — the two bouncing off each other, with the screen the person came from
     * lost between them.
     */
    public function testAdminerIsNotSomewhereYouCameFrom(): void
    {
        // Arrange
        $this->backFrom($this->base . '/admin/Logs/viewer');

        // Act
        $back = $this->backFrom($this->base . '/adminer?db=pramnos&select=users');

        // Assert
        $this->assertStringContainsString('/admin/Logs/viewer', $back, 'Adminer replaced the origin');
        $this->assertStringNotContainsString('adminer', $back);
    }

    // ── What is refused ───────────────────────────────────────────────────────

    /**
     * A foreign referrer is never remembered.
     *
     * The security property. A recorded foreign URL is rendered into an anchor on a page an
     * administrator is about to click, with the panel's appearance vouching for it — an open
     * redirect with a trust badge on it. Any page anywhere can send a signed-in administrator here
     * with a `Referer` of its choosing.
     */
    public function testAForeignReferrerIsNeverRemembered(): void
    {
        // Act & Assert
        foreach (
            [
                'https://evil.example/phish',
                // A host that merely *starts* like ours, which a naive prefix check would accept.
                $this->base . '.evil.example/phish',
                '//evil.example/phish',
                'javascript:alert(1)',
            ] as $hostile
        ) {
            $_SESSION = [];
            $back = $this->backFrom($hostile);

            $this->assertStringNotContainsString(
                'evil.example',
                $back,
                $hostile . ' became the destination of the Back button'
            );
            $this->assertStringNotContainsString('javascript:', $back);
        }
    }

    /**
     * A host whose name merely begins like ours is not our host.
     *
     * The bug this file found. The check was `str_starts_with($referrer, $base)`, and for a base of
     * `https://example.com` the URL `https://example.com.evil.test/phish` starts with it. An attacker
     * registers a host whose name begins with yours, sends a signed-in administrator to the panel
     * with that `Referer`, and the panel renders it as the destination of its own Back button — an
     * open redirect with the panel's appearance vouching for the link.
     *
     * What follows the base has to be a boundary. Asserted in both directions, because "reject
     * everything that is not exactly the base" would break the feature entirely.
     */
    public function testAHostThatMerelyBeginsLikeOursIsRefused(): void
    {
        // Act & Assert — the lookalike
        $_SESSION = [];
        $this->assertStringNotContainsString(
            'evil.test',
            $this->backFrom($this->base . '.evil.test/phish'),
            'a host beginning with ours was accepted as ours'
        );

        // And a path genuinely on this site, which must still work
        $_SESSION = [];
        $this->assertStringContainsString(
            '/admin/Users',
            $this->backFrom($this->base . '/admin/Users'),
            'the fix rejected a real on-site path'
        );
    }

    /**
     * `?` and `#` are boundaries too, not only `/`.
     *
     * A trailing slash alone is not enough, and the reason is the exclusions rather than the
     * site check: `/adminer?db=x` **is** the Adminer page and has no slash after it, so a rule that
     * demanded one would stop recognising Adminer as part of the panel — and Back would start
     * bouncing between the two again, which is the bug the exclusion was added for.
     */
    public function testAQueryStringIsABoundaryAsWellAsASlash(): void
    {
        // Arrange
        $this->backFrom($this->base . '/admin/Logs/viewer');

        // Act — Adminer, addressed with a query string and no trailing slash
        $back = $this->backFrom($this->base . '/adminer?db=pramnos&select=users');

        // Assert
        $this->assertStringContainsString(
            '/admin/Logs/viewer',
            $back,
            'Adminer with a query string was not recognised as part of the panel'
        );
    }

    /**
     * A remembered value that has stopped being ours is refused on the way out.
     *
     * Not redundant with the check on the way in: the session outlives the request that wrote it, so
     * a changed site URL or a session restored from elsewhere can leave a value in there that was
     * once valid and no longer is. Checking only on entry means trusting a value written by a request
     * that is long gone.
     */
    public function testARememberedValueThatStoppedBeingOursIsRefused(): void
    {
        // Arrange — as if written when the site lived somewhere else
        $_SESSION[DevPanelController::RETURN_KEY] = 'https://old-domain.example/admin/Users';

        // Act
        $back = $this->backFrom(null);

        // Assert
        $this->assertStringNotContainsString(
            'old-domain.example',
            $back,
            'a stale session value became a link, without any request having vouched for it'
        );
    }

    /**
     * With nothing to go back to, Back goes somewhere rather than nowhere.
     *
     * The panel is reachable from a bookmark and from a link in the debug toolbar, neither of which
     * carries a useful referrer. An empty `href` is a button that appears to be broken.
     */
    public function testWithNothingRememberedBackStillGoesSomewhere(): void
    {
        // Act
        $back = $this->backFrom(null);

        // Assert
        $this->assertNotSame('', $back, 'the Back button had an empty destination');
    }

    // ── The instance method the panel's own button uses ───────────────────────

    /**
     * The panel's own Back button agrees with the one on other pages.
     *
     * Two entry points, one remembered value — a visitor who came from a screen returns to it
     * whichever of the two they go through. Two implementations that disagreed would send the same
     * person to two different places depending on which page they happened to be on.
     */
    public function testTheTwoBackButtonsAgree(): void
    {
        // Arrange
        $origin = $this->base . '/admin/Users?usertype=90';
        $this->remembered($origin);

        // Act
        $fromInstance = $this->remembered(null);
        $fromStatic   = $this->backFrom(null);

        // Assert
        $this->assertStringContainsString('/admin/Users', $fromInstance);
        $this->assertStringContainsString('/admin/Users', $fromStatic);
    }

    /**
     * The remembered URL is escaped before it becomes an attribute.
     *
     * It arrived in a header, and it goes into an `href`. A quote in it would end the attribute — and
     * the value has already been proven to be on this site, which makes it exactly the kind of value
     * somebody stops escaping.
     */
    public function testTheRememberedUrlIsEscaped(): void
    {
        // Arrange — on this site, and carrying something that must not reach the markup raw
        $origin = $this->base . '/admin/Users?q="><script>alert(1)</script>';

        // Act
        $back = $this->remembered($origin);

        // Assert
        $this->assertStringNotContainsString('<script>', $back, 'the referrer broke out of the href');
        $this->assertStringNotContainsString('"><', $back);
        $this->assertStringContainsString('&quot;', $back);
    }
}
