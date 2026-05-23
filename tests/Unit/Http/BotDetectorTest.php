<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\BotDetector;

/**
 * Unit tests for BotDetector.
 *
 * BotDetector is a stateless pattern-matching service; no database or HTTP
 * infrastructure is required. Tests verify that known bots are detected and
 * that ordinary browser user-agents are treated as human traffic.
 *
 * These tests directly validate the ROADMAP Phase 25.5 test requirements:
 *   - BotDetector::isBot('Googlebot/2.1') → true
 *   - BotDetector::isBot('Mozilla/5.0 (Windows NT 10.0)') → false
 */
class BotDetectorTest extends TestCase
{
    private BotDetector $detector;

    protected function setUp(): void
    {
        // Arrange — shared instance; BotDetector is stateless
        $this->detector = new BotDetector();
    }

    // =========================================================================
    // isBot() — known bots
    // =========================================================================

    /**
     * Googlebot must be recognised as a bot.
     *
     * This is the canonical ROADMAP requirement (Phase 25.5).
     */
    public function testGooglebotIsDetectedAsBot(): void
    {
        // Act + Assert
        $this->assertTrue(
            $this->detector->isBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
            'Googlebot/2.1 must be detected as a bot'
        );
    }

    /**
     * Bing Bot must be recognised as a bot.
     */
    public function testBingBotIsDetectedAsBot(): void
    {
        // Act + Assert
        $this->assertTrue(
            $this->detector->isBot('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'),
            'bingbot must be detected as a bot'
        );
    }

    /**
     * Yandex Bot must be recognised as a bot.
     */
    public function testYandexBotIsDetectedAsBot(): void
    {
        // Act + Assert
        $this->assertTrue(
            $this->detector->isBot('Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'),
            'YandexBot must be detected'
        );
    }

    /**
     * OpenAI GPTBot must be recognised as a bot.
     *
     * AI crawlers should be treated as bots for session-tracking purposes.
     */
    public function testGptBotIsDetectedAsBot(): void
    {
        // Act + Assert
        $this->assertTrue(
            $this->detector->isBot('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)'),
            'GPTBot must be detected'
        );
    }

    /**
     * Common Crawl (CCBot) must be recognised as a bot.
     */
    public function testCcBotIsDetectedAsBot(): void
    {
        // Act + Assert
        $this->assertTrue(
            $this->detector->isBot('CCBot/2.0 (https://commoncrawl.org/faq/)'),
            'CCBot must be detected'
        );
    }

    /**
     * UptimeRobot monitoring agent must be recognised as a bot.
     */
    public function testUptimeRobotIsDetectedAsBot(): void
    {
        // Act + Assert
        $this->assertTrue(
            $this->detector->isBot('Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'),
            'UptimeRobot must be detected'
        );
    }

    // =========================================================================
    // isBot() — human browsers
    // =========================================================================

    /**
     * A standard Windows Chrome user-agent must NOT be treated as a bot.
     *
     * This is the canonical ROADMAP requirement (Phase 25.5).
     */
    public function testWindowsChromeIsNotABot(): void
    {
        // Act + Assert
        $this->assertFalse(
            $this->detector->isBot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'),
            'Windows Chrome must NOT be detected as a bot'
        );
    }

    /**
     * macOS Safari must NOT be treated as a bot.
     */
    public function testMacSafariIsNotABot(): void
    {
        // Act + Assert
        $this->assertFalse(
            $this->detector->isBot('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'),
            'macOS Safari must not be detected as a bot'
        );
    }

    /**
     * Firefox on Linux must NOT be treated as a bot.
     */
    public function testFirefoxLinuxIsNotABot(): void
    {
        // Act + Assert
        $this->assertFalse(
            $this->detector->isBot('Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0'),
            'Firefox on Linux must not be detected as a bot'
        );
    }

    /**
     * An empty user-agent string must NOT be treated as a bot.
     *
     * Some monitoring tools and CLI tools omit the User-Agent header entirely;
     * treating empty strings as bots would cause false-positives for legitimate
     * headless requests that are not crawlers.
     */
    public function testEmptyAgentIsNotABot(): void
    {
        // Act + Assert
        $this->assertFalse(
            $this->detector->isBot(''),
            'Empty user-agent must not be detected as a bot'
        );
    }

    // =========================================================================
    // botName()
    // =========================================================================

    /**
     * botName() must return the human-readable name for a recognised bot.
     *
     * The name is used by Addon\System\Session and SessionTrackingMiddleware
     * to record who is visiting in the sessions table.
     */
    public function testBotNameReturnsHumanReadableNameForKnownBot(): void
    {
        // Act
        $name = $this->detector->botName(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        );

        // Assert — the canonical bot name (matches the pattern map)
        $this->assertSame('Googlebot', $name, 'botName() must return the canonical Googlebot label');
    }

    /**
     * botName() must return an empty string for ordinary browser user-agents.
     *
     * Callers use the empty return to branch on "is a bot" without a separate
     * isBot() call.
     */
    public function testBotNameReturnsEmptyStringForHumanBrowser(): void
    {
        // Act
        $name = $this->detector->botName(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0'
        );

        // Assert
        $this->assertSame('', $name, 'botName() must return empty string for human browsers');
    }
}
