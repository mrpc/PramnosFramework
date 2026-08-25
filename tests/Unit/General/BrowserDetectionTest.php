<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\General\Helpers;

/**
 * Reading a user agent, with and without a parser under the method.
 *
 * `get_browser()` needs the `browscap` ini directive to point at a browscap.ini.
 * That directive is unset by default and almost nobody sets it, so the call
 * could not succeed — it raised a warning and returned false, on every request
 * that logged a user agent. The warning was suppressed with `@`, and the
 * toolbar's error handler reported it anyway, which is how it became visible.
 *
 * Both halves are fixed: the handler respects `@`, and this asks before calling.
 *
 * That left a second problem, filed later as FW-017 with numbers behind it. The
 * fallback — {@see Helpers::get_user_browser()} — returns a **name and nothing else**,
 * so the method answered with one useful field out of six on almost every installation,
 * and answered as a perfectly valid object nobody could interrogate. A consuming
 * application measured it: 3,040 visits with a browser name, 771 with an OS or engine.
 *
 * `matomo/device-detector` fills all six when installed. It is a `suggest`, so both
 * paths have to keep working — which is why the fallback is tested through
 * {@see HelpersWithoutParser} rather than by hoping the package is absent.
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
        // Act — the fallback explicitly, since the parser is installed in this repo.
        $browser = HelpersWithoutParser::getBrowser(self::CHROME);

        // Assert
        $this->assertSame('chrome', $browser->browser);
        $this->assertSame(self::CHROME, $browser->userAgent);
        $this->assertSame('sniff', $browser->detector, 'and it says which engine answered');
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
        foreach (['userAgent', 'browser', 'version', 'platform', 'majorver', 'os_number', 'engine', 'detector'] as $field) {
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
        // Act — the fallback, which is the path with nothing to fall back to.
        $empty   = HelpersWithoutParser::getBrowser('');
        $unknown = HelpersWithoutParser::getBrowser('SomeBot/1.0');

        // Assert
        $this->assertSame('', $empty->browser);
        $this->assertSame('', $unknown->browser);
        $this->assertSame('SomeBot/1.0', $unknown->userAgent);
    }

    // ── With matomo/device-detector installed ───────────────────────────────

    /**
     * All six fields are filled, including the one nothing ever filled.
     *
     * `os_number` was hard-coded empty on the browscap path and empty on the fallback,
     * so it had never been populated by anything — a field that looked like it had been
     * declared and forgotten. It is the OS version, and device-detector has it.
     */
    public function testTheParserFillsEveryField(): void
    {
        // Act
        $browser = Helpers::getBrowser(self::CHROME);

        // Assert
        $this->assertSame('device-detector', $browser->detector);
        $this->assertSame('Chrome', $browser->browser);
        $this->assertSame('Windows', $browser->platform, 'platform is the OS, as browscap meant it');
        $this->assertSame('10', $browser->os_number, 'the OS version, which nothing filled before');
        $this->assertSame('Blink', $browser->engine);
        $this->assertNotSame('', $browser->version);
        $this->assertSame(
            explode('.', $browser->version)[0],
            $browser->majorver,
            'majorver is the major part of the version it reported, not a second guess'
        );
    }

    /**
     * `platform` is the operating system, not the CPU architecture.
     *
     * device-detector has a `platform` of its own and it means the architecture — x64,
     * ARM. Passing that through would have been the obvious mapping and would have
     * quietly changed what a public field means for every existing caller, none of whom
     * would report it as a bug.
     */
    public function testPlatformKeepsItsBrowscapMeaning(): void
    {
        // Act
        $browser = Helpers::getBrowser(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 '
            . '(KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'
        );

        // Assert
        $this->assertSame('iOS', $browser->platform);
        $this->assertSame('17.1', $browser->os_number);
        $this->assertNotSame('x64', $browser->platform);
    }

    /**
     * A crawler is named, and given no version it does not have.
     *
     * This object is written into a statistics table a row at a time. A fabricated
     * version for a bot is fiction in a column somebody will later average.
     */
    public function testACrawlerIsNamedAndNothingIsInvented(): void
    {
        // Act
        $bot = Helpers::getBrowser(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        );

        // Assert
        $this->assertSame('Googlebot', $bot->browser);
        $this->assertSame('', $bot->version);
        $this->assertSame('', $bot->engine);
        $this->assertSame('device-detector', $bot->detector);
    }

    /**
     * `detector` distinguishes "unknown browser" from "no parser".
     *
     * The field the filing asked for, and the whole reason it matters: an empty
     * `version` meant either of those, and they call for opposite responses — install a
     * package, or accept that this agent is not identifiable.
     */
    public function testDetectorSaysWhichEngineAnswered(): void
    {
        // Act
        $withParser    = Helpers::getBrowser(self::CHROME);
        $withoutParser = HelpersWithoutParser::getBrowser(self::CHROME);

        // Assert
        $this->assertSame('device-detector', $withParser->detector);
        $this->assertSame('sniff', $withoutParser->detector);

        // The point of the field: same empty-ish shape, different reason.
        $this->assertSame('', $withoutParser->version);
        $this->assertNotSame('', $withParser->version);
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

/**
 * `Helpers` with no user-agent parser underneath it.
 *
 * `matomo/device-detector` is a `require-dev` here, so it is always installed while the
 * suite runs — and the fallback is what most consuming installations will actually use.
 * Testing it by hoping the package is absent would mean the fallback is only covered on
 * machines where coverage is impossible to arrange.
 *
 * The override works because `getBrowser()` calls `static::detectWithDeviceDetector()`.
 * With `self::` it would bind to `Helpers`, the override would be ignored, and every
 * assertion below would silently measure the installed parser instead.
 */
class HelpersWithoutParser extends Helpers
{
    protected static function detectWithDeviceDetector(string $agent): ?object
    {
        return null;
    }
}
