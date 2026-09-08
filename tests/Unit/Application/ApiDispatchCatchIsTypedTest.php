<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

/**
 * A broken endpoint must not report as a missing one.
 *
 * WHAT: `Api::exec()`'s controller-dispatch `catch` names
 *       `ControllerNotFoundException`, not `\Exception`.
 *
 * WHY:  `getController()` does more than look up a class name — it **instantiates** one.
 *       So a broad catch also covered a constructor that throws: a missing dependency, a
 *       failed database connection, a bad configuration read at construction time. All of
 *       those became `{"status":404,"error":"EndpointNotFound"}`.
 *
 *       That is worse than the fatal it replaced, and worse in the way that matters: a 500
 *       gets looked at, and a 404 on a path the client mistyped gets ignored. An endpoint
 *       that had broken *itself* reported as an endpoint that does not exist,
 *       indefinitely, and that path did not log.
 *
 * Read from the source rather than executed, and the precedent is
 * {@see \Pramnos\Tests\Unit\Framework\ApplicationFactoryPurityTest}. Reaching that `catch`
 * behaviourally means standing up everything `exec()` does before it — the migration check,
 * a four-stage middleware pipeline and API authentication, all of which want a database —
 * to observe one `catch` clause. The type being *thrown* is covered behaviourally by
 * {@see ControllerNotFoundCarriesNoUsernameTest}; this is what stops the catch widening
 * back, which is the failure mode that matters, because `\Exception` is the one you reach
 * for when a test goes red.
 */
class ApiDispatchCatchIsTypedTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Pramnos/Application/Api.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The dispatch catch is the typed one.
     *
     * Anchored on the `getController()` call rather than searched for globally: `exec()`
     * has a second, deliberately broad `catch` around the *action*, and that one is right
     * to be broad — it feeds a handler that distinguishes a `ValidationException`, a 403
     * by code and a SQL error.
     */
    public function testTheDispatchCatchNamesTheControllerNotFoundException(): void
    {
        // Arrange
        $source = $this->source();
        $at = strpos($source, '$moduleObject = $this->getController($this->controller);');
        $this->assertNotFalse($at, 'the dispatch call moved; this test is anchored on it');

        // Act — the catch that immediately follows the dispatch
        $following = substr($source, $at, 400);

        // Assert
        $this->assertStringContainsString(
            'catch (\Pramnos\Application\ControllerNotFoundException',
            $following,
            'the dispatch catch must name the type, or a broken endpoint answers 404'
        );
        $this->assertStringNotContainsString(
            'catch (\Exception $exception) {',
            $following,
            'a broad catch here turns a failed constructor into "no such endpoint"'
        );
    }

    /**
     * And it logs, because it did not.
     *
     * A 404 nobody records is a 404 nobody investigates — which was the second half of why
     * a self-broken endpoint could report as missing indefinitely.
     */
    public function testTheDispatchCatchLeavesARecord(): void
    {
        // Arrange
        $source = $this->source();
        $at = strpos($source, '$moduleObject = $this->getController($this->controller);');

        // Act
        // 3000, because the catch carries the docblock explaining why it is typed.
        $following = substr($source, (int) $at, 3000);

        // Assert
        $this->assertStringContainsString('apinotfound', $following);
    }

    /**
     * `getController()` throws the type, and does not put the request in the message.
     *
     * The source half of the exposure: the two lines that appended `REQUEST_URI` and
     * `$_SESSION['user']->username` to an exception message are gone from the resolver.
     * Asserted here as well as behaviourally, because re-adding them is a one-line
     * convenience when somebody wants better logs and does not think about where the
     * message goes.
     */
    public function testTheResolverDoesNotBuildItsMessageFromTheRequest(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Assert
        $this->assertStringNotContainsString(
            "\$errorMessage .= \"\\n\"",
            $source,
            'the resolver is appending request context to an exception message again'
        );
        $this->assertStringContainsString(
            'throw new ControllerNotFoundException($controller, $context);',
            $source
        );
    }
}
