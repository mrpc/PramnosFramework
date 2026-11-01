<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Sitemap;

/**
 * The registry behind sitemap:generate — what belongs in the sitemap, entirely the
 * application's own criterion (docs/Hreflang-And-Sitemap-Design.md §9.2's decision: no
 * `latest N` / `changed since` shape from the framework).
 *
 * Mirrors HreflangTest.php, since Sitemap copies the same loadDefinitions() idiom.
 */
#[CoversClass(Sitemap::class)]
class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        Sitemap::reset();
    }

    protected function tearDown(): void
    {
        Sitemap::reset();
    }

    /**
     * A registered provider is retrievable by label, and a second
     * registration under the same label replaces the first rather than
     * running both.
     */
    public function testProviderRegistersAndReplacesByLabel(): void
    {
        // Arrange
        $first  = function (): \Generator { yield ['loc' => '/first']; };
        $second = function (): \Generator { yield ['loc' => '/second']; };

        // Act
        Sitemap::provider('ads', $first);
        Sitemap::provider('ads', $second);

        // Assert
        $this->assertTrue(Sitemap::hasProviders());
        $this->assertSame($second, Sitemap::providers()['ads']);
        $this->assertCount(1, Sitemap::providers());
    }

    /**
     * An empty label is refused — it is the registry key, and a blank one
     * cannot address anything.
     */
    public function testProviderRejectsABlankLabel(): void
    {
        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        Sitemap::provider('   ', function (): \Generator { yield ['loc' => '/x']; });
    }

    /**
     * Nothing registered yet reports as such, so the generator knows to
     * rely only on its two automatic sources (router groups, Hreflang
     * groups) rather than treating this as an error.
     */
    public function testHasProvidersIsFalseInitially(): void
    {
        // Assert
        $this->assertFalse(Sitemap::hasProviders());
        $this->assertSame([], Sitemap::providers());
    }

    /**
     * A missing app/sitemap.php is not an error — an application with
     * nothing registered here still has whatever the two automatic sources
     * find.
     */
    public function testLoadDefinitionsReturnsFalseWhenNoFileExists(): void
    {
        // Act
        $loaded = Sitemap::loadDefinitions('/nonexistent/path/sitemap.php');

        // Assert
        $this->assertFalse($loaded);
        $this->assertFalse(Sitemap::hasProviders());
    }

    /**
     * With no $file argument, the default path is ROOT/app/sitemap.php —
     * exercised here because the test application has no such file, so
     * this is also the "nothing to load" case for the default path.
     */
    public function testLoadDefinitionsUsesTheDefaultPathWhenNoneIsGiven(): void
    {
        // Act
        $loaded = Sitemap::loadDefinitions();

        // Assert
        $this->assertFalse($loaded);
    }

    /**
     * An existing file is required and its registration takes effect;
     * loadDefinitions() is idempotent within a process — a second call does
     * not require() the file again.
     */
    public function testLoadDefinitionsRequiresTheFileOnce(): void
    {
        // Arrange
        $tmpFile = tempnam(sys_get_temp_dir(), 'sitemap_test_') . '.php';
        file_put_contents($tmpFile, <<<'PHP'
<?php
\Pramnos\Html\Sitemap::provider('ads', function (): \Generator {
    yield ['loc' => '/ads/1'];
});
PHP);

        // Act
        $first  = Sitemap::loadDefinitions($tmpFile);
        unlink($tmpFile); // if loadDefinitions() required it again, this would throw
        $second = Sitemap::loadDefinitions($tmpFile);

        // Assert
        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertTrue(Sitemap::hasProviders());
    }
}
