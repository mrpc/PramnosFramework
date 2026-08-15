<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\ValidationRedirectMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Validation\ValidationException;

/**
 * A router-dispatched application can have the validation redirect too.
 *
 * The Validation guide presents flash-and-redirect as something the framework does:
 * a `ValidationException` puts the errors and the submitted input in the session and
 * sends the browser back, so the form redraws itself with both. That is accurate, and
 * it lives **inside `Application::exec()`** — which an application routing through
 * `Router::dispatch()` never calls. Those applications got an uncaught exception
 * where the guide promised a redirect.
 *
 * The same shape as `ApiDebugMiddleware`: a capability locked inside the MVC kernel,
 * one line away from being reachable outside it.
 *
 * The session keys matter more than they look. This writes `_validation_errors` and
 * `_old_input` — what `View::__construct()` exposes as `$this->errors` and what
 * `Request::old()` reads. `FormRequest::failWith()` writes a different pair, so a
 * view using `$this->errors` sees nothing after a `FormRequest` failure. These tests
 * pin the pair, because getting it wrong produces a form that silently redraws empty.
 */
class ValidationRedirectMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> Session contents before a test */
    private array $sessionBackup = [];

    /** @var string|null The referer the environment had */
    private ?string $refererBackup = null;

    /**
     * Remembers the session and referer these tests write.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $this->sessionBackup = $_SESSION ?? [];
        $this->refererBackup = $_SERVER['HTTP_REFERER'] ?? null;
        unset($_SESSION['_validation_errors'], $_SESSION['_old_input']);
    }

    /**
     * Restores both.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        if ($this->refererBackup === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $this->refererBackup;
        }
    }

    /**
     * Runs the middleware around an action that fails validation.
     *
     * @param  ValidationRedirectMiddleware $middleware The middleware
     * @return mixed
     */
    private function dispatchFailing(ValidationRedirectMiddleware $middleware)
    {
        return $middleware->handle(
            new Request(),
            static function (): never {
                throw new ValidationException(['email' => ['The email is required.']]);
            }
        );
    }

    /**
     * A passing request goes through untouched.
     *
     * The middleware must be invisible on the path it does not exist for — which is
     * every request that validates.
     *
     * @return void
     */
    public function testAPassingRequestIsUntouched(): void
    {
        // Act
        $result = (new ValidationRedirectMiddleware())->handle(
            new Request(),
            static fn (): string => 'the action ran'
        );

        // Assert
        $this->assertSame('the action ran', $result);
        $this->assertArrayNotHasKey('_validation_errors', $_SESSION);
    }

    /**
     * A failure becomes a redirect rather than an uncaught exception.
     *
     * @return void
     */
    public function testAFailureBecomesARedirect(): void
    {
        // Arrange
        $_SERVER['HTTP_REFERER'] = 'https://example.com/signup';

        // Act
        $result = $this->dispatchFailing(new ValidationRedirectMiddleware());

        // Assert
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(
            'https://example.com/signup',
            $result->getHeaderLine('Location'),
            'The visitor goes back to the form they submitted.'
        );
    }

    /**
     * The errors land under the key the `View` reads.
     *
     * `_validation_errors`, not `_form_errors`. A view using `$this->errors` reads
     * the first and never the second, so choosing the wrong one here produces a form
     * that redraws with no errors on it and no way to tell why.
     *
     * @return void
     */
    public function testTheErrorsLandWhereTheViewLooksForThem(): void
    {
        // Act
        $this->dispatchFailing(new ValidationRedirectMiddleware());

        // Assert
        $this->assertArrayHasKey('_validation_errors', $_SESSION);
        $this->assertSame(
            ['email' => ['The email is required.']],
            $_SESSION['_validation_errors']
        );
        $this->assertArrayHasKey(
            '_old_input',
            $_SESSION,
            'Without the old input the form redraws empty and the visitor retypes it.'
        );
    }

    /**
     * An explicit target beats the referer.
     *
     * For a form that has one address it should always return to — and because it
     * removes the referer-less case entirely.
     *
     * @return void
     */
    public function testAnExplicitTargetIsUsed(): void
    {
        // Arrange
        $_SERVER['HTTP_REFERER'] = 'https://example.com/somewhere-else';

        // Act
        $result = $this->dispatchFailing(new ValidationRedirectMiddleware('/register'));

        // Assert
        $this->assertSame('/register', $result->getHeaderLine('Location'));
    }

    /**
     * With no referer at all it falls back rather than redirecting nowhere.
     *
     * Some privacy tooling strips `Referer`. `Application::exec()` has always fallen
     * back to `URL` here; this documents the same behaviour rather than inventing a
     * different one, and the constructor argument exists for anybody who wants the
     * case gone.
     *
     * @return void
     */
    public function testWithoutARefererItStillRedirectsSomewhere(): void
    {
        // Arrange
        unset($_SERVER['HTTP_REFERER']);

        // Act
        $result = $this->dispatchFailing(new ValidationRedirectMiddleware());

        // Assert
        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotSame(
            '',
            (string) $result->getHeaderLine('Location'),
            'A redirect with no location is worse than the exception it replaced.'
        );
    }

    /**
     * Anything that is not a validation failure passes straight through.
     *
     * A middleware that swallowed other exceptions would turn a real fault into a
     * redirect to the form, which is the most confusing possible outcome: the visitor
     * sees the page again with nothing wrong on it.
     *
     * @return void
     */
    public function testOtherExceptionsAreNotCaught(): void
    {
        // Arrange
        $this->expectException(\RuntimeException::class);

        // Act
        (new ValidationRedirectMiddleware())->handle(
            new Request(),
            static function (): never {
                throw new \RuntimeException('the database is on fire');
            }
        );
    }
}
