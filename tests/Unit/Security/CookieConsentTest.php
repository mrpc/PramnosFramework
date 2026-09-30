<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Security\CookieConsent;

/**
 * The server half of the cookie banner: what the tag carries, and how PHP reads a choice.
 *
 * The browser decides and the server obeys, so the contracts pinned here are the ones a
 * mistake would turn into a legal problem rather than a visible bug: a choice made under an
 * old policy must not count, a category nobody was asked about must never read as granted,
 * and a site that turned the banner off must not silently switch everything off.
 */
#[CoversClass(CookieConsent::class)]
class CookieConsentTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cookies = [];

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    private mixed $savedDatabase = null;

    /**
     * Settings in memory only, with no database behind them.
     *
     * A key this test has not set reads its default rather than asking whatever
     * database an earlier test left connected, and nothing is deleted from one:
     * `deleteSetting()` did that here, and failed whenever the leftover database
     * had no settings table.
     */
    protected function setUp(): void
    {
        $this->cookies       = $_COOKIE;
        $this->savedSettings = (array) self::settingsProperty('settings')->getValue();
        $this->savedDatabase = self::settingsProperty('database')->getValue();

        $settings = $this->savedSettings;
        foreach ([
            CookieConsent::ENABLED_SETTING,
            CookieConsent::CATEGORIES_SETTING,
            CookieConsent::POLICY_URL_SETTING,
            CookieConsent::VERSION_SETTING,
        ] as $key) {
            unset($settings[$key]);
        }
        self::settingsProperty('settings')->setValue(null, $settings);
        self::settingsProperty('database')->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        self::settingsProperty('settings')->setValue(null, $this->savedSettings);
        self::settingsProperty('database')->setValue(null, $this->savedDatabase);
    }

    private static function settingsProperty(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(Settings::class, $name);
    }

    /**
     * With nothing configured, the banner is on, asks about all three optional
     * categories and calls the policy version "1".
     */
    public function testDefaultsAreOnWithEveryCategory(): void
    {
        // Act / Assert
        $this->assertTrue(CookieConsent::enabled());
        $this->assertSame(['preferences', 'analytics', 'marketing'], CookieConsent::categories());
        $this->assertSame('1', CookieConsent::version());
    }

    /**
     * Configured categories are narrowed to known names in the banner's order; an
     * empty version falls back to "1" rather than matching a cookie with no version.
     */
    public function testCategoriesAreNarrowedAndAnEmptyVersionIsOne(): void
    {
        // Arrange
        Settings::setSetting(CookieConsent::CATEGORIES_SETTING, 'Marketing, tracking ,analytics', false);
        Settings::setSetting(CookieConsent::VERSION_SETTING, '  ', false);

        // Act / Assert
        $this->assertSame(['analytics', 'marketing'], CookieConsent::categories());
        $this->assertSame('1', CookieConsent::version());
    }

    /**
     * A cookie under the current version grants exactly what it lists, among the
     * known categories — an invented name in the cookie is ignored.
     */
    public function testGrantedReadsTheCookie(): void
    {
        // Arrange
        $cookies = [CookieConsent::COOKIE => 'v=1&c=analytics.evil&t=1700000000'];

        // Act
        $granted = CookieConsent::granted($cookies);

        // Assert
        $this->assertSame(['necessary', 'analytics'], $granted);
        $this->assertTrue(CookieConsent::allows('Analytics', $cookies), 'category names are case-insensitive');
        $this->assertFalse(CookieConsent::allows('marketing', $cookies));
    }

    /**
     * A choice made under an older policy version is no choice: only the strictly
     * necessary category is allowed until the visitor answers again.
     */
    public function testAChoiceUnderAnOlderPolicyDoesNotCount(): void
    {
        // Arrange
        Settings::setSetting(CookieConsent::VERSION_SETTING, '2', false);
        $_COOKIE[CookieConsent::COOKIE] = 'v=1&c=analytics.marketing&t=1';

        // Act / Assert
        $this->assertSame(['necessary'], CookieConsent::granted());
        $this->assertFalse(CookieConsent::allows('analytics'));
        $this->assertTrue(CookieConsent::allows('necessary'));
    }

    /**
     * No cookie, or one that is not a string (a crafted `pf_consent[]=`), means no
     * choice — never an error.
     */
    public function testAMissingOrMalformedCookieIsNoChoice(): void
    {
        // Act / Assert
        $this->assertSame(['necessary'], CookieConsent::granted([]));
        $this->assertSame(['necessary'], CookieConsent::granted([CookieConsent::COOKIE => ['x']]));
        $this->assertSame(['necessary'], CookieConsent::granted([CookieConsent::COOKIE => 'garbage']));
    }

    /**
     * With the banner off, `allows()` is always true: nobody is ever asked, so gating
     * on the answer would switch every optional feature off for good. The tag says the
     * same to the browser, so a script gated with `type="text/plain"` is released.
     */
    public function testWithTheBannerOffEverythingIsAllowed(): void
    {
        // Arrange
        Settings::setSetting(CookieConsent::ENABLED_SETTING, '0', false);

        // Act / Assert
        $this->assertFalse(CookieConsent::enabled());
        $this->assertTrue(CookieConsent::allows('marketing', []));
        // The script is still emitted, told it is off, so gated scripts are released.
        $tag = CookieConsent::tag('https://example.com/');
        $this->assertStringContainsString('pf-consent.js', $tag);
        $this->assertStringContainsString('data-config="{&quot;enabled&quot;:false}"', $tag);
    }

    /**
     * `'cookie_consent' => false` in app.php switches the feature off whatever the
     * setting says — the answer `init` writes for a project that declined it.
     */
    public function testAppConfigSwitchesItOffOverTheSetting(): void
    {
        // Arrange
        $reflection = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $saved = $reflection->getValue() ?? [];
        $stub = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['cookie_consent' => false];
        $reflection->setValue(null, ['default' => $stub] + $saved);
        Settings::setSetting(CookieConsent::ENABLED_SETTING, '1', false);

        try {
            // Act / Assert
            $this->assertTrue(CookieConsent::offInApp());
            $this->assertFalse(CookieConsent::enabled());
            $this->assertStringContainsString('{&quot;enabled&quot;:false}', CookieConsent::tag('https://example.com/'));
        } finally {
            $reflection->setValue(null, $saved);
        }

        // With the key gone the setting decides again.
        $this->assertFalse(CookieConsent::offInApp());
    }

    /**
     * The tag is an external script with its configuration as an escaped attribute —
     * no inline script, so no CSP nonce in a cacheable body — and the configuration
     * lists strictly necessary first, then the site's categories.
     */
    public function testTheTagCarriesTheConfigurationEscaped(): void
    {
        // Arrange
        Settings::setSetting(CookieConsent::CATEGORIES_SETTING, 'analytics', false);
        Settings::setSetting(CookieConsent::POLICY_URL_SETTING, 'https://example.com/cookies?a="b"', false);

        // Act
        $tag = CookieConsent::tag('https://example.com');
        preg_match('/data-config="([^"]*)"/', $tag, $match);
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

        // Assert
        $this->assertStringStartsWith('<script src="https://example.com/assets/js/pf-consent.js"', $tag);
        $this->assertStringEndsWith(' defer></script>', $tag);
        $this->assertSame(['necessary', 'analytics'], array_column($config['categories'], 'name'));
        // The quote in the URL survived the attribute rather than closing it.
        $this->assertSame('https://example.com/cookies?a="b"', $config['policyUrl']);
        $this->assertSame('https://example.com/cookieconsent/record', $config['recordUrl']);
        $this->assertSame(180, $config['days']);
        $this->assertNotSame('', $config['text']['rejectAll']);
    }

    /**
     * Without a base URL there is nowhere to post a choice to, so the record URL is
     * empty and the script does not try.
     */
    public function testConfigWithoutABaseHasNoRecordUrl(): void
    {
        // Act
        $config = CookieConsent::config();

        // Assert
        $this->assertSame('', $config['recordUrl']);
    }
}
