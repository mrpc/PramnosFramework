<?php

namespace Pramnos\Tests\Unit\Application\Template;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Template\TemplateCache;

/**
 * Unit tests for TemplateCache.
 *
 * All tests use a temporary directory so they never touch the real filesystem
 * outside /tmp. Each test cleans up after itself.
 *
 * Coverage goals:
 *   - Default cache directory logic (ROOT defined vs. undefined)
 *   - getCachedPath() returns stable, collision-resistant key
 *   - isUpToDate() — cache absent, cache stale, cache fresh
 *   - store() — creates directory, writes file, returns path
 *   - resolve() — compiles on miss, returns cached path on hit
 *   - flush() — deletes compiled files, leaves non-.php files
 *   - Concurrency safety via atomic file_put_contents (structural check)
 */
#[\PHPUnit\Framework\Attributes\CoversClass(TemplateCache::class)]
class TemplateCacheTest extends TestCase
{
    /** Temporary directory used by the test; cleaned up in tearDown. */
    private string $tmpDir;

    /** Real source file written to disk for mtime-based tests. */
    private string $sourceFile;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/pramnos_cache_test_' . uniqid();
        $this->sourceFile = $this->tmpDir . '/source/template.tpl.php';
        @mkdir(dirname($this->sourceFile), 0755, true);
        file_put_contents($this->sourceFile, '@if($x) hello @endif');
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->rmdirRecursive($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    // =========================================================================
    // Constructor / directory defaults
    // =========================================================================

    /**
     * When an explicit directory is provided, getCacheDir() returns it exactly.
     * This verifies that the constructor doesn't silently override a caller-
     * supplied path with one of the defaults.
     */
    public function testExplicitCacheDirIsUsed(): void
    {
        // Arrange + Act
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        // Assert
        $this->assertSame($cacheDir, $cache->getCacheDir());
    }

    /**
     * When no directory is given and ROOT is not defined, the cache falls back
     * to sys_get_temp_dir()/pramnos_viewcache.
     * This prevents cache construction from failing in unit-test environments
     * where ROOT is unavailable.
     */
    public function testDefaultCacheDirFallsBackToTmpDir(): void
    {
        // Arrange — ROOT must NOT be defined for this test
        if (defined('ROOT')) {
            $this->markTestSkipped('ROOT is defined — default-dir fallback not testable in this env.');
        }

        // Act
        $cache = new TemplateCache();

        // Assert
        $expected = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pramnos_viewcache';
        $this->assertSame($expected, $cache->getCacheDir());
    }

    // =========================================================================
    // getCachedPath
    // =========================================================================

    /**
     * getCachedPath() returns a .php file inside the configured cache directory.
     * The filename is a SHA-1 hash so multiple source files never collide on
     * their basename.
     */
    public function testGetCachedPathReturnsPhpFileInCacheDir(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        // Act
        $path = $cache->getCachedPath($this->sourceFile);

        // Assert — is inside cache dir and has .php extension
        $this->assertStringStartsWith($cacheDir . DIRECTORY_SEPARATOR, $path);
        $this->assertStringEndsWith('.php', $path);
    }

    /**
     * Two different source files must produce different cached paths.
     * Collision would cause one template to silently overwrite another.
     */
    public function testDifferentSourceFilesGetDifferentCachedPaths(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        $sourceA = $this->tmpDir . '/source/a.tpl.php';
        $sourceB = $this->tmpDir . '/source/b.tpl.php';
        file_put_contents($sourceA, 'A');
        file_put_contents($sourceB, 'B');

        // Act
        $pathA = $cache->getCachedPath($sourceA);
        $pathB = $cache->getCachedPath($sourceB);

        // Assert — different files → different cache keys
        $this->assertNotSame($pathA, $pathB);
    }

    /**
     * The same source file always maps to the same cached path.
     * Determinism is required for mtime comparison to work correctly.
     */
    public function testSameSourceFileAlwaysMapsTossameCachedPath(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        // Act — call twice
        $pathA = $cache->getCachedPath($this->sourceFile);
        $pathB = $cache->getCachedPath($this->sourceFile);

        // Assert
        $this->assertSame($pathA, $pathB);
    }

    // =========================================================================
    // isUpToDate
    // =========================================================================

    /**
     * isUpToDate() returns false when the cached file does not exist yet.
     * On first use the cache is empty and every source must be compiled.
     */
    public function testIsUpToDateReturnsFalseWhenCacheFileMissing(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        // Act + Assert — cache dir is empty, no compiled file exists
        $this->assertFalse($cache->isUpToDate($this->sourceFile));
    }

    /**
     * isUpToDate() returns true when the cached file exists and is newer than
     * (or same age as) the source file.
     * In production, templates are rarely changed, so this is the hot path.
     */
    public function testIsUpToDateReturnsTrueWhenCacheIsNewer(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        mkdir($cacheDir, 0755, true);
        $cache = new TemplateCache($cacheDir);

        // Write the cached file and make its mtime 60 seconds in the future
        // relative to the source file so we can reliably test the comparison.
        $cachedPath = $cache->getCachedPath($this->sourceFile);
        file_put_contents($cachedPath, '<?php ?>');
        $sourceMtime = filemtime($this->sourceFile);
        touch($cachedPath, $sourceMtime + 60);

        // Act + Assert
        $this->assertTrue($cache->isUpToDate($this->sourceFile));
    }

    /**
     * isUpToDate() returns false when the source file is newer than the cache.
     * This triggers recompilation after a template edit.
     */
    public function testIsUpToDateReturnsFalseWhenSourceIsNewer(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        mkdir($cacheDir, 0755, true);
        $cache = new TemplateCache($cacheDir);

        // Write the cached file with an mtime 60 seconds in the past
        $cachedPath = $cache->getCachedPath($this->sourceFile);
        file_put_contents($cachedPath, '<?php ?>');
        $sourceMtime = filemtime($this->sourceFile);
        touch($cachedPath, $sourceMtime - 60);

        // Act + Assert
        $this->assertFalse($cache->isUpToDate($this->sourceFile));
    }

    // =========================================================================
    // store
    // =========================================================================

    /**
     * store() creates the cache directory if it doesn't exist, then writes the
     * compiled PHP and returns its absolute path.
     */
    public function testStoreCreatesDirectoryAndWritesFile(): void
    {
        // Arrange — cache dir does not exist yet
        $cacheDir = $this->tmpDir . '/new_cache';
        $cache    = new TemplateCache($cacheDir);

        // Act
        $written = $cache->store($this->sourceFile, '<?php echo 1; ?>');

        // Assert
        $this->assertFileExists($written);
        $this->assertSame('<?php echo 1; ?>', file_get_contents($written));
    }

    /**
     * store() returns a path that is consistent with getCachedPath() for the
     * same source file — i.e. resolve() and direct store() target the same file.
     */
    public function testStorePathMatchesGetCachedPath(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        // Act
        $stored  = $cache->store($this->sourceFile, '<?php ?>');
        $derived = $cache->getCachedPath($this->sourceFile);

        // Assert
        $this->assertSame($derived, $stored);
    }

    // =========================================================================
    // resolve
    // =========================================================================

    /**
     * resolve() calls $compiler on cache miss and stores the result.
     * The callable is invoked with the source string and the return value is
     * written to the cache.
     */
    public function testResolveCompilesCacheOnMiss(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);
        $compiled = '<?php echo "compiled"; ?>';

        // Act
        $path = $cache->resolve($this->sourceFile, fn(string $src) => $compiled);

        // Assert — the file was written and contains the compiled output
        $this->assertFileExists($path);
        $this->assertSame($compiled, file_get_contents($path));
    }

    /**
     * resolve() does NOT call $compiler when the cached file is already fresh.
     * This ensures templates are not recompiled on every request in production.
     */
    public function testResolveSkipsCompilerOnCacheHit(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        mkdir($cacheDir, 0755, true);
        $cache      = new TemplateCache($cacheDir);

        // Pre-populate cache with a fresh copy (mtime + 60)
        $cachedPath = $cache->getCachedPath($this->sourceFile);
        file_put_contents($cachedPath, '<?php /* cached */ ?>');
        touch($cachedPath, filemtime($this->sourceFile) + 60);

        $compilerCalled = false;

        // Act
        $path = $cache->resolve($this->sourceFile, function (string $src) use (&$compilerCalled): string {
            $compilerCalled = true;
            return '<?php /* recompiled */ ?>';
        });

        // Assert — compiler was not called, cached content unchanged
        $this->assertFalse($compilerCalled, 'Compiler must not be called on cache hit');
        $this->assertSame('<?php /* cached */ ?>', file_get_contents($path));
    }

    /**
     * resolve() returns a consistent path regardless of whether a compilation
     * happened; callers use the return value as the include path.
     */
    public function testResolveReturnsCachedPath(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        // Act
        $resolved = $cache->resolve($this->sourceFile, fn(string $s) => '<?php ?>');
        $direct   = $cache->getCachedPath($this->sourceFile);

        // Assert
        $this->assertSame($direct, $resolved);
    }

    // =========================================================================
    // flush
    // =========================================================================

    /**
     * flush() deletes all .php files from the cache directory.
     * After flush(), every subsequent resolve() will recompile.
     */
    public function testFlushDeletesCompiledFiles(): void
    {
        // Arrange — populate the cache with two compiled files
        $cacheDir = $this->tmpDir . '/cache';
        $cache    = new TemplateCache($cacheDir);

        $srcA = $this->tmpDir . '/source/a.tpl.php';
        $srcB = $this->tmpDir . '/source/b.tpl.php';
        file_put_contents($srcA, 'A');
        file_put_contents($srcB, 'B');

        $cache->store($srcA, '<?php echo "A"; ?>');
        $cache->store($srcB, '<?php echo "B"; ?>');

        $this->assertCount(2, glob($cacheDir . '/*.php') ?: []);

        // Act
        $cache->flush();

        // Assert — no .php files remain
        $this->assertCount(0, glob($cacheDir . '/*.php') ?: []);
    }

    /**
     * flush() on a non-existent cache directory must not throw.
     * This makes it safe to call flush() during bootstrap before any template
     * has ever been compiled.
     */
    public function testFlushOnMissingDirectoryDoesNotThrow(): void
    {
        // Arrange — directory does not exist
        $cacheDir = $this->tmpDir . '/does_not_exist';
        $cache    = new TemplateCache($cacheDir);

        // Act + Assert — no exception
        $cache->flush();
        $this->assertTrue(true); // reached without exception
    }

    // =========================================================================
    // setCacheDir
    // =========================================================================

    /**
     * setCacheDir() overrides the cache directory after construction.
     * Useful when the cache path is computed lazily (e.g. after the application
     * boots and ROOT becomes available).
     */
    public function testSetCacheDirOverridesDirectory(): void
    {
        // Arrange
        $cache    = new TemplateCache($this->tmpDir . '/old');
        $newDir   = $this->tmpDir . '/new';

        // Act
        $cache->setCacheDir($newDir);

        // Assert
        $this->assertSame($newDir, $cache->getCacheDir());
    }
}
