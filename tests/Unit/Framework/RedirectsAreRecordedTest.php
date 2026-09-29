<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Every redirect the framework issues goes through `Application::redirect()`.
 *
 * That method records the destination (`getRedirect()`) and, under a test runner, ends the
 * request with an `ApplicationClosedException` a `TestClient` answers as a redirect. A bare
 * `header('Location: …')` does neither: on the CLI PHP keeps no headers at all, so the test
 * sees a 302 with no `Location`, or — where the header was followed by `exit` — the whole
 * PHPUnit process stops.
 *
 * It was found from an application: the authorization endpoint redirected with the code by
 * `header()`, so a test of the one flow an authentication server exists for could not read
 * the code it had just been issued.
 */
class RedirectsAreRecordedTest extends TestCase
{
    /**
     * Files allowed to send `Location` themselves, and why.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // The one place a redirect is performed.
        'Pramnos/Application/Application.php' => 'Application::redirect() itself',
        // Middleware stops a request by returning null, which is MiddlewareInterface's
        // contract; it has no request to close, and TestClient does not run the pipeline.
        'Pramnos/Http/Middleware/RequireFactorEnrolmentMiddleware.php' => 'returns null by contract',
    ];

    /**
     * No framework code sends a `Location` header of its own.
     *
     * An exception has to be listed above with its reason, so a new one is a decision somebody
     * wrote down rather than a redirect nothing can see.
     */
    public function testNoRedirectBypassesTheApplication(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3) . '/src/';
        $offenders = [];
        $scanned = 0;

        // Act
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $relative = substr($file->getPathname(), strlen($root));
            if (isset(self::ALLOWED[$relative])) {
                continue;
            }
            // A call to the global `header()` with a Location — not `->header('Location')`,
            // which is the HTTP client reading a response.
            if (preg_match('/(?<![>:\w$])header\s*\(\s*[\'"]Location\s*:/i', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }

        // Assert
        // A sweep over nothing passes, so the sweep is asserted to have found something.
        $this->assertGreaterThan(100, $scanned, 'the sweep found nothing to check');
        $this->assertSame([], $offenders,
            'these send Location with header(); use $this->redirect() / Application::redirect()');
    }

    /**
     * Every listed exception still exists and still sends `Location` itself.
     *
     * An entry left behind after its file is fixed or moved would quietly exempt whatever
     * takes its place.
     */
    public function testEveryExceptionIsStillNeeded(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3) . '/src/';
        $this->assertNotEmpty(self::ALLOWED, 'the sweep found nothing to check');

        foreach (array_keys(self::ALLOWED) as $relative) {
            // Act
            $source = (string) @file_get_contents($root . $relative);

            // Assert
            $this->assertMatchesRegularExpression('/header\s*\(\s*[\'"]Location\s*:/i', $source,
                "$relative is listed as sending Location itself and no longer does");
        }
    }
}
