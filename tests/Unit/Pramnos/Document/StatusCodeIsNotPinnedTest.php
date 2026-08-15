<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Json;
use Pramnos\Document\DocumentTypes\Rss;
use Pramnos\Framework\Factory;

/**
 * Rendering a document must not decide the response status.
 *
 * `Json::render()` and `Rss::render()` both opened with
 * `header('HTTP/1.1 200 OK')`, which is harmful twice over:
 *
 *   - **It stamps 200 over a status the caller already set.** An API returning 404,
 *     403 or 500 renders its body through `Json::render()`, so the error was served
 *     as `200 OK` and the client could not tell failure from success by status. For
 *     an SPA that checks `response.ok` — which is what `fetch` gives you — every
 *     error looked like a success carrying strange data.
 *   - **It pins the status.** PHP ignores every later `http_response_code()` once a
 *     status line has been written by hand, and says so only as a warning nobody
 *     reads in production. That is how this was found: an unrelated fix added an
 *     `http_response_code()` call and PHP reported it had no effect.
 *
 * 200 is PHP's default, so the line was a no-op in the one case where it was right.
 *
 * One test here is behavioural and fails when the fix is reverted. The other is
 * structural, because the behavioural version of it **cannot be written**: PHP's
 * "a status line was sent by hand" flag is global to the process with no way to
 * clear it, so the first test to trip it decides the answer for every test after.
 * Two such tests were written first and both passed with the defect present.
 */
class StatusCodeIsNotPinnedTest extends TestCase
{
    /**
     * Gives the language the charset key both renderers ask for.
     *
     * @return void
     */
    protected function setUp(): void
    {
        Factory::getLanguage()->addlang(['CHARSET' => 'UTF-8']);
        Document::_setContent('');
        http_response_code(200);
    }

    /**
     * Restores a clean status for whatever runs next.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Document::_setContent('');
        http_response_code(200);
    }

    /**
     * A JSON document does not overwrite an error status the caller set.
     *
     * The headline case: an API that decided this request is a 404 must still be a
     * 404 after its body is rendered.
     *
     * @return void
     */
    public function testJsonDoesNotOverwriteAnErrorStatus(): void
    {
        // Arrange
        http_response_code(404);
        Document::_setContent('{"error":"not found"}');

        // Act
        (new Json())->render();

        // Assert
        $this->assertSame(
            404,
            http_response_code(),
            'Rendering the body must not turn a 404 into a 200.'
        );
    }

    /**
     * No document renderer writes a status line by hand.
     *
     * This one is structural rather than behavioural, and deliberately so. The
     * runtime version — render, then set a code, then check it applied — **cannot be
     * written here**: PHP's "a status line was sent by hand" flag is global to the
     * process and there is no way to clear it, so the first test to trip it changes
     * the answer for every test after it. A pair of such tests passed with the defect
     * present, in the order PHPUnit happened to run them.
     *
     * So this asserts the construction instead of observing the effect: no renderer
     * emits `header('HTTP/...')`. That covers `Rss` and every renderer added later,
     * which the behavioural test never could — a second file with a copy of the same
     * code is exactly where a fix applied once goes missing.
     *
     * @return void
     */
    public function testNoDocumentTypeWritesAStatusLineByHand(): void
    {
        // Arrange
        $dir     = dirname(__DIR__, 4) . '/src/Pramnos/Document/DocumentTypes';
        $files   = glob($dir . '/*.php') ?: [];
        $offend  = [];

        // A guard that scans nothing passes. The path above is four levels up and
        // was five while this was being written, which found no files at all and
        // reported success — the failure mode this project has now hit from both
        // sides. The authority for "there is something to check" has to come from
        // outside the loop doing the checking.
        $this->assertGreaterThan(
            5,
            count($files),
            'No document types were scanned — the path is wrong, not the code clean.'
        );

        // Act
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Only real calls: the explanatory comments in these files name the very
            // string being banned, and matching those would make the guard unfixable.
            $withoutComments = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);
            if (preg_match('#header\s*\(\s*[\'"]HTTP/#i', $withoutComments)) {
                $offend[] = basename($file);
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offend,
            'A hand-written status line stamps 200 over the caller\'s status and '
            . 'pins it against every later http_response_code(). Use '
            . 'http_response_code() instead.'
        );
    }

    /**
     * A successful render still leaves the default 200 in place.
     *
     * The guard against the obvious over-correction: removing the status line must
     * not mean a normal response loses its status.
     *
     * @return void
     */
    public function testANormalRenderIsStillTwoHundred(): void
    {
        // Arrange
        Document::_setContent('{"ok":true}');

        // Act
        (new Json())->render();

        // Assert
        $this->assertSame(200, http_response_code());
    }
}
