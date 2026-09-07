<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Debug\DebugAccess;
use Pramnos\Debug\DebugGrantController;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Http\Request;

/**
 * The DevPanel's debug-bar switch across the seam no test was covering.
 *
 * Reported twice, and the second time with both halves: *"I press it and it does NOT do
 * anything"* when the bar is on, and *"when it is off it does not turn on from here — it
 * throws me to the front end of the site"*.
 *
 * Every test this feature had drove **one** request. They rendered the switch, or they
 * posted a form the test itself had built with `CsrfMiddleware::token()` — which is
 * self-consistent by construction and cannot fail. The seam is that **the token is issued
 * while the panel renders and checked in the next request**, with a session in between, and
 * nothing exercised that.
 *
 * So: render the switch, take the token out of the HTML the way a browser reads a form, and
 * dispatch the POST through the real controller with the session carried across. If the
 * grant works and the redirect goes somewhere useful, the feature works.
 */
#[CoversClass(DevPanelController::class)]
#[CoversClass(DebugGrantController::class)]
class DebugSwitchEndToEndTest extends TestCase
{
    private ?string $savedKey = null;

    private string $savedMethod = 'GET';

    protected function setUp(): void
    {
        $this->savedKey    = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        $this->savedMethod = (string) Request::$requestMethod;

        putenv('APP_KEY=test-key-for-the-debug-switch');
        $_ENV['APP_KEY'] = 'test-key-for-the-debug-switch';

        global $unittesting_logged;
        $unittesting_logged = true;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 7;

        // A fresh CSRF token for this "browser", as a real session would carry.
        unset($_SESSION['csrf_token']);

        $user = new \Pramnos\User\User();
        $user->userid   = 7;
        $user->usertype = 99;
        Application::getInstance()->currentUser = $user;

        unset($_COOKIE[DebugAccess::COOKIE], $_GET[DebugAccess::PARAM]);
        DebugAccess::reset();
    }

    protected function tearDown(): void
    {
        if ($this->savedKey === null) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->savedKey);
            $_ENV['APP_KEY'] = $this->savedKey;
        }

        Request::$requestMethod = $this->savedMethod;
        $_POST = array();

        Application::getInstance()->currentUser = null;
        unset(
            $_SESSION['logged'],
            $_SESSION['uid'],
            $_SESSION['csrf_token'],
            $_COOKIE[DebugAccess::COOKIE]
        );

        global $unittesting_logged;
        $unittesting_logged = false;

        DebugAccess::reset();

        parent::tearDown();
    }

    /**
     * Request one: the panel's header, with the switch in it.
     *
     * `renderLayout()` rather than `debugToolbarSwitch()` alone, so the CSRF token is issued
     * exactly as it is for a visitor — during a page render, into the session.
     */
    private function renderPanel(): string
    {
        $controller = new class extends DevPanelController {
            public function __construct()
            {
                // The real constructor registers actions against an application.
            }

            protected function terminate(): void
            {
                // `exit` would take the runner with it.
            }

            public function exposeRender(): string
            {
                ob_start();

                try {
                    $this->renderLayout('overview', '<p>content</p>');
                } finally {
                    return (string) ob_get_clean();
                }
            }
        };

        return $controller->exposeRender();
    }

    /**
     * Request two: the browser posts the form.
     *
     * @param array<string, string> $fields
     * @return array{0: string, 1: string} The redirect, and anything echoed instead
     */
    private function postSwitch(string $action, array $fields): array
    {
        Request::$requestMethod = 'POST';
        $_POST = $fields;

        $controller = new class extends DebugGrantController {
            public string $went = '';

            public function __construct()
            {
                parent::__construct(null, array());
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->went = (string) $url;
            }

            protected function terminate(): void
            {
            }
        };

        ob_start();

        try {
            $controller->exec($action);
        } catch (\Throwable $exception) {
            ob_end_clean();

            return array('', get_class($exception) . '(' . $exception->getCode() . '): '
                . $exception->getMessage());
        }

        return array($controller->went, (string) ob_get_clean());
    }

    /**
     * A form field's value, read out of the rendered HTML as a browser would.
     */
    private function fieldValue(string $html, string $name): string
    {
        if (preg_match(
            '/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/',
            $html,
            $m
        ) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        return '';
    }

    /**
     * The panel renders a form a browser can submit, with both hidden fields.
     *
     * Asserted first because everything below depends on it, and because an assertion on a
     * fragment built in PHP does not prove that the HTML reaching the browser has them.
     */
    public function testThePanelRendersASubmittableForm(): void
    {
        // Act
        $html = $this->renderPanel();

        // Assert
        $this->assertStringContainsString('/debugbar/enable"', $html, 'no switch in the panel');
        $this->assertNotSame('', $this->fieldValue($html, '_csrf_token'), 'no CSRF token issued');
        $this->assertNotSame('', $this->fieldValue($html, 'return'), 'no return address');
        $this->assertSame('3600', $this->fieldValue($html, 'ttl'));
    }

    /**
     * **The panel's own token passes the panel's own form.**
     *
     * The seam, and the assertion that could not be made by any single-request test: the
     * token comes out of the rendered HTML rather than from `CsrfMiddleware::token()`, so a
     * token that is issued but never persisted — or checked against a different source — is
     * a 419 here and was invisible before.
     */
    public function testTheTokenTheRenderIssuedPassesTheCheck(): void
    {
        // Arrange — request one
        $html = $this->renderPanel();

        // Act — request two, with what the browser would send
        [$location, $output] = $this->postSwitch('enable', array(
            '_csrf_token' => $this->fieldValue($html, '_csrf_token'),
            'return'      => $this->fieldValue($html, 'return'),
            'ttl'         => $this->fieldValue($html, 'ttl'),
        ));

        // Assert
        $this->assertStringNotContainsString('419', $output, 'the panel\'s own token was refused');
        $this->assertNotSame('', $location, 'nothing was redirected: ' . $output);
        $this->assertStringContainsString(DebugAccess::PARAM . '=', $location);
    }

    /**
     * And the grant it hands out is one the framework accepts.
     *
     * A redirect carrying a token nothing verifies looks exactly like a working switch and
     * turns nothing on, which is one of the two reported symptoms.
     */
    public function testTheGrantInTheRedirectIsAccepted(): void
    {
        // Arrange
        $html = $this->renderPanel();

        [$location] = $this->postSwitch('enable', array(
            '_csrf_token' => $this->fieldValue($html, '_csrf_token'),
            'return'      => $this->fieldValue($html, 'return'),
            'ttl'         => '3600',
        ));

        // Act — the browser follows it
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $_GET[DebugAccess::PARAM] = (string) ($query[DebugAccess::PARAM] ?? '');
        DebugAccess::reset();

        // Assert
        $this->assertTrue(DebugAccess::isGranted(), 'the redirect carried a token nothing accepts');

        unset($_GET[DebugAccess::PARAM]);
    }

    /**
     * It comes back to the panel, not to the front page.
     *
     * The second reported symptom in one assertion — *"it throws me to the front end of the
     * site"* is what the `returnUrl()` fallback looks like when the `return` field is missing
     * or refused.
     */
    public function testItReturnsToThePanelRatherThanTheSiteRoot(): void
    {
        // Arrange
        $html = $this->renderPanel();

        // Act
        [$location] = $this->postSwitch('enable', array(
            '_csrf_token' => $this->fieldValue($html, '_csrf_token'),
            'return'      => $this->fieldValue($html, 'return'),
            'ttl'         => '3600',
        ));

        // Assert
        $this->assertStringContainsString(
            '/devpanel',
            $location,
            'the switch sent the browser to the site root: ' . $location
        );
    }

    /**
     * Turning it off works the same way, and revokes.
     *
     * The half the report describes as doing nothing. `postDisable()` issues no token, so it
     * fails differently from `postEnable()` — and the two symptoms in the report being
     * different is what said the two paths diverge.
     */
    public function testTurningItOffRevokes(): void
    {
        // Arrange — a live grant, and the panel rendered while it is on
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);
        DebugAccess::reset();

        $html = $this->renderPanel();

        $this->assertStringContainsString('/debugbar/disable"', $html, 'the switch still offers to enable');

        // Act
        [$location, $output] = $this->postSwitch('disable', array(
            '_csrf_token' => $this->fieldValue($html, '_csrf_token'),
            'return'      => $this->fieldValue($html, 'return'),
        ));

        // Assert
        $this->assertNotSame('', $location, 'disabling redirected nowhere: ' . $output);
        $this->assertStringContainsString(
            DebugAccess::PARAM . '=' . DebugAccess::REVOKE,
            $location,
            'the redirect did not carry the revocation'
        );

        // and following it clears the cookie
        $_GET[DebugAccess::PARAM] = DebugAccess::REVOKE;
        DebugAccess::reset();

        $this->assertFalse(DebugAccess::isGranted());
        $this->assertArrayNotHasKey(DebugAccess::COOKIE, $_COOKIE, 'the grant survived its revocation');

        unset($_GET[DebugAccess::PARAM]);
    }

    /**
     * A form posted without the token is refused, which is the check being real.
     *
     * The control for the first test: if a missing token also passed, that test would be
     * asserting nothing about CSRF.
     */
    public function testAFormWithoutTheTokenIsRefused(): void
    {
        // Act
        [$location, $output] = $this->postSwitch('enable', array('ttl' => '3600'));

        // Assert
        $this->assertSame('', $location, 'a form with no token was honoured');
        $this->assertStringContainsString('419', $output);
    }

    /**
     * The return address survives a scheme, host or port that has moved.
     *
     * **The reported bug.** The field carried an absolute URL and `returnUrl()` compared it
     * against `sURL` as a string prefix, so any of these refused it and sent the browser to
     * the site root — which is *"it throws me to the front end of the site"*:
     *
     * ```
     * posted http://host/devpanel        against https://host      → refused
     * posted https://www.host/devpanel   against https://host      → refused
     * posted https://host:443/devpanel   against https://host      → refused
     * ```
     *
     * Behind a proxy every one of those is ordinary: `X-Forwarded-Proto`, a `www` redirect,
     * an explicit port. A **path** names no host, so it cannot disagree with one — and that
     * is what the switch posts now.
     */
    public function testAPathReturnAddressSurvivesAMovedHost(): void
    {
        // Arrange — the panel's own field, which must be a path
        $html   = $this->renderPanel();
        $return = $this->fieldValue($html, 'return');

        $this->assertStringStartsWith('/', $return, 'the switch still posts an absolute URL');
        $this->assertStringNotContainsString('://', $return);

        // Act
        [$location] = $this->postSwitch('enable', array(
            '_csrf_token' => $this->fieldValue($html, '_csrf_token'),
            'return'      => $return,
            'ttl'         => '3600',
        ));

        // Assert — back to the panel, absolute, with the grant on it
        $this->assertStringContainsString('/devpanel', $location);
        $this->assertStringContainsString('://', $location, 'the redirect is not an absolute URL');
        $this->assertStringContainsString(DebugAccess::PARAM . '=', $location);
    }

    /**
     * A path that is a way off the site is still refused.
     *
     * The protection the absolute-URL check was there for, kept. Three shapes look like a
     * path and are not: `//evil` is protocol-relative and a browser reads it as another host,
     * `/\evil` is the same trick with a backslash, and a scheme can hide after the slash.
     * Each is an open redirect that begins with a slash.
     */
    public function testAPathThatLeavesTheSiteIsRefused(): void
    {
        $html  = $this->renderPanel();
        $token = $this->fieldValue($html, '_csrf_token');

        foreach (array(
            '//evil.example/x',
            '/\\evil.example/x',
            '/https://evil.example',
            'https://evil.example/x',
        ) as $hostile) {
            // Act
            [$location] = $this->postSwitch('enable', array(
                '_csrf_token' => $token,
                'return'      => $hostile,
                'ttl'         => '3600',
            ));

            // Assert — never the attacker's address
            $this->assertStringNotContainsString(
                'evil.example',
                $location,
                'an off-site return address was honoured: ' . $hostile
            );
        }
    }

    /**
     * A refused return address is logged, because the fallback looks like a broken feature.
     *
     * Landing on the site root is indistinguishable from the switch doing nothing, and
     * nothing anywhere said which it was. That cost an afternoon twice.
     */
    public function testARefusedReturnAddressIsRecorded(): void
    {
        // Arrange
        $recorded = array();

        $controller = new class ($recorded) extends DebugGrantController {
            /** @param array<int, string> $recorded */
            public function __construct(private array &$recorded)
            {
            }

            protected function reportRefusedReturn(string $asked, string $base): void
            {
                $this->recorded[] = $asked;
            }

            public function exposeReturnUrl(): string
            {
                return $this->returnUrl();
            }
        };

        $_POST['return'] = 'https://evil.example/x';

        // Act
        $where = $controller->exposeReturnUrl();

        // Assert — refused, and the refusal is not silent
        $this->assertStringNotContainsString('evil.example', $where);
        $this->assertSame(
            array('https://evil.example/x'),
            $recorded,
            'a refused return address was not recorded anywhere'
        );

        // and an address that is accepted is not reported as a refusal
        $recorded        = array();
        $_POST['return'] = '/devpanel';

        $controller->exposeReturnUrl();

        $this->assertSame(array(), $recorded, 'an accepted address was reported as refused');
    }
}
