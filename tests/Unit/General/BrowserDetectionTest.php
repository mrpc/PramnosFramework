<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\General\Helpers;

/**
 * Reading a user agent on a server with no browscap file.
 *
 * `get_browser()` needs the `browscap` ini directive to point at a browscap.ini.
 * That directive is unset by default and almost nobody sets it, so the call
 * could not succeed — it raised a warning and returned false, on every request
 * that logged a user agent. The warning was suppressed with `@`, and the
 * toolbar's error handler reported it anyway, which is how it became visible.
 *
 * Both halves are fixed: the handler respects `@`, and this asks before calling.
 */
#[CoversClass(Helpers::class)]
class BrowserDetectionTest extends TestCase
{
    /** A current Chrome on Windows. */
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /**
     * With no browscap configured, nothing is raised and an answer still comes back.
     *
     * The default state of a PHP installation, and therefore the case that
     * matters. The assertion is that no diagnostic is produced at all — which
     * is what a converted error handler would turn into an exception.
     */
    public function testItIsQuietWithoutBrowscap(): void
    {
        // Arrange — treat any diagnostic as a failure, the way the toolbar's
        // handler effectively did
        $raised = [];
        set_error_handler(static function (int $no, string $message) use (&$raised): bool {
            $raised[] = $message;
            return true;
        });

        try {
            // Act
            $browser = Helpers::getBrowser(self::CHROME);
        } finally {
            restore_error_handler();
        }

        // Assert
        $this->assertSame([], $raised, 'nothing was raised: ' . implode('; ', $raised));
        $this->assertIsObject($browser);
    }

    /**
     * The user agent is still parsed, so the caller loses nothing.
     *
     * Skipping `get_browser()` would be no good if it meant skipping the
     * answer; the fallback parsing was always there and is what runs.
     */
    public function testTheAgentIsStillIdentified(): void
    {
        // Act
        $browser = Helpers::getBrowser(self::CHROME);

        // Assert
        $this->assertSame('chrome', $browser->browser);
        $this->assertSame(self::CHROME, $browser->userAgent);
    }

    /**
     * Every field the caller reads is present, whichever path produced it.
     *
     * `Token::addAction()` stores this object; a missing key there is a notice
     * on every logged request.
     */
    public function testTheShapeIsCompleteEitherWay(): void
    {
        // Act
        $browser = Helpers::getBrowser(self::CHROME);

        // Assert
        foreach (['userAgent', 'browser', 'version', 'platform', 'majorver', 'os_number', 'engine'] as $field) {
            $this->assertObjectHasProperty($field, $browser, $field . ' is missing');
        }
    }

    /**
     * An empty or unknown agent is answered, not refused.
     *
     * A request with no `User-Agent` header is ordinary — a health check, a
     * script — and must not be the one that raises.
     */
    public function testAnUnknownAgentIsAnswered(): void
    {
        // Act
        $empty   = Helpers::getBrowser('');
        $unknown = Helpers::getBrowser('SomeBot/1.0');

        // Assert
        $this->assertSame('', $empty->browser);
        $this->assertSame('', $unknown->browser);
        $this->assertSame('SomeBot/1.0', $unknown->userAgent);
    }

    /**
     * The browscap check reflects the ini directive.
     *
     * If this ever answered true on a server without the file, the call would
     * come back — along with the warning on every request.
     */
    public function testTheBrowscapCheckFollowsTheIniDirective(): void
    {
        // Arrange
        $method = new \ReflectionMethod(Helpers::class, 'browscapConfigured');

        // Act
        $configured = $method->invoke(null);
        $directive  = ini_get('browscap');

        // Assert
        $this->assertSame(
            is_string($directive) && trim($directive) !== '',
            $configured
        );
    }
}
