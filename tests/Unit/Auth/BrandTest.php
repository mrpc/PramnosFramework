<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Auth\Brand;

/** The `auth_brand` partial, drawn: `$this` is what the partial reads. */
class BrandPartialProbe
{
    /** @param array<string, string>|null $brand */
    public function __construct(public ?array $brand = null)
    {
    }

    /** The partial's HTML. */
    public function draw(string $file): string
    {
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}

/**
 * Whose server this is, on every auth card: the brand comes from the `auth_brand_*` settings (or
 * the site's name), the partial shows the logo or the name escaped, and every card screen of the
 * three themes inserts it.
 */
#[CoversClass(Brand::class)]
final class BrandTest extends TestCase
{
    protected function tearDown(): void
    {
        Settings::clearSettings();
        parent::tearDown();
    }

    /**
     * With no settings at all there is still something to show — a page with an empty heading
     * looks broken — and the colour has its default.
     */
    public function testWithNoSettingsTheBrandHasDefaults(): void
    {
        // Arrange
        foreach (['auth_brand_name', 'sitename', 'auth_brand_logo', 'auth_brand_primary_color', 'auth_brand_footer'] as $key) {
            Settings::setSetting($key, '', false);
        }

        // Act
        $brand = Brand::current();

        // Assert
        $this->assertSame(['name' => 'Sign in', 'logo' => '', 'primary_color' => '#2563eb', 'primary_color_set' => false, 'footer' => ''], $brand);
    }

    /**
     * The site's name stands in for a brand name nobody set, and a set brand name wins over it.
     */
    public function testTheBrandNameFallsBackToTheSitesName(): void
    {
        // Arrange
        Settings::setSetting('auth_brand_name', '', false);
        Settings::setSetting('sitename', 'Example Site', false);
        Settings::setSetting('auth_brand_primary_color', '#ff0000', false);
        Settings::setSetting('auth_brand_logo', '/logo.svg', false);
        Settings::setSetting('auth_brand_footer', 'Example Ltd', false);

        // Act
        $fallback = Brand::current();
        Settings::setSetting('auth_brand_name', 'Example Accounts', false);
        $named = Brand::current();

        // Assert
        $this->assertSame('Example Site', $fallback['name']);
        $this->assertSame('#ff0000', $fallback['primary_color']);
        $this->assertTrue($fallback['primary_color_set']);
        $this->assertSame('/logo.svg', $fallback['logo']);
        $this->assertSame('Example Ltd', $fallback['footer']);
        $this->assertSame('Example Accounts', $named['name']);
    }

    /**
     * In each theme the partial draws the logo with the name as its alt text, or — with no logo —
     * the name as a heading; both escaped, since the name is whatever an administrator typed.
     */
    public function testThePartialDrawsTheLogoOrTheNameEscapedInEveryTheme(): void
    {
        $partials = glob(dirname(__DIR__, 3) . '/scaffolding/themes/*/views/partials/auth_brand.html.php') ?: [];
        $this->assertCount(3, $partials, 'one partial per theme');
        foreach ($partials as $file) {
            // Arrange
            $withLogo = new BrandPartialProbe(['name' => 'A & <B>', 'logo' => '/l.svg?a=1&b=2']);
            $noLogo   = new BrandPartialProbe(['name' => 'A & <B>', 'logo' => '']);

            // Act
            $logoHtml = $withLogo->draw($file);
            $nameHtml = $noLogo->draw($file);

            // Assert
            $this->assertStringContainsString('src="/l.svg?a=1&amp;b=2"', $logoHtml, $file);
            $this->assertStringContainsString('alt="A &amp; &lt;B&gt;"', $logoHtml, $file);
            $this->assertDoesNotMatchRegularExpression('/<h[12]/', $logoHtml, 'the logo replaces the heading: ' . $file);
            // A heading of the theme's own level: the card's title in plain CSS, the page's elsewhere.
            $this->assertMatchesRegularExpression('#A &amp; &lt;B&gt;</h[12]>#', $nameHtml, $file);
            $this->assertStringNotContainsString('<B>', $nameHtml, 'never raw: ' . $file);
        }
    }

    /**
     * Every card screen somebody can arrive at from a link inserts the brand, in every theme — a
     * screen that forgets it asks for a password without saying whose server is asking.
     */
    public function testEveryCardScreenInsertsTheBrand(): void
    {
        // Arrange
        $screens = [
            'login/login', 'login/login_2fa', 'login/approve', 'login/message', 'login/forgotpassword',
            'login/resetpassword', 'login/newpassword', 'register/register', 'OAuth2/authorize',
            'OAuth2/errormessage', 'device/device', 'device/confirmation', 'device/deny',
            'device/success', 'device/errormessage',
        ];
        $missing = [];

        // Act
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            foreach ($screens as $screen) {
                $file = dirname(__DIR__, 3) . "/scaffolding/themes/{$theme}/views/{$screen}.html.php";
                if (!str_contains((string) @file_get_contents($file), "insert('../partials/auth_brand')")) {
                    $missing[] = "{$theme}/{$screen}";
                }
            }
        }

        // Assert
        $this->assertSame([], $missing);
    }

    /**
     * No view forces the brand's colour on a theme: every view that reads it applies it only
     * when `primary_color_set` says an administrator chose one, so a daisyUI or dark theme with
     * its own primary keeps it.
     */
    public function testNoViewForcesTheBrandColourWhenNoneWasChosen(): void
    {
        // Arrange
        $views = glob(dirname(__DIR__, 3) . '/scaffolding/themes/*/views/*/*.html.php') ?: [];
        $this->assertNotEmpty($views, 'the sweep found nothing to check');
        $readers = 0;
        $forced  = [];

        // Act
        foreach ($views as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, "primary_color'")) {
                continue;
            }
            $readers++;
            if (!str_contains($source, "primary_color_set")
                || preg_match('/<\?php echo \$primary; \?>/', $source) === 1) {
                $forced[] = basename(dirname($file, 3)) . '/' . basename(dirname($file)) . '/' . basename($file);
            }
        }

        // Assert
        $this->assertGreaterThan(0, $readers, 'no view reads the brand colour: the sweep is looking in the wrong place');
        $this->assertSame([], $forced, 'views that print the brand colour whether or not it was chosen');
    }
}
