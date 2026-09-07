<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\DebugAccess;
use Pramnos\Debug\DebugGrantController;
use Pramnos\Http\Middleware\CsrfMiddleware;
use Pramnos\Http\Request;

/**
 * The grant route driven the way a browser drives it — through `exec()`.
 *
 * Every other test of this controller calls `postEnable()` directly, so the whole of
 * dispatch was uncovered: whether a `POST` to `/debugbar/enable` resolves to that
 * method at all, and whether anything validated the CSRF token the form has always
 * carried.
 *
 * Nothing did. The constructor's comment said `CsrfMiddleware` validated these two
 * actions, and no middleware was ever registered — `Controller::_runThroughMiddleware()`
 * runs what a controller has registered, and this one registered none. A claimed check
 * is worse than an absent one, because it is the reason nobody looks.
 *
 * And the registration has a trap of its own: `exec()` resolves `POST enable` to
 * `postenable` and passes **that** name to the pipeline, so a middleware filed under
 * `enable` is never reached. Filed under the wrong name it would have looked exactly
 * like this fix and changed nothing.
 */
#[CoversClass(DebugGrantController::class)]
class DebugGrantDispatchTest extends TestCase
{
    private ?string $savedKey = null;

    private string $savedMethod = 'GET';

    protected function setUp(): void
    {
        $this->savedKey    = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        $this->savedMethod = (string) Request::$requestMethod;

        putenv('APP_KEY=test-key-for-grant-dispatch');
        $_ENV['APP_KEY'] = 'test-key-for-grant-dispatch';

        global $unittesting_logged;
        $unittesting_logged = true;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 7;

        // A user on the instance, so `Logger::log()`'s `getUserId()` does not go to the
        // database looking for one — there is none in a unit test, and the failure it
        // produces is a mysqli connection error a long way from anything under test.
        $user = new \Pramnos\User\User();
        $user->userid   = 7;
        $user->usertype = 99;
        \Pramnos\Application\Application::getInstance()->currentUser = $user;

        Request::$requestMethod = 'POST';
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
        \Pramnos\Application\Application::getInstance()->currentUser = null;

        global $unittesting_logged;
        $unittesting_logged = false;
        unset($_SESSION['logged'], $_SESSION['uid'], $_COOKIE[DebugAccess::COOKIE]);
        DebugAccess::reset();

        parent::tearDown();
    }

    /**
     * A controller that records its redirect instead of sending one.
     *
     * `mayGrant()` is answered directly: who may grant is covered at length elsewhere, and
     * what this class is about is everything between the request and the method.
     */
    private function controller(): object
    {
        return new class extends DebugGrantController {
            /** @var list<string> */
            public array $went = array();

            public function __construct()
            {
                parent::__construct(null, array());
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->went[] = (string) $url;
            }

            protected function mayGrant(): bool
            {
                return true;
            }
        };
    }

    /**
     * A POST with the token reaches `postEnable()` and redirects with a usable grant.
     *
     * The end-to-end path, and the assertion that the dispatch convention holds:
     * `strtolower('POST' . ucfirst('enable'))` has to name a method on this class, and
     * `auth()` has to pass for both the bare and the prefixed name.
     */
    public function testAPostWithTheTokenIssuesAGrant(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = array('ttl' => 3600, '_csrf_token' => CsrfMiddleware::token());

        // Act
        ob_start();

        try {
            $controller->exec('enable');
        } finally {
            $output = (string) ob_get_clean();
        }

        // Assert
        $this->assertCount(1, $controller->went, 'the action was not reached: ' . $output);
        $this->assertStringContainsString(DebugAccess::PARAM . '=', $controller->went[0]);

        // and the token in that URL is one the framework will accept
        parse_str((string) parse_url($controller->went[0], PHP_URL_QUERY), $query);
        $_GET[DebugAccess::PARAM] = $query[DebugAccess::PARAM] ?? '';
        DebugAccess::reset();

        $this->assertTrue(DebugAccess::isGranted(), 'the redirect carried a token nothing accepts');

        unset($_GET[DebugAccess::PARAM]);
    }

    /**
     * Without the token the request is refused, and the grant is never issued.
     *
     * The check that was claimed and absent. 419 is what `CsrfMiddleware` throws, and the
     * assertion that matters is the second one: no redirect, so nothing was signed.
     */
    public function testAPostWithoutTheTokenIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = array('ttl' => 3600);

        // Act
        $code    = 0;
        $message = '';

        ob_start();

        try {
            $controller->exec('enable');
        } catch (\Throwable $exception) {
            $code    = (int) $exception->getCode();
            $message = $exception->getMessage();
        } finally {
            ob_end_clean();
        }

        // Assert
        $this->assertSame(419, $code, 'the CSRF check did not run: ' . $message);
        $this->assertSame(array(), $controller->went, 'a grant was issued anyway');
    }

    /**
     * Disabling is protected the same way.
     *
     * Registered under `postdisable`, and worth its own case: a revocation somebody else
     * can trigger is a smaller problem than a grant, and it is still a state change on a
     * `POST` that has a token field.
     */
    public function testDisablingIsProtectedToo(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = array();

        // Act
        $refused = false;

        ob_start();

        try {
            $controller->exec('disable');
        } catch (\Throwable $exception) {
            $refused = (int) $exception->getCode() === 419;
        } finally {
            ob_end_clean();
        }

        // Assert
        $this->assertTrue($refused);

        // and with the token it goes through
        $_POST = array('_csrf_token' => CsrfMiddleware::token());

        ob_start();

        try {
            $controller->exec('disable');
        } finally {
            ob_end_clean();
        }

        $this->assertCount(1, $controller->went);
        $this->assertStringContainsString(DebugAccess::REVOKE, $controller->went[0]);
    }

    /**
     * A `GET` on the same address is the screen, not a state change.
     *
     * The reason the two actions are POST-only: a state change behind a link is a link
     * somebody else can get you to click, and `exec()` only looks for the method-prefixed
     * name when the request is not a `GET`.
     */
    public function testAGetIsTheScreenAndChangesNothing(): void
    {
        // Arrange
        $controller = $this->controller();
        Request::$requestMethod = 'GET';

        // Act
        ob_start();

        try {
            $controller->exec('enable');
        } finally {
            $html = (string) ob_get_clean();
        }

        // Assert — 405 and the screen, where it used to be a fatal
        $this->assertSame(array(), $controller->went, 'a GET issued a grant');
        $this->assertStringContainsString('Debug toolbar', $html);
        $this->assertStringContainsString('only answers a POST', $html);
        $this->assertSame(405, http_response_code());
    }
}
