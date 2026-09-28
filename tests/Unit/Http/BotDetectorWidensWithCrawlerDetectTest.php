<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\BotDetector;

/**
 * A detector looking for a class that is not there.
 *
 * Which is what every installation that does not install the suggested library has. The
 * library is in `require-dev` so the suite exercises the path an application gets by
 * installing it — and that leaves the **absent** path, the common one, unreachable unless
 * something names a class that does not exist.
 */
class BuiltInOnlyBotDetector extends BotDetector
{
    protected function crawlerDetectClass(): string
    {
        return '\\No\\Such\\CrawlerDetect';
    }
}

/**
 * The built-in list keeps a session table tidy; counting visits needs more than it has.
 *
 * Thirty-five patterns, one of which is a generic `bot` — so the list catches far more than
 * that count suggests. SemrushBot, AhrefsBot, PetalBot, Bytespider and headless Chrome are
 * all already found, which is worth saying because it is the first thing anybody assumes is
 * missing. It was measured rather than assumed, and the data provider below is what was
 * left after measuring.
 *
 * **What it misses are the crawlers that do not say "bot"**: `python-requests`,
 * `Go-http-client`, `okhttp`, Scrapy, Zabbix, link unfurlers, `Google-InspectionTool`. Those
 * are also the ones most likely to be hammering a site.
 *
 * That is enough for what this class was extracted **for** — keeping crawlers out of the
 * session table — and not enough to count anything: a counter that lets them through
 * reports crawlers as readers, and the number stops being comparable with anything else
 * measuring the same site.
 *
 * **The symptom is a number that is slightly too high**, which nobody can see is wrong. A
 * hand-kept list cannot fix that: a new crawler appears every week and the day this one
 * stops covering them is a day nothing reports.
 *
 * So `jaybizzle/crawler-detect` — one compiled regular expression over about 1,500 crawlers,
 * maintained — is a `suggest`, used when the application installed it, exactly as
 * `Helpers::getBrowser()` uses `matomo/device-detector`.
 */
#[CoversClass(BotDetector::class)]
class BotDetectorWidensWithCrawlerDetectTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('\Jaybizzle\CrawlerDetect\CrawlerDetect')) {
            $this->fail(
                'jaybizzle/crawler-detect is in require-dev precisely so this suite '
                . 'exercises the path an application gets by installing it. A skip here '
                . 'would mean the widened behaviour is tested nowhere.'
            );
        }
    }

    /**
     * Crawlers the framework's own list has never heard of.
     *
     * Each of these is an ordinary visitor to the built-in patterns, and each would have
     * been counted as a reader.
     *
     * @param string $agent A real user-agent string
     */
    #[DataProvider('crawlersTheBuiltInListMisses')]
    public function testACrawlerTheBuiltInListMissesIsFound(string $agent): void
    {
        // Arrange
        $widened  = new BotDetector();
        $builtIn  = new BuiltInOnlyBotDetector();

        // Act + Assert — the point of the change, in both directions at once
        $this->assertTrue($widened->isBot($agent), 'the library did not catch it either');
        $this->assertFalse(
            $builtIn->isBot($agent),
            'the built-in list already caught this one, so it proves nothing'
        );
    }

    /**
     * Chosen by measuring rather than by guessing.
     *
     * The built-in list carries a generic `bot` pattern, so it catches far more than its
     * thirty-five names suggest — SemrushBot, AhrefsBot, PetalBot and Bytespider are all
     * already found, and putting them here would have proved nothing. **What it misses are
     * the crawlers that do not say "bot"**: HTTP libraries, monitoring agents, link
     * unfurlers and the ones that call themselves something else.
     *
     * Those are also the ones most likely to be hammering a site, which is why this is
     * worth more than the count of patterns implies.
     *
     * @return array<string, array{string}>
     */
    public static function crawlersTheBuiltInListMisses(): array
    {
        return [
            'python-requests'       => ['python-requests/2.31.0'],
            'Go http client'        => ['Go-http-client/1.1'],
            'okhttp'                => ['okhttp/4.12.0'],
            'Scrapy'                => ['Scrapy/2.11.0 (+https://scrapy.org)'],
            'Zabbix'                => ['Zabbix'],
            'WhatsApp unfurler'     => ['WhatsApp/2.23.20.0'],
            'Google-InspectionTool' => ['Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)'],
            'Turnitin'              => ['TurnitinBot (https://turnitin.com/robot/crawlerinfo.html)'],
        ];
    }

    /**
     * A person is still a person.
     *
     * The control, and the one that would be expensive to get wrong in the other
     * direction: a wider net that caught real browsers would undercount every site using
     * it, silently, and the number would look plausible.
     *
     * @param string $agent A real browser's user-agent string
     */
    #[DataProvider('realBrowsers')]
    public function testARealBrowserIsNotACrawler(string $agent): void
    {
        // Act + Assert
        $this->assertFalse((new BotDetector())->isBot($agent));
    }

    /** @return array<string, array{string}> */
    public static function realBrowsers(): array
    {
        return [
            'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'],
            'Safari on macOS'   => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'],
            'Firefox on Linux'  => ['Mozilla/5.0 (X11; Linux x86_64; rv:126.0) Gecko/20100101 Firefox/126.0'],
            'Safari on iPhone'  => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'],
        ];
    }

    /**
     * Without the library, the answers are exactly what they always were.
     *
     * This is what every installation that does not install it gets, so it is the case
     * that must not have moved. An empty agent stays human — monitoring tools omit the
     * header and treating them as bots was never the intent.
     */
    public function testWithoutTheLibraryNothingChanged(): void
    {
        // Arrange
        $builtIn = new BuiltInOnlyBotDetector();

        // Act + Assert
        $this->assertTrue($builtIn->isBot('Googlebot/2.1 (+http://www.google.com/bot.html)'));
        $this->assertTrue($builtIn->isBot('curl/8.4.0'));
        $this->assertFalse($builtIn->isBot('Mozilla/5.0 (X11; Linux x86_64; rv:126.0) Gecko/20100101 Firefox/126.0'));
        $this->assertFalse($builtIn->isBot(''));
        $this->assertSame('Googlebot', $builtIn->botName('Googlebot/2.1 (+http://www.google.com/bot.html)'));
    }

    /**
     * A name somebody wrote beats a fragment of a regular expression.
     *
     * The framework's own patterns are consulted first for the name, because they carry a
     * label — "Googlebot" — while the library can only report which part of the agent
     * string it matched on. The library is the wider net, not the better label.
     */
    public function testAKnownBotKeepsItsWrittenName(): void
    {
        // Act + Assert
        $this->assertSame(
            'Googlebot',
            (new BotDetector())->botName('Googlebot/2.1 (+http://www.google.com/bot.html)')
        );
    }

    /**
     * A crawler only the library knows still gets a name.
     *
     * Empty would be worse than a fragment: a log line saying a bot was seen and refusing
     * to say which is a line nobody can act on.
     */
    public function testACrawlerOnlyTheLibraryKnowsIsNamed(): void
    {
        // Arrange — one the built-in patterns do not match, so the name can only come
        // from the library
        $agent = 'python-requests/2.31.0';
        $this->assertSame('', (new BuiltInOnlyBotDetector())->botName($agent));

        // Act
        $name = (new BotDetector())->botName($agent);

        // Assert
        $this->assertNotSame('', $name, 'a bot was detected and could not be named');
        $this->assertStringContainsStringIgnoringCase('requests', $name);
    }

    /**
     * An empty agent is not a bot, with the library or without it.
     */
    public function testAnEmptyAgentIsNotABotEitherWay(): void
    {
        // Act + Assert
        $this->assertFalse((new BotDetector())->isBot(''));
        $this->assertSame('', (new BotDetector())->botName(''));
    }

    /**
     * The library is resolved once and the answer does not drift.
     *
     * It compiles its regular expression in the constructor and this is called on every
     * request in the session tracker, so it is held. A memo that answered differently the
     * second time would be a visitor counted one way and a session tracked the other.
     */
    public function testTheLibraryIsResolvedOnceAndAnswersTheSame(): void
    {
        // Arrange
        $detector = new BotDetector();
        $agent    = 'python-requests/2.31.0';

        // Act — twice, so the second call is the memoised path
        $first  = $detector->isBot($agent);
        $second = $detector->isBot($agent);

        // Assert
        $this->assertTrue($first);
        $this->assertSame($first, $second);
    }

    /**
     * And an absent library is remembered as absent.
     *
     * Three states rather than two — resolved, absent, not yet looked — because `null`
     * alone would send every call back to `class_exists()`. Asserted through the answers
     * rather than the cache, which is the part that has to stay true.
     */
    public function testAnAbsentLibraryIsRememberedAndTheAnswersHold(): void
    {
        // Arrange
        $detector = new BuiltInOnlyBotDetector();

        // Act
        $first  = $detector->isBot('python-requests/2.31.0');
        $second = $detector->isBot('python-requests/2.31.0');

        // Assert — not a bot to the built-in list, consistently
        $this->assertFalse($first);
        $this->assertSame($first, $second);
        $this->assertTrue($detector->isBot('Googlebot/2.1 (+http://www.google.com/bot.html)'));
    }
}
