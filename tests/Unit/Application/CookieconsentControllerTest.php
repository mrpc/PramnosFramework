<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Cookieconsent as ConsentController;

/**
 * `/cookieconsent/record` is a write, and is refused without a CSRF token.
 *
 * The endpoint appends to a legal record. Were it reachable by a cross-site POST, any page
 * a signed-in visitor opened could write "agreed to marketing" into their trail. The
 * controller declares it with `addWriteAction()`, so `exec()` refuses it before the action
 * runs; this pins that the declaration is there. The database half is in
 * {@see \Pramnos\Tests\Integration\Security\CookieConsentRecordTest}.
 */
#[CoversClass(ConsentController::class)]
class CookieconsentControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = [];
    }

    /**
     * A POST with no token never reaches `record()`: the refusal is JSON, because the
     * banner asks for JSON, and says nothing was changed.
     */
    public function testRecordWithoutACsrfTokenIsRefused(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = [];
        $controller = new class (null) extends ConsentController {
            public bool $reached = false;

            public function record(): mixed
            {
                $this->reached = true;

                return null;
            }
        };

        // Act
        $controller->exec('record');

        // Assert
        $this->assertFalse($controller->reached, 'the action ran without a verified token');
        $this->assertContains('record', (new \ReflectionProperty($controller, 'writeActions'))->getValue($controller));
    }

    /**
     * With nobody signed in the user id is the guest's (0 or 1) — which `record()`
     * turns into a 401 — and outside a web request the body is empty, not an error.
     */
    public function testTheHelpersAnswerForAnAnonymousCliRequest(): void
    {
        // Arrange
        $controller = new ConsentController(null);

        // Act
        $userId = (new \ReflectionMethod($controller, 'currentUserId'))->invoke($controller);
        $body = (new \ReflectionMethod($controller, 'rawBody'))->invoke($controller);

        // Assert
        $this->assertLessThanOrEqual(1, $userId);
        $this->assertSame('', $body);
    }
}
