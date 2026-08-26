<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;

/**
 * "Please sign in" must not be reported as an authorization error.
 *
 * `authorize()` wraps its work in `catch (\Exception)` and renders an
 * `Authorization Error` page from the message. That is right for an OAuth fault
 * and wrong for the framework's way of ending a request: sending a signed-out
 * visitor to the login form is the first step of the flow, not a failure of it.
 *
 * In production the redirect `exit`s, so nothing was caught and the bug was
 * invisible. Under test `close()` throws — and the catch swallowed it, rendering
 * `Authorization Error: Application::close() called with msg:` with an empty
 * message. So the sign-in redirect, the entry point to every authorization-code
 * flow this server serves, could not be tested at all.
 *
 * The escape hatch that existed was a string comparison against a message
 * (`'OAuth controller terminated'`) — somebody meeting this and working around the
 * one instance in front of them. Both signals have types now, and both are
 * rethrown ahead of the generic catch.
 *
 * The rethrow itself is asserted on the source. Reaching that line behaviourally
 * needs a signed-out request to get past parameter validation and a client
 * lookup, which is a database fixture for a two-line control-flow fact — and the
 * order of the catch blocks, which is the part that can silently regress, is
 * exactly what the source shows.
 */
class AuthorizeSignInRedirectTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 4) . '/src/Pramnos/Auth/Controllers/Oauth.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Both end-of-request signals are rethrown rather than rendered.
     */
    public function testTheEndOfRequestSignalsAreRethrown(): void
    {
        // Act
        $source = $this->source();

        // Assert
        $this->assertStringContainsString(
            'catch (\Pramnos\Application\ApplicationClosedException $ex) {',
            $source
        );
        $this->assertStringContainsString(
            'catch (\Pramnos\Http\RedirectException $ex) {',
            $source
        );
    }

    /**
     * And they are caught before the generic handler, or they never run.
     *
     * PHP takes the first matching catch. A specific block placed after
     * `catch (\Exception)` is dead code that reads as a fix.
     */
    public function testTheSignalsAreCaughtBeforeTheGenericHandler(): void
    {
        // Arrange
        $source = $this->source();

        // Act
        $closed = strpos($source, 'catch (\Pramnos\Application\ApplicationClosedException $ex) {');
        $redirect = strpos($source, 'catch (\Pramnos\Http\RedirectException $ex) {');
        $generic = strpos($source, "if (\$ex->getMessage() === 'OAuth controller terminated') {");

        // Assert
        $this->assertIsInt($closed);
        $this->assertIsInt($redirect);
        $this->assertIsInt($generic);
        $this->assertLessThan($generic, $closed, 'the close signal must be caught first');
        $this->assertLessThan($generic, $redirect, 'so must the redirect');
    }

    /**
     * An ordinary failure is still turned into the error page.
     *
     * Rethrowing everything would trade a swallowed redirect for an unhandled
     * exception on a page a visitor is looking at. A missing `client_id` fails
     * validation before anything touches the database, so this is the whole flow
     * with no fixture.
     */
    public function testAnOrdinaryFailureStillRendersTheErrorPage(): void
    {
        // Arrange
        $_GET = ['client_id' => '', 'response_type' => 'code'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = new Oauth(null);

        // Act — the error page is added to the document, not echoed
        \Pramnos\Document\Document::reset();
        ob_start();
        $controller->authorize();
        $echoed = (string) ob_get_clean();
        $output = (string) \Pramnos\Framework\Factory::getDocument()->render();

        // Assert
        $this->assertSame('', $echoed, 'the error page must not be echoed');
        $this->assertStringContainsString('Authorization Error', $output);
        $this->assertStringContainsString('Missing client_id', $output);
    }
}
