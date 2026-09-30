<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * The cookie banner reaches every page a scaffolded application renders.
 *
 * There is one script, but four places load it: each theme's footer and the SPA shell
 * (spa and hybrid styles). A place that forgot the tag does not fail — it just sets
 * cookies with nobody asked, which is the whole problem the banner exists for. And a page
 * with the banner but no way back to it breaks the other half of the rule: withdrawing
 * must be as easy as agreeing. So each file is read and both are looked for.
 */
class ScaffoldCookieConsentTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return [
            'tailwind'  => ['tailwind'],
            'bootstrap' => ['bootstrap'],
            'plain-css' => ['plain-css'],
        ];
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every theme footer emits the tag and a "Cookie settings" link that reopens the
     * dialog, and loads the tag before the page's own scripts so the Consent Mode
     * default is on the data layer before anything reads it.
     */
    #[DataProvider('themes')]
    public function testEveryFooterLoadsTheBannerAndALinkBackToIt(string $theme): void
    {
        // Arrange
        $footer = self::read('scaffolding/themes/' . $theme . '/footer.php');

        // Act
        $tagAt = strpos($footer, '\Pramnos\Security\CookieConsent::tag(sURL)');
        $jsAt  = strpos($footer, 'renderJs()');

        // Assert
        $this->assertNotFalse($tagAt, $theme . ' footer does not load the banner');
        $this->assertStringContainsString('data-consent-open', $footer);
        $this->assertLessThan($jsAt, $tagAt, 'the banner loads before the page scripts');
    }

    /**
     * The settings screen carries the four fields the controller saves, by the names
     * it reads — a missing field would be kept as-is, never editable.
     */
    #[DataProvider('themes')]
    public function testEverySettingsScreenCarriesTheConsentFields(string $theme): void
    {
        // Arrange
        $view = self::read('scaffolding/themes/' . $theme . '/views/settings/settings.html.php');

        // Act / Assert
        foreach (['ENABLED_SETTING', 'CATEGORIES_SETTING', 'POLICY_URL_SETTING', 'VERSION_SETTING'] as $constant) {
            $this->assertStringContainsString('\Pramnos\Security\CookieConsent::' . $constant, $view, $theme . ' lacks ' . $constant);
        }
        // A project that switched it off in app.php is told so instead of shown fields.
        $this->assertStringContainsString('\Pramnos\Security\CookieConsent::offInApp()', $view);
    }

    /**
     * The SPA shell does not boot the application and so cannot read settings: it
     * loads the same script pointed at `/cookieconsent` for its configuration (see
     * {@see \Pramnos\Tests\Unit\Console\InitCookieConsentTest}), and that path is kept
     * with the front controller rather than swallowed by the shell.
     */
    public function testTheSpaShellLoadsTheBannerAndItsEndpointIsRouted(): void
    {
        // Arrange
        $shell = self::read('scaffolding/templates/spa-shell.php.stub');

        // Act
        $prefixes = Init::mvcRoutePrefixes([]);

        // Assert
        // The tag is a token, so `init` can leave it out of a project that declined it.
        $this->assertStringContainsString('{{ cookieConsentScript }}', $shell);
        $this->assertContains('cookieconsent', $prefixes);
    }

    /**
     * The standalone login layout `init` generates carries the tag too — a sign-in
     * page is a page, and often the first one somebody sees.
     */
    public function testTheGeneratedLoginLayoutLoadsTheBanner(): void
    {
        // Arrange
        $init = new \ReflectionMethod(Init::class, 'themeFootAssets');
        $instance = (new \ReflectionClass(Init::class))->newInstanceWithoutConstructor();
        $withSw = new \ReflectionProperty(Init::class, 'withServiceWorker');
        $withSw->setValue($instance, false);
        $base = new \ReflectionProperty(Init::class, 'targetBaseDir');
        $base->setValue($instance, sys_get_temp_dir() . '/pf-no-such-project');
        $root = new \ReflectionProperty(Init::class, 'webRoot');
        $root->setValue($instance, 'www');

        // Act
        $markup = $init->invoke($instance, 'plain-css', []);

        // Assert
        $this->assertStringContainsString('\Pramnos\Security\CookieConsent::tag(sURL)', $markup);
    }
}
