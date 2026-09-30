<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\CookieConsent;

/**
 * `assetUrl()` stamps a static file's URL with its modification time.
 *
 * A file at a fixed URL is kept by whatever sits in front of it: Cloudflare answered `HIT`
 * with the old `pf-consent.js` for the four hours `max-age` allowed, so a deployed fix to the
 * cookie dialog reached nobody. The stamp changes the URL when the file changes and only
 * then. And every framework asset the scaffold loads goes through it, because one tag left
 * unstamped is one fix that does not ship.
 */
class HelpersAssetUrlTest extends TestCase
{
    private string $webRoot = '';

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server  = $_SERVER;
        $this->webRoot = sys_get_temp_dir() . '/pf-asset-' . bin2hex(random_bytes(5));
        mkdir($this->webRoot . '/assets/js', 0777, true);
        file_put_contents($this->webRoot . '/index.php', '<?php');
        file_put_contents($this->webRoot . '/assets/js/pf-consent.js', '//');
        touch($this->webRoot . '/assets/js/pf-consent.js', 1790000000);
        // The front controller that is running is what locates the web root.
        $_SERVER['SCRIPT_FILENAME'] = $this->webRoot . '/index.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        @unlink($this->webRoot . '/assets/js/pf-consent.js');
        @unlink($this->webRoot . '/index.php');
        @rmdir($this->webRoot . '/assets/js');
        @rmdir($this->webRoot . '/assets');
        @rmdir($this->webRoot);
    }

    /**
     * A file beside the front controller gets `?v=<mtime>`, under the base given;
     * a leading slash in the path does not double up.
     */
    public function testAPresentFileIsStamped(): void
    {
        // Act
        $url = assetUrl('/assets/js/pf-consent.js', 'https://example.com/');

        // Assert
        $this->assertSame('https://example.com/assets/js/pf-consent.js?v=1790000000', $url);
    }

    /**
     * A file that is not there gets no stamp, rather than an error or `?v=0`.
     */
    public function testAMissingFileIsNotStamped(): void
    {
        // Act
        $url = assetUrl('assets/js/nope.js', '/');

        // Assert
        $this->assertSame('/assets/js/nope.js', $url);
    }

    /**
     * The cookie banner's tag carries the stamp — the finding this helper came from.
     */
    public function testTheCookieConsentTagIsStamped(): void
    {
        // Act
        $tag = CookieConsent::tag('https://example.com/');

        // Assert
        $this->assertStringStartsWith('<script src="https://example.com/assets/js/pf-consent.js?v=1790000000"', $tag);
    }

    /** @return array<string, array{string}> Every scaffold file that could load an asset */
    public static function scaffoldFiles(): array
    {
        $root  = dirname(__DIR__, 3);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/scaffolding/themes', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = [$file->getPathname()];
            }
        }
        $files['Init.php'] = [$root . '/src/Pramnos/Console/Commands/Init.php'];

        return $files;
    }

    /**
     * No scaffold view, layout or generator loads a framework asset unstamped.
     */
    #[DataProvider('scaffoldFiles')]
    public function testNoScaffoldFileLoadsAFrameworkAssetUnstamped(string $path): void
    {
        // Arrange
        $source = (string) file_get_contents($path);

        // Act
        preg_match_all('#<\?php echo sURL; \?>assets/(?:js/(?:pf-[a-z]+|push)\.js|css/style\.css)#', $source, $found);

        // Assert
        $this->assertSame([], $found[0], 'use assetUrl() so a deployed fix reaches the browser');
    }
}
