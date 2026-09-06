<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\DebugAccess;
use Pramnos\Debug\DebugGrantController;

/**
 * Turning the toolbar on from a screen instead of from a shell.
 *
 * `DebugAccess` could always do this; the only door was `debug:token`, which needs
 * a shell on the server — the wrong door for the moment somebody is looking at a
 * page that is misbehaving, because the page is gone by the time they get back.
 *
 * The interesting assertions are the guard and the redirect. The grant itself is
 * `DebugAccess`'s and is covered where it lives; what is new here is who may ask
 * for one, and that asking lands you back where you were rather than somewhere an
 * attacker chose.
 */
#[CoversClass(DebugGrantController::class)]
class DebugGrantControllerTest extends TestCase
{
    /** @var string|null The APP_KEY as the environment had it */
    private ?string $originalKey = null;

    protected function setUp(): void
    {
        $this->originalKey = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        $this->withKey('test-key-for-debug-grant');
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            $this->withKey($this->originalKey);
        }

        \Pramnos\Application\Application::getInstance()->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid'], $_POST['ttl']);

        parent::tearDown();
    }

    /**
     * `DebugAccess` signs with the application key and refuses when there is none, so
     * every test here has to say which of those two worlds it is in.
     */
    private function withKey(string $key): void
    {
        if ($key === '') {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);

            return;
        }

        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;
    }

    /**
     * A controller with the guard's inputs under the test's control.
     */
    private function controller(int $usertype, int $userid = 4242, int $floor = 90): object
    {
        $user = new \Pramnos\User\User();
        $user->userid   = $userid;
        $user->usertype = $usertype;

        \Pramnos\Application\Application::getInstance()->currentUser = $user;
        $_SESSION['logged'] = $userid > 0;
        $_SESSION['uid']    = $userid;

        return new class ($floor) extends DebugGrantController {
            public function __construct(private int $floor)
            {
                // Skipping the parent constructor: it registers actions on a real
                // application, and none of that is what these assertions are about.
            }

            protected function minUserType(): int
            {
                return $this->floor;
            }

            public function exposeMayGrant(): bool
            {
                return $this->mayGrant();
            }

            public function exposeScreen(string $problem = ''): string
            {
                return $this->screen($problem);
            }

            /** Where the action tried to send the browser. */
            public ?string $redirectedTo = null;

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo = (string) $url;
            }

            /**
             * A fixed return URL.
             *
             * The real one goes through `DevPanelController::returnUrlFor()`, which reads
             * the referrer, writes the session and refuses anything off-site. That is
             * covered where it lives; pinning it here would make these tests assertions
             * about the referrer instead of about the grant.
             */
            protected function returnUrl(): string
            {
                return 'https://example.com/orders?page=2';
            }
        };
    }

    /**
     * An administrator at or above the floor may ask.
     */
    public function testSomebodyAtTheFloorMayGrant(): void
    {
        // Assert
        $this->assertTrue($this->controller(90)->exposeMayGrant());
        $this->assertTrue($this->controller(95)->exposeMayGrant());
    }

    /**
     * Below the floor, no.
     *
     * The floor exists because the toolbar reports the SQL each request ran and this
     * framework interpolates values into statements — so it shows real column values,
     * personal ones included, for the rows that browsing touches. "Signed in" is not
     * the right bar for that.
     */
    public function testBelowTheFloorIsRefused(): void
    {
        // Assert
        $this->assertFalse($this->controller(89)->exposeMayGrant());
        $this->assertFalse($this->controller(0)->exposeMayGrant());
    }

    /**
     * Nobody signed in is refused whatever their user type says.
     *
     * A `User` object with a type and no id is what a lookup that found nothing leaves
     * behind, and it is truthy — so the id is checked first and separately.
     */
    public function testNobodySignedInIsRefused(): void
    {
        // Assert
        $this->assertFalse($this->controller(99, 0)->exposeMayGrant());
    }

    /**
     * The floor is configurable upwards, and a raised one actually refuses.
     *
     * From `app.php` rather than a settings row: who may read the query log of a live
     * server is a property of the deployment, so it belongs with the code where a
     * change to it leaves a trace.
     */
    public function testARaisedFloorRefusesWhatTheDefaultWouldAllow(): void
    {
        // Assert
        $this->assertTrue($this->controller(90, 4242, 90)->exposeMayGrant());
        $this->assertFalse($this->controller(90, 4242, 95)->exposeMayGrant());
    }

    /**
     * With no application at all, the default floor applies rather than a fatal.
     *
     * `currentInstance()` is null outside a request, and `getInstance()` is a factory
     * that would boot an application to answer a question about a number.
     */
    public function testWithNoApplicationTheDefaultFloorApplies(): void
    {
        // Arrange — the real method, not the test override
        $real = (new \ReflectionClass(DebugGrantController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DebugGrantController::class, 'minUserType');

        // Act + Assert
        $this->assertSame(
            DebugGrantController::DEFAULT_MIN_USERTYPE,
            $method->invoke($real)
        );
    }

    /**
     * The screen offers the TTLs and carries a CSRF field on every form.
     *
     * The two actions change state, so they are POST and the middleware validates
     * them — a switch that turns on a query log must not be reachable by getting
     * somebody to click a link.
     */
    public function testTheScreenPostsWithACsrfField(): void
    {
        // Act
        $html = $this->controller(90)->exposeScreen();

        // Assert
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('_csrf_token', $html);
        $this->assertStringNotContainsString('method="get"', $html);

        foreach (DebugGrantController::TTL_CHOICES as $seconds => $label) {
            $this->assertStringContainsString('value="' . $seconds . '"', $html);
            $this->assertStringContainsString($label, $html);
        }
    }

    /**
     * The screen says what the toolbar will show, on the screen that turns it on.
     *
     * Somebody clicking this is about to read live values. The warning belongs where
     * the decision is, not only in a guide.
     */
    public function testTheScreenSaysWhatItWillShow(): void
    {
        // Act
        $html = $this->controller(90)->exposeScreen();

        // Assert
        $this->assertStringContainsString('this browser only', $html);
        $this->assertStringContainsString('auth log', $html);
    }

    /**
     * A problem is rendered escaped, not interpolated.
     */
    public function testAProblemIsEscaped(): void
    {
        // Act
        $html = $this->controller(90)->exposeScreen('<script>alert(1)</script>');

        // Assert
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Enabling issues a real grant and sends the browser back with it applied.
     *
     * The assertions that matter: the token verifies, so it is the same kind of grant the
     * CLI mints; and it arrives as `?_debug=` on the page the request came from, so
     * redemption keeps happening in one place instead of this action setting a cookie of
     * its own that could drift from what the CLI's link does.
     */
    public function testEnablingRedirectsBackWithAWorkingGrant(): void
    {
        // Arrange
        $controller  = $this->controller(90);
        $_POST['ttl'] = '900';

        // Act
        $controller->postEnable();

        // Assert
        $this->assertIsString($controller->redirectedTo);
        $this->assertStringStartsWith('https://example.com/orders?page=2&', $controller->redirectedTo);

        parse_str((string) parse_url($controller->redirectedTo, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey(DebugAccess::PARAM, $query);
        $this->assertTrue(
            DebugAccess::verify((string) $query[DebugAccess::PARAM]),
            'the grant it handed out does not verify'
        );
    }

    /**
     * A TTL the screen never offered falls back to the default rather than being honoured.
     *
     * `$_POST` is whatever was posted. Without this, `ttl=999999` would ask
     * `DebugAccess` for a grant longer than its own ceiling and get a clamped one — a
     * silent disagreement between what was asked and what happened.
     */
    public function testATtlThatIsNotOnOfferFallsBackToTheDefault(): void
    {
        // Arrange
        $controller   = $this->controller(90);
        $_POST['ttl'] = '999999';

        // Act
        $controller->postEnable();

        // Assert — a valid grant, and one that expires about an hour out, not a week
        parse_str((string) parse_url((string) $controller->redirectedTo, PHP_URL_QUERY), $query);
        $token = (string) $query[DebugAccess::PARAM];
        $this->assertTrue(DebugAccess::verify($token));

        [$expiry] = explode('.', $token, 2);
        $this->assertEqualsWithDelta(
            time() + DebugGrantController::DEFAULT_TTL,
            (int) $expiry,
            30
        );
    }

    /**
     * Disabling sends the browser back with the revocation, not with a grant.
     */
    public function testDisablingRedirectsBackWithTheRevocation(): void
    {
        // Arrange
        $controller = $this->controller(90);

        // Act
        $controller->postDisable();

        // Assert
        parse_str((string) parse_url((string) $controller->redirectedTo, PHP_URL_QUERY), $query);
        $this->assertSame(DebugAccess::REVOKE, $query[DebugAccess::PARAM]);
    }

    /**
     * With no application key it says so and issues nothing.
     *
     * `DebugAccess` signs with the key and refuses rather than falling back to a
     * predictable secret — a default here would hand the query log of a live server to
     * anybody who guessed it. What this action must not do is redirect anyway with an
     * empty token, which looks like it worked.
     */
    public function testWithNoApplicationKeyItExplainsAndIssuesNothing(): void
    {
        // Arrange
        $this->withKey('');
        $controller = $this->controller(90);

        // Act
        ob_start();
        $controller->postEnable();
        $html = (string) ob_get_clean();

        // Assert
        $this->assertNull($controller->redirectedTo, 'it redirected with nothing to redeem');
        $this->assertStringContainsString('No application key', $html);
        $this->assertStringContainsString('key:generate', $html);
    }

    /**
     * Below the floor, every action refuses with a 403 and issues nothing.
     *
     * Asserted on all three, because the guard is repeated per action rather than
     * centralised — and a guard that is repeated is a guard that can be forgotten in one
     * place.
     */
    public function testEveryActionRefusesBelowTheFloor(): void
    {
        foreach (array('display', 'postEnable', 'postDisable') as $action) {
            // Arrange
            $controller = $this->controller(10);

            // Act
            ob_start();
            $controller->$action();
            $html = (string) ob_get_clean();

            // Assert
            $this->assertNull($controller->redirectedTo, $action . ' acted for somebody refused');
            $this->assertStringContainsString('403', $html, $action . ' did not refuse');
            $this->assertStringContainsString('user type 90', $html);
        }
    }

    /**
     * The screen renders for somebody allowed, and shows the live state after a grant.
     */
    public function testTheScreenShowsWhetherAGrantIsLive(): void
    {
        // Arrange
        $controller = $this->controller(90);

        // Act — off
        ob_start();
        $controller->display();
        $off = (string) ob_get_clean();

        // and on, by presenting a grant the way a redeemed cookie does
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(900);
        DebugAccess::reset();

        ob_start();
        $this->controller(90)->display();
        $on = (string) ob_get_clean();

        unset($_COOKIE[DebugAccess::COOKIE]);
        DebugAccess::reset();

        // Assert
        $this->assertStringContainsString('Off.', $off);
        $this->assertStringContainsString('Turn it off', $on);
        $this->assertStringNotContainsString('Turn it off', $off);
    }

    /**
     * The parameter is appended with `&` when the return URL already has a query string,
     * and with `?` when it does not.
     *
     * A `?` on a URL that already has one produces a parameter nothing reads, so the
     * grant would be dropped on the way back — the toolbar simply would not appear, with
     * nothing to say why.
     */
    public function testTheParameterIsAppendedWithTheRightSeparator(): void
    {
        // Arrange
        $apply = new \ReflectionMethod(DebugGrantController::class, 'applyTo');
        $controller = $this->controller(90);

        // Act + Assert
        $this->assertSame(
            'https://example.com/a?_debug=x',
            $apply->invoke($controller, 'https://example.com/a', 'x')
        );
        $this->assertSame(
            'https://example.com/a?b=1&_debug=x',
            $apply->invoke($controller, 'https://example.com/a?b=1', 'x')
        );
    }

    /**
     * Every TTL the screen offers is one the action accepts.
     *
     * A button that posts a value the action silently replaces with the default is a
     * button that lies about what it did — and the mismatch is invisible, because both
     * ends produce a working grant.
     */
    public function testEveryOfferedTtlIsAnAcceptedTtl(): void
    {
        // Assert
        foreach (array_keys(DebugGrantController::TTL_CHOICES) as $seconds) {
            $this->assertLessThanOrEqual(
                DebugAccess::MAX_TTL,
                $seconds,
                'the screen offers a TTL DebugAccess would clamp'
            );
        }
        $this->assertArrayHasKey(
            DebugGrantController::DEFAULT_TTL,
            DebugGrantController::TTL_CHOICES,
            'the fallback TTL is not one of the buttons'
        );
    }
}
