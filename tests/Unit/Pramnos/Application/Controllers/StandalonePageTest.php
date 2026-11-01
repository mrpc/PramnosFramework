<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\StandalonePage;

/**
 * StandalonePage::themedPage() — the one call site of the nine robots call
 * sites named in docs/Hreflang-And-Sitemap-Design.md §1 that already had a
 * Document available, so it is the one migrated to Document::setRobots()
 * instead of a direct header('X-Robots-Tag: …') call.
 *
 * Verifies the migration kept the directive and — now that it goes through
 * Document — also gets the <meta name="robots"> tag the bare header() call
 * never emitted at this call site.
 */
#[CoversTrait(StandalonePage::class)]
class StandalonePageTest extends TestCase
{
    private string $themeDir;

    protected function setUp(): void
    {
        $this->themeDir = sys_get_temp_dir() . '/standalone_page_test_theme_' . uniqid();
        mkdir($this->themeDir);
        file_put_contents($this->themeDir . '/login.php', '<?php // test theme layout');
    }

    protected function tearDown(): void
    {
        @unlink($this->themeDir . '/login.php');
        @rmdir($this->themeDir);
    }

    /**
     * When the active theme has a login.php layout, themedPage() sets
     * Document::$robots to 'noindex, nofollow' rather than sending the
     * header directly — an unsubscribe/mailing-list URL carries a token,
     * and a search engine that indexed one would publish somebody's
     * ability to unsubscribe them.
     */
    public function testThemedPageSetsRobotsOnTheDocument(): void
    {
        // Arrange
        $doc   = \Pramnos\Framework\Factory::getDocument('html');
        $theme = new class ($this->themeDir) {
            public string $fullpath;
            public function __construct(string $fullpath)
            {
                $this->fullpath = $fullpath;
            }
            public function setContentType(string $type): void
            {
            }
        };
        $doc->themeObject = $theme;
        $doc->robots       = null;

        $subject = new class {
            use StandalonePage;
        };

        // Act
        $ref = new \ReflectionMethod($subject, 'themedPage');
        $handled = $ref->invoke($subject, 'Unsubscribed', 'You have been unsubscribed.', '', '');

        // Assert
        $this->assertTrue($handled);
        $this->assertSame('noindex, nofollow', $doc->robots);
    }
}
