<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\ExceptionHandler;
use Pramnos\Http\Response;

#[CoversClass(ExceptionHandler::class)]
class ExceptionHandlerTest extends TestCase
{
    // =========================================================================
    // httpStatus() — status code mapping
    // =========================================================================

    /**
     * A valid 4xx exception code (e.g. 404) must be preserved as the HTTP
     * status in the rendered Response.  Mapping to 500 for client errors
     * would be wrong and mislead monitoring tools.
     */
    public function testRenderPreserves4xxCodeFromException(): void
    {
        // Arrange
        $exception = new \Exception('Not found', 404);

        // Act
        $response = ExceptionHandler::render($exception, 'html', false);

        // Assert
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A valid 5xx exception code (e.g. 503) must be preserved.
     */
    public function testRenderPreserves5xxCodeFromException(): void
    {
        // Arrange
        $exception = new \Exception('Maintenance', 503);

        // Act
        $response = ExceptionHandler::render($exception, 'html', false);

        // Assert
        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * Exception code 0 (the PHP default for new \Exception('msg')) must
     * map to 500.  Code 0 is not a valid HTTP status; defaulting to 500
     * avoids sending a "0 OK"-like response to the browser.
     */
    public function testRenderMapsCodeZeroTo500(): void
    {
        // Arrange — default PHP exception has code 0
        $exception = new \Exception('Something went wrong');

        // Act
        $response = ExceptionHandler::render($exception);

        // Assert
        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * A code below 400 (e.g. 200, 301) is not a valid error status and
     * must also fall back to 500.
     */
    public function testRenderMapsNonHttpErrorCodeTo500(): void
    {
        // Arrange
        $exception = new \Exception('Weird code', 200);

        // Act
        $response = ExceptionHandler::render($exception);

        // Assert
        $this->assertSame(500, $response->getStatusCode());
    }

    // =========================================================================
    // render() — HTML format
    // =========================================================================

    /**
     * render() with format='html' must return a Response instance (not throw).
     * The contract that render() always returns a Response — even for unusual
     * exception types — is what makes Application::exec() safe to simplify.
     */
    public function testRenderHtmlReturnsResponseInstance(): void
    {
        // Arrange / Act
        $response = ExceptionHandler::render(new \RuntimeException('oops'), 'html', false);

        // Assert
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Production HTML response must NOT contain a stack trace.
     * Leaking internal file paths or class names in production is a security
     * risk and confuses end users.
     */
    public function testRenderHtmlProductionDoesNotContainTrace(): void
    {
        // Arrange
        $exception = new \Exception('Boom', 500);

        // Act
        $response = ExceptionHandler::render($exception, 'html', false);

        // Assert — trace marker (#0, #1, …) must be absent
        $this->assertStringNotContainsString('#0', $response->getBody());
        $this->assertStringNotContainsString(get_class($exception), $response->getBody());
    }

    /**
     * Production HTML response must contain the HTTP status code so the
     * user has some context about what went wrong.
     */
    public function testRenderHtmlProductionContainsStatusCode(): void
    {
        // Arrange
        $exception = new \Exception('Server error', 500);

        // Act
        $response = ExceptionHandler::render($exception, 'html', false);

        // Assert
        $this->assertStringContainsString('500', $response->getBody());
    }

    /**
     * Debug HTML response must include the exception class, message, file,
     * and stack trace so the developer can diagnose the problem without
     * opening any other tool.
     */
    public function testRenderHtmlDebugContainsTrace(): void
    {
        // Arrange
        $exception = new \RuntimeException('Debug message', 500);

        // Act
        $response = ExceptionHandler::render($exception, 'html', true);

        $body = $response->getBody();

        // Assert — all key diagnostics present
        $this->assertStringContainsString('RuntimeException', $body);
        $this->assertStringContainsString('Debug message', $body);
        $this->assertStringContainsString('#0', $body);  // trace lines
    }

    /**
     * Debug HTML output must be HTML-escaped.  Unescaped exception messages
     * could inject arbitrary markup (XSS via error messages).
     */
    public function testRenderHtmlDebugEscapesMessageForXss(): void
    {
        // Arrange — message contains HTML-special characters
        $exception = new \Exception('<script>alert(1)</script>', 500);

        // Act
        $body = ExceptionHandler::render($exception, 'html', true)->getBody();

        // Assert — raw <script> tag must not appear verbatim
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /**
     * Friendly HTML pages for well-known 4xx/5xx codes must include
     * a human-readable title so the user understands the error type.
     */
    public function testRenderHtmlProductionFriendlyTitlesForKnownCodes(): void
    {
        // Arrange/Act/Assert for a selection of standard codes
        $cases = [
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
        ];

        foreach ($cases as $code => $expectedTitle) {
            $response = ExceptionHandler::render(new \Exception('x', $code), 'html', false);
            $this->assertStringContainsString(
                $expectedTitle,
                $response->getBody(),
                "Friendly title missing for HTTP {$code}"
            );
        }
    }

    // =========================================================================
    // render() — JSON format
    // =========================================================================

    /**
     * render() with format='json' must set Content-Type: application/json.
     * Without this header, API clients will treat the body as plain text and
     * may fail to parse it.
     */
    public function testRenderJsonSetsContentTypeHeader(): void
    {
        // Arrange / Act
        $response = ExceptionHandler::render(new \Exception('fail', 422), 'json', false);

        // Assert
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Production JSON envelope must contain 'error' and 'code' keys and
     * must NOT contain debug fields (exception class, file, trace).
     */
    public function testRenderJsonProductionEnvelopeStructure(): void
    {
        // Arrange
        $exception = new \Exception('Resource not found', 404);

        // Act
        $payload = json_decode(
            ExceptionHandler::render($exception, 'json', false)->getBody(),
            true
        );

        // Assert — required fields
        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayHasKey('code', $payload);
        $this->assertSame('Resource not found', $payload['error']);
        $this->assertSame(404, $payload['code']);

        // Assert — debug fields must be absent in production
        $this->assertArrayNotHasKey('exception', $payload);
        $this->assertArrayNotHasKey('trace', $payload);
    }

    /**
     * Debug JSON envelope must include 'exception', 'file', 'line', and
     * 'trace' so the developer can diagnose without switching to server logs.
     */
    public function testRenderJsonDebugEnvelopeContainsDebugFields(): void
    {
        // Arrange
        $exception = new \RuntimeException('DB connection failed', 500);

        // Act
        $payload = json_decode(
            ExceptionHandler::render($exception, 'json', true)->getBody(),
            true
        );

        // Assert — debug fields present
        $this->assertArrayHasKey('exception', $payload);
        $this->assertArrayHasKey('file', $payload);
        $this->assertArrayHasKey('line', $payload);
        $this->assertArrayHasKey('trace', $payload);
        $this->assertSame('RuntimeException', $payload['exception']);
        $this->assertIsArray($payload['trace']);
    }

    /**
     * JSON format defaults: render() with no $format argument defaults to
     * 'html', not 'json' — caller must opt-in to JSON. This ensures HTML
     * apps don't accidentally get JSON responses.
     */
    public function testRenderDefaultFormatIsHtml(): void
    {
        // Arrange / Act
        $response = ExceptionHandler::render(new \Exception('err'));

        // Assert — no JSON Content-Type on default call
        $this->assertNull($response->getHeaderLine('Content-Type'));
    }

    // =========================================================================
    // detectFormat()
    // =========================================================================

    /**
     * When the Accept header explicitly prefers JSON (and does not include
     * text/html), detectFormat() returns 'json'.
     */
    public function testDetectFormatReturnsJsonWhenAcceptIsJsonOnly(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        // Act
        $format = ExceptionHandler::detectFormat();

        // Assert
        $this->assertSame('json', $format);

        // Cleanup
        unset($_SERVER['HTTP_ACCEPT']);
    }

    /**
     * When the Accept header includes both text/html and application/json
     * (typical browser request), detectFormat() returns 'html'.
     * Browser requests must never receive a bare JSON error page.
     */
    public function testDetectFormatReturnsHtmlWhenAcceptIncludesHtml(): void
    {
        // Arrange — typical browser Accept header
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/json;q=0.9';

        // Act
        $format = ExceptionHandler::detectFormat();

        // Assert
        $this->assertSame('html', $format);

        // Cleanup
        unset($_SERVER['HTTP_ACCEPT']);
    }

    /**
     * When HTTP_ACCEPT is absent (CLI, raw TCP), detectFormat() defaults to
     * 'html' — a safe conservative choice.
     */
    public function testDetectFormatDefaultsToHtmlWhenNoAcceptHeader(): void
    {
        // Arrange
        unset($_SERVER['HTTP_ACCEPT']);

        // Act / Assert
        $this->assertSame('html', ExceptionHandler::detectFormat());
    }

    // =========================================================================
    // log() — smoke test
    // =========================================================================

    /**
     * log() must not throw for any \Throwable type — even for exceptions
     * with unusual messages (e.g. empty string, special characters).
     * A handler that itself throws during error logging is catastrophic.
     */
    public function testLogDoesNotThrowForAnyThrowable(): void
    {
        // Arrange — cover both Exception and Error subtypes
        $cases = [
            new \Exception(''),
            new \RuntimeException('msg with <html> & "quotes"', 500),
            new \LogicException('logic error', 0),
            new \Error('Fatal error'),
        ];

        // Act / Assert — must not throw for any of them
        foreach ($cases as $throwable) {
            try {
                ExceptionHandler::log($throwable);
                $this->assertTrue(true);  // reached here without throwing
            } catch (\Throwable $e) {
                $this->fail('log() threw for ' . get_class($throwable) . ': ' . $e->getMessage());
            }
        }
    }
}
