<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Html\Icon;

/**
 * `Controller::addWriteAction()` — a state change runs only as a `POST` carrying this session's token.
 *
 * Forty-eight actions across the framework's administration screens deleted, revoked, reset or
 * sent on a plain `GET` link or on a `POST` nobody checked, so any page a signed-in administrator
 * opened could do the same — an `<img src="…/users/delete/5">` needs no click. The guard lives in
 * `exec()`, before the action runs, so a controller declares its write actions once and no action
 * needs a check of its own. These tests pin what it accepts and what it refuses.
 */
#[CoversClass(Controller::class)]
#[CoversClass(Icon::class)]
class ControllerWriteActionsTest extends TestCase
{
    protected function setUp(): void
    {
        \Pramnos\Http\Session::getInstance();
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        \Pramnos\Http\Request::$requestMethod = 'GET';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::$requestMethod = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /** A controller whose `wipe` action is a declared write, and which records what happened. */
    private function controller(): object
    {
        return new class extends Controller {
            public bool $ran = false;
            public ?string $redirectedTo = null;
            public array $errorsAdded = [];

            public function __construct()
            {
                $this->addAuthAction(['wipe', 'show']);
                $this->addWriteAction('Wipe');
            }

            public function wipe(): string
            {
                $this->ran = true;

                return 'wiped';
            }

            public function show(): string
            {
                return 'shown';
            }

            public function auth($action)
            {
                return true;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo = (string) $url;
            }

            public function addError($error)
            {
                $this->errorsAdded[] = $error;

                return $this;
            }
        };
    }

    private function post(array $fields = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Pramnos\Http\Request::$requestMethod = 'POST';
        $_POST = $fields;
    }

    /**
     * A `GET` to a write action is refused before it runs — the `<img src>` case.
     */
    public function testAGetIsRefusedBeforeTheActionRuns(): void
    {
        // Arrange
        $controller = $this->controller();
        $_SERVER['HTTP_REFERER'] = (defined('sURL') ? sURL : '/') . 'admin/users';

        // Act
        $result = $controller->exec('wipe');

        // Assert
        $this->assertNull($result);
        $this->assertFalse($controller->ran);
        $this->assertStringContainsString('could not be verified', $controller->errorsAdded[0] ?? '');
        $this->assertSame($_SERVER['HTTP_REFERER'], $controller->redirectedTo, 'sent back where it came from on this site');
    }

    /** A `POST` without a token is refused too — a form on another site. */
    public function testAPostWithoutATokenIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->post();
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/page';

        // Act
        $controller->exec('wipe');

        // Assert — and a foreign referer is not followed back
        $this->assertFalse($controller->ran);
        $this->assertSame(defined('sURL') ? sURL : '/', $controller->redirectedTo);
    }

    /**
     * Each of the framework's token forms is accepted: the form token, the synchronizer token as a
     * field, and the synchronizer token as the header a page's own `fetch()` sends.
     */
    public function testEachKindOfTokenIsAccepted(): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        // Starts the session, which is what names the form token: read before it, the name
        // was empty whenever no earlier test had started one.
        $session->getFingerprint();
        $ref     = new \ReflectionObject($session);
        $name    = (string) $ref->getProperty('_token')->getValue($session);

        foreach ([
            'form token'        => fn () => $this->post([$name => $session->getFingerprint()]),
            'synchronizer field' => fn () => $this->post(['_csrf_token' => $session->getCsrfToken()]),
            'header'            => function () use ($session): void {
                $this->post();
                $_SERVER['HTTP_X_CSRF_TOKEN'] = $session->getCsrfToken();
            },
        ] as $kind => $arrange) {
            // Arrange
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            $arrange();
            $controller = $this->controller();

            // Act
            $result = $controller->exec('wipe');

            // Assert
            $this->assertSame('wiped', $result, $kind . ' must be accepted');
        }
    }

    /** An action not declared a write is unaffected, `GET` included. */
    public function testAnUndeclaredActionIsUnaffected(): void
    {
        // Act + Assert
        $this->assertSame('shown', $this->controller()->exec('show'));
    }

    /** A script is refused with `403` and JSON rather than a redirect. */
    public function testAScriptGetsA403(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->post();
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        \Pramnos\Document\Document::reset();

        // Act
        $controller->exec('wipe');

        // Assert
        $this->assertFalse($controller->ran);
        $this->assertNull($controller->redirectedTo);
        $this->assertStringContainsString('could not be verified', (string) \Pramnos\Framework\Factory::getDocument('raw')->render());
    }

    /**
     * The views' side: a POST form with the session's token and the look of the link it replaces.
     */
    public function testPostButtonsCarryTheToken(): void
    {
        // Act
        $icon    = Icon::postButton('/admin/users/delete/5', 'delete', 'Delete', ['data-confirm' => 'Sure?']);
        $control = Icon::postControl('/admin/users/lock/5', 'Lock <b>now</b>', ['class' => 'btn']);

        // Assert
        foreach ([$icon, $control] as $html) {
            $this->assertStringContainsString('<form method="post"', $html);
            $this->assertStringContainsString(\Pramnos\Http\Session::getInstance()->getTokenField(), $html);
        }
        $this->assertStringContainsString('data-confirm="Sure?"', $icon);
        $this->assertStringContainsString('aria-label="Delete"', $icon);
        $this->assertStringContainsString('<button type="submit" class="btn">Lock <b>now</b></button>', $control);
    }
}
