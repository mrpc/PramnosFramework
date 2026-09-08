<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\ControllerNotFoundException;

/**
 * A mistyped API path answered 500 with a trace naming the signed-in user.
 *
 * `getController()` appended the request URI and `$_SESSION['user']->username` to its
 * exception message, to help whoever read the log. It did help. But where an exception
 * message goes next is the caller's choice, and every unhandled path puts it somewhere it
 * should not be: an application that lets it escape renders a PHP error page carrying the
 * username, a debug toolbar or an exception reporter forwards it by design, `display_errors`
 * on a staging host turns it into a page.
 *
 * `POST /api/1.09/account/logout` — a typo for `1.0` — is how it was found. A mistyped path
 * is also the shape of a path being probed, so the audience for that message is not always
 * a colleague.
 *
 * The context is not dropped: it goes to the `controllernotfound` log, and to
 * {@see ControllerNotFoundException::getContext()} for a handler that has somewhere safe to
 * put it.
 */
#[CoversClass(Application::class)]
#[CoversClass(ControllerNotFoundException::class)]
class ControllerNotFoundCarriesNoUsernameTest extends TestCase
{
    private mixed $savedUri = null;

    private mixed $savedUser = null;

    protected function setUp(): void
    {
        $this->savedUri  = $_SERVER['REQUEST_URI'] ?? null;
        $this->savedUser = $_SESSION['user'] ?? null;

        $_SERVER['REQUEST_URI'] = '/api/1.09/account/logout';
        $_SESSION['user'] = (object) ['username' => 'evridiki.pantazi'];
    }

    protected function tearDown(): void
    {
        if ($this->savedUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->savedUri;
        }
        if ($this->savedUser === null) {
            unset($_SESSION['user']);
        } else {
            $_SESSION['user'] = $this->savedUser;
        }

        parent::tearDown();
    }

    /**
     * Resolve a controller name nothing answers to, and return what was thrown.
     */
    private function resolve(string $controller = 'nosuchcontroller'): ControllerNotFoundException
    {
        $app = new class extends Application {
            public function __construct()
            {
                // The real constructor reads configuration, connects and starts a session.
                // Resolution needs none of it.
            }
        };

        try {
            $app->getController($controller);
        } catch (ControllerNotFoundException $exception) {
            return $exception;
        }

        $this->fail('resolving an absent controller did not raise ControllerNotFoundException');
    }

    /**
     * The message names the controller and nothing else.
     *
     * The single assertion the report reduces to.
     */
    public function testTheMessageCarriesNeitherTheUrlNorTheUser(): void
    {
        // Act
        $message = $this->resolve()->getMessage();

        // Assert
        $this->assertSame('Cannot find controller: nosuchcontroller', $message);
        $this->assertStringNotContainsString('evridiki.pantazi', $message, 'the username leaked');
        $this->assertStringNotContainsString('1.09', $message, 'the request URI leaked');
    }

    /**
     * It is typed, which is what lets a caller answer 404 without reading text.
     *
     * A consumer that wanted to separate "no such route" from "that route is broken" had
     * only `strpos($e->getMessage(), 'Cannot find controller:')` to go on — the right shape
     * and the wrong mechanism, brittle *because* there was no type.
     */
    public function testItIsTypedAndStillAnException(): void
    {
        // Act
        $exception = $this->resolve();

        // Assert
        $this->assertInstanceOf(ControllerNotFoundException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception, 'an existing catch must still catch it');
        $this->assertSame('nosuchcontroller', $exception->getController());
    }

    /**
     * The context is still there, for a handler with somewhere safe to put it.
     *
     * Without this the change would trade an exposure for a blind spot: the URI and the
     * user are what turn "a 404 happened" into "this account hit this path".
     */
    public function testTheContextIsAvailableToAHandler(): void
    {
        // Act
        $context = $this->resolve()->getContext();

        // Assert
        $this->assertSame('/api/1.09/account/logout', $context['url'] ?? null);
        $this->assertSame('evridiki.pantazi', $context['user'] ?? null);
    }

    /**
     * With nothing signed in and no request URI, the context is simply empty.
     *
     * The CLI case, and the shape a first version of this got wrong by reading
     * `$_SESSION['user']->username` on a session that has an array there.
     */
    public function testAnAnonymousCliResolutionHasNoContext(): void
    {
        // Arrange
        unset($_SERVER['REQUEST_URI'], $_SESSION['user']);

        // Act
        $context = $this->resolve()->getContext();

        // Assert
        $this->assertSame([], $context);
    }

    /**
     * A session whose `user` is not an object is ignored rather than fatal.
     *
     * `$_SESSION['user']` is application-owned and an application may put anything there;
     * the original code guarded with `is_object()` and this keeps that guarantee.
     */
    public function testASessionUserThatIsNotAnObjectIsIgnored(): void
    {
        // Arrange
        $_SESSION['user'] = ['username' => 'array-shaped'];

        // Act
        $exception = $this->resolve();

        // Assert
        $this->assertArrayNotHasKey('user', $exception->getContext());
        $this->assertStringNotContainsString('array-shaped', $exception->getMessage());
    }
}
