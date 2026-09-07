<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Debug\DebugAccess;
use Pramnos\Debug\DebugGrantController;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Http\Middleware\CsrfMiddleware;

/**
 * The debug-toolbar switch in the panel's header: how it looks, where it sits, what it says.
 *
 * Reported twice as *«it does not work»*, and the second time with a screenshot. The grant
 * was being issued both times. What was wrong was everything around it:
 *
 *  - **No CSS.** `.debugbar-switch` was styled by nothing, so a browser-default button —
 *    white, bordered, rounded — sat in a dark strip looking like a tooltip somebody had
 *    left open.
 *  - **Inside `<nav>`.** A flex item in a row of a dozen tabs that neither wraps nor
 *    scrolls, so on a narrow window it was pushed past the right edge: present in the
 *    markup, unreachable with a mouse.
 *  - **No receipt.** `renderLayout()` ends in `echo $html` and bypasses the framework's
 *    document, which is where the toolbar is injected — so the panel can never draw the
 *    toolbar, and the entire visible outcome of a successful grant was one word in the
 *    switch's own caption.
 *
 * A control whose only confirmation is a two-letter change in its own label has no
 * confirmation, and that is what both reports were describing.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelDebugSwitchTest extends TestCase
{
    private ?string $savedCookie = null;

    private ?string $savedKey = null;

    protected function setUp(): void
    {
        $this->savedCookie = $_COOKIE[DebugAccess::COOKIE] ?? null;
        $this->savedKey    = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');

        // `DebugAccess::issue()` refuses to sign without one, which is right and is not
        // what any of this is testing.
        putenv('APP_KEY=test-key-for-the-devpanel-switch');
        $_ENV['APP_KEY'] = 'test-key-for-the-devpanel-switch';

        unset($_COOKIE[DebugAccess::COOKIE], $_GET[DebugAccess::PARAM]);
        DebugAccess::reset();

        $this->signIn(99, 7);
    }

    /**
     * Somebody signed in, by both routes the framework offers.
     *
     * `$unittesting_logged` as well as `$_SESSION`, because a test earlier in this class's
     * own run left `$_SESSION` empty — PHPUnit restores superglobals between tests — and
     * the first test of the class then rendered a header with no switch in it and failed
     * for a reason that had nothing to do with the switch. The global is the hook
     * `Session::staticIsLogged()` documents for exactly this.
     */
    private function signIn(int $usertype, int $userid): void
    {
        global $unittesting_logged;
        $unittesting_logged = true;

        $user = new \Pramnos\User\User();
        $user->userid   = $userid;
        $user->usertype = $usertype;
        Application::getInstance()->currentUser = $user;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = $userid;
    }

    protected function tearDown(): void
    {
        if ($this->savedCookie === null) {
            unset($_COOKIE[DebugAccess::COOKIE]);
        } else {
            $_COOKIE[DebugAccess::COOKIE] = $this->savedCookie;
        }

        if ($this->savedKey === null) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->savedKey);
            $_ENV['APP_KEY'] = $this->savedKey;
        }

        unset($_GET[DebugAccess::PARAM]);
        DebugAccess::reset();
        Application::getInstance()->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid']);

        global $unittesting_logged;
        $unittesting_logged = false;

        parent::tearDown();
    }

    /**
     * The whole page, from the real `renderLayout()`.
     *
     * Driven through the method that builds the markup rather than through
     * `debugToolbarSwitch()` alone, because two of the three defects above are about the
     * switch's *surroundings* — which container it is in, and whether the page says
     * anything else about the grant. Neither is visible from the fragment.
     */
    private function page(): string
    {
        $controller = new class extends DevPanelController {
            public function __construct()
            {
                // The real constructor registers actions against an application.
            }

            protected function terminate(): void
            {
                // `exit` would take the test runner with it.
            }

            public function exposeRender(): string
            {
                ob_start();
                $this->renderLayout('overview', '<p>content</p>');

                return (string) ob_get_clean();
            }
        };

        return $controller->exposeRender();
    }

    /**
     * A live grant, as the redirect from `postEnable()` leaves it.
     */
    private function grant(): void
    {
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);
        DebugAccess::reset();
    }

    /**
     * The switch is styled, rather than left to the browser.
     *
     * The screenshot's whole content: an unstyled `<button>` in a dark header. Asserted as
     * "the class the markup uses has rules in the stylesheet the page ships", because the
     * two are written 400 lines apart and nothing else connects them.
     */
    public function testTheSwitchHasStylesOfItsOwn(): void
    {
        // Act
        $html = $this->page();

        // Assert
        $this->assertStringContainsString('class="debugbar-switch', $html);
        $this->assertStringContainsString('.debugbar-switch button {', $html);
        $this->assertStringContainsString('.debugbar-switch.is-on button {', $html);
    }

    /**
     * It sits in the header, not inside the scrolling tab strip.
     *
     * The positional assertion, and the one that could not be made while the switch was
     * appended to `$tabHtml`: it must come **after** `</nav>`. Inside, a narrow window
     * pushed it off the right edge of a row that does not wrap.
     */
    public function testTheSwitchIsOutsideTheTabStrip(): void
    {
        // Act
        $html = $this->page();

        // Assert
        // `class="…` and not the bare name: the stylesheet in `<head>` also mentions it,
        // 13,000 characters earlier, which is what the first version of this measured.
        $navEnd = strpos($html, '</nav>');
        $switch = strpos($html, 'class="debugbar-switch');

        $this->assertIsInt($navEnd);
        $this->assertIsInt($switch);
        $this->assertGreaterThan($navEnd, $switch, 'the switch is back inside <nav>');

        // and the tabs scroll rather than shoving what follows them
        $this->assertStringContainsString('overflow-x: auto', $html);
    }

    /**
     * Off, the caption is something to press.
     *
     * `Debug bar: off` was a status line wearing a button's chrome, which reads as a label
     * somebody forgot to make clickable — and this control has been reported as doing
     * nothing twice.
     */
    public function testOffItReadsAsAnAction(): void
    {
        // Act
        $html = $this->page();

        // Assert
        $this->assertStringContainsString('Turn debug bar on', $html);
        $this->assertStringNotContainsString(
            '<div class="debugbar-notice"',
            $html,
            'a notice with no grant'
        );
    }

    /**
     * On, the page says so twice: in the caption, and in a sentence that says where to look.
     *
     * The receipt. The panel cannot draw the toolbar — it echoes its own HTML — so without
     * this the successful outcome of pressing the switch is indistinguishable from nothing
     * happening, which is precisely how it was reported.
     */
    public function testOnItSaysSoAndSaysWhereTheToolbarIs(): void
    {
        // Arrange
        $this->grant();

        // Act
        $html = $this->page();

        // Assert
        $this->assertStringContainsString('debugbar-switch is-on', $html);
        $this->assertStringContainsString('turn off', $html);
        $this->assertStringContainsString('<div class="debugbar-notice"', $html);
        $this->assertStringContainsString("site's own pages", $html);
    }

    /**
     * The form posts what the grant route now insists on.
     *
     * `DebugGrantController` registers `CsrfMiddleware` on the two POST actions, so a form
     * without the field is refused with a 419. This switch is the only caller that builds
     * its own form, and it is not covered by the grant screen's tests.
     */
    public function testTheFormCarriesTheCsrfField(): void
    {
        // Act
        $html = $this->page();

        // Assert
        $this->assertStringContainsString('name="_csrf_token"', $html);
        $this->assertStringContainsString('action="', $html);
        $this->assertStringContainsString('/debugbar/enable"', $html);
        $this->assertStringContainsString('name="return"', $html);
    }

    /**
     * Somebody below the grant floor sees no switch at all.
     *
     * A control that 403s when pressed is worse than one that is absent — and this one is
     * drawn on a panel whose own floor can be lower than the grant's.
     */
    public function testBelowTheGrantFloorThereIsNoSwitch(): void
    {
        // Arrange — the control first: at the floor the switch is there to lose
        $this->assertStringContainsString('class="debugbar-switch', $this->page());

        $this->signIn((int) DebugGrantController::DEFAULT_MIN_USERTYPE - 1, 8);

        // Act
        $html = $this->page();

        // Assert
        $this->assertStringNotContainsString('class="debugbar-switch', $html);
        $this->assertStringContainsString('</nav>', $html, 'the page did not render at all');
    }
}
