<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\SitemapGenerate;
use Pramnos\Html\Hreflang;
use Pramnos\Html\Sitemap;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the sitemap:generate console command — the three sources it combines
 * (a route file's localized route groups, Hreflang::group() declarations, and registered
 * Sitemap::provider()s) and the option wiring around SitemapWriter.
 */
#[CoversClass(SitemapGenerate::class)]
class SitemapGenerateTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sitemapgen_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
        Hreflang::reset();
        Sitemap::reset();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        Hreflang::reset();
        Sitemap::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function tester(): CommandTester
    {
        $command = new SitemapGenerate();
        $command->targetBaseDir = $this->tmp;
        return new CommandTester($command);
    }

    /**
     * A route file's localized route group becomes one sitemap entry per
     * locale, each carrying the whole group as its hreflang alternates.
     */
    public function testRouteFileLocaleGroupBecomesSitemapEntries(): void
    {
        // Arrange
        $routesFile = $this->tmp . '/routes.php';
        file_put_contents($routesFile, <<<'PHP'
<?php
$router = new \Pramnos\Routing\Router(new \Pramnos\Application\Application('myApp'));
$router->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], fn() => 'faq-page');
return $router->dispatch(new \Pramnos\Http\Request());
PHP);

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--routes'   => 'routes.php',
            '--output'   => 'sitemap.xml',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $xml = (string) file_get_contents($this->tmp . '/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/syxnes-erwthseis</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/faqs</loc>', $xml);
        $this->assertStringContainsString('hreflang="el"', $xml);
        $this->assertStringContainsString('hreflang="en"', $xml);
    }

    /**
     * A hand-declared Hreflang::group() feeds the sitemap with no --routes
     * at all — the "one declaration, two consumers" promise.
     */
    public function testHreflangGroupFeedsTheSitemapWithoutRoutes(): void
    {
        // Arrange — registered directly, the same effect app/hreflang.php's own
        // Hreflang::group() call would have; the command reads whatever is registered
        // regardless of how it got there, and ROOT (not $this->tmp) is where
        // Hreflang::loadDefinitions() with no args would look for that file anyway.
        Hreflang::group(['el' => '/oroi-xrisis-el', 'en' => '/terms']);

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'   => 'sitemap.xml',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $xml = (string) file_get_contents($this->tmp . '/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/terms</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/oroi-xrisis-el</loc>', $xml);
    }

    /**
     * A registered Sitemap::provider() entry is written through unchanged
     * — its own absolute URL, no base-url prefixing, since it already
     * built one itself.
     */
    public function testRegisteredProviderIsIncluded(): void
    {
        // Arrange
        Sitemap::provider('ads', function (): \Generator {
            yield ['loc' => 'https://example.com/ads/1', 'lastmod' => '2026-11-01'];
        });

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'   => 'sitemap.xml',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $xml = (string) file_get_contents($this->tmp . '/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/ads/1</loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-11-01</lastmod>', $xml);
    }

    /**
     * An explicitly blank --base-url, with no setting to fall back to,
     * refuses clearly rather than writing a sitemap full of file paths.
     *
     * Passed explicitly rather than omitted: sURL is a global constant
     * other tests in this suite may already have defined, which would
     * otherwise make "nothing available" untestable here.
     */
    public function testRefusesWithNoBaseUrlAvailable(): void
    {
        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--output' => 'sitemap.xml', '--base-url' => '']);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No base URL', $tester->getDisplay());
    }

    /**
     * A --routes file that does not exist refuses clearly.
     */
    public function testRefusesWhenRouteFileIsMissing(): void
    {
        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--routes'   => 'nope.php',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Route file not found', $tester->getDisplay());
    }

    /**
     * Nothing registered anywhere refuses rather than overwriting a good
     * sitemap with an empty one — the same guard api:docs has.
     */
    public function testRefusesWhenNothingIsRegistered(): void
    {
        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'   => 'sitemap.xml',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertFileDoesNotExist($this->tmp . '/sitemap.xml');
    }

    /**
     * --allow-empty writes an empty sitemap instead of refusing.
     */
    public function testAllowEmptyWritesAnEmptySitemap(): void
    {
        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'     => 'sitemap.xml',
            '--base-url'   => 'https://example.com',
            '--allow-empty' => true,
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertFileExists($this->tmp . '/sitemap.xml');
    }

    /**
     * A --routes file that throws while being included refuses clearly,
     * rather than letting the exception escape the command.
     */
    public function testRefusesWhenRouteFileThrows(): void
    {
        // Arrange
        file_put_contents($this->tmp . '/broken.php', '<?php throw new \RuntimeException("boom");');

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--routes'   => 'broken.php',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not read', $tester->getDisplay());
        $this->assertStringContainsString('boom', $tester->getDisplay());
    }

    /**
     * The output directory is created when it does not exist yet.
     */
    public function testCreatesTheOutputDirectoryWhenMissing(): void
    {
        // Arrange
        Sitemap::provider('ads', function (): \Generator {
            yield ['loc' => 'https://example.com/ads/1'];
        });

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'   => 'build/sitemaps/sitemap.xml',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertFileExists($this->tmp . '/build/sitemaps/sitemap.xml');
    }

    /**
     * With no --base-url option at all (as opposed to an explicit blank
     * one), the command falls back to the site_url setting.
     */
    public function testFallsBackToTheSiteUrlSettingWhenBaseUrlIsOmitted(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting('site_url', 'https://fallback.example.com', false);
        Sitemap::provider('ads', function (): \Generator {
            yield ['loc' => 'https://fallback.example.com/ads/1'];
        });

        try {
            // Act
            $tester = $this->tester();
            $exit = $tester->execute(['--output' => 'sitemap.xml']);

            // Assert
            $this->assertSame(0, $exit, $tester->getDisplay());
        } finally {
            \Pramnos\Application\Settings::setSetting('site_url', false, false);
        }
    }

    /**
     * An absolute --output path is used as-is, not resolved against the
     * project base directory.
     */
    public function testAbsoluteOutputPathIsUsedAsIs(): void
    {
        // Arrange
        Sitemap::provider('ads', function (): \Generator {
            yield ['loc' => 'https://example.com/ads/1'];
        });
        $absolute = $this->tmp . '/elsewhere.xml';

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'   => $absolute,
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertFileExists($absolute);
    }

    /**
     * With no --output given, the default is <web root>/sitemap.xml — the
     * first of www/public/html/web that looks like a project's web root
     * (has an index.php), falling back to www/ when none does.
     */
    public function testDefaultOutputPathUsesTheWebRootCandidates(): void
    {
        // Arrange — no web root exists in this temp project yet
        Sitemap::provider('ads', function (): \Generator {
            yield ['loc' => 'https://example.com/ads/1'];
        });

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--base-url' => 'https://example.com']);

        // Assert — falls back to the first candidate, www/
        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertFileExists($this->tmp . '/www/sitemap.xml');
    }

    /**
     * When a web root candidate actually exists (has an index.php), the
     * default output goes there instead of the www/ fallback.
     */
    public function testDefaultOutputPathPrefersAnExistingWebRoot(): void
    {
        // Arrange
        mkdir($this->tmp . '/public', 0775, true);
        file_put_contents($this->tmp . '/public/index.php', '<?php');
        Sitemap::provider('ads', function (): \Generator {
            yield ['loc' => 'https://example.com/ads/1'];
        });

        // Act
        $tester = $this->tester();
        $exit = $tester->execute(['--base-url' => 'https://example.com']);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertFileExists($this->tmp . '/public/sitemap.xml');
    }

    /**
     * A reciprocity violation prints a warning but still succeeds.
     */
    public function testReciprocityWarningIsPrintedButSucceeds(): void
    {
        // Arrange
        Sitemap::provider('bad', function (): \Generator {
            yield [
                'loc'        => 'https://example.com/el',
                'alternates' => ['el' => 'https://example.com/el', 'en' => 'https://example.com/en'],
            ];
        });

        // Act
        $tester = $this->tester();
        $exit = $tester->execute([
            '--output'   => 'sitemap.xml',
            '--base-url' => 'https://example.com',
        ]);

        // Assert
        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertStringContainsString('Warning:', $tester->getDisplay());
        $this->assertStringContainsString('https://example.com/en', $tester->getDisplay());
    }
}
