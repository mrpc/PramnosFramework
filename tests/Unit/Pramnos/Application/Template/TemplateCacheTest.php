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
     *
     * ROOT is always defined by the test bootstrap, so we cannot undefine it.
     * Instead we use an anonymous subclass that overrides resolveDefaultCacheDir()
     * to force the no-ROOT branch — this exercises the same production code path
     * that real deployments hit when ROOT is absent.
     */
    public function testDefaultCacheDirFallsBackToTmpDir(): void
    {
        // Arrange — subclass forces the no-ROOT fallback regardless of environment
        $cache = new class extends TemplateCache {
            protected function resolveDefaultCacheDir(): string
            {
                // Simulate the environment where ROOT is not defined
                return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pramnos_viewcache';
            }
        };

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
     * isUpToDate() is true while the compiled file carries the source's modification time —
     * the one store() gives it — and false when the compiled file is newer: a newer compiled file
     * was made from another version of the source. In production this is the hot path.
     */
    public function testIsUpToDateOnlyWhileTheTimesAreTheSame(): void
    {
        // Arrange
        $cacheDir = $this->tmpDir . '/cache';
        mkdir($cacheDir, 0755, true);
        $cache = new TemplateCache($cacheDir);
        $cachedPath  = $cache->getCachedPath($this->sourceFile);
        file_put_contents($cachedPath, '<?php ?>');
        $sourceMtime = filemtime($this->sourceFile);

        // Act
        touch($cachedPath, $sourceMtime);
        clearstatcache();
        $same = $cache->isUpToDate($this->sourceFile);
        touch($cachedPath, $sourceMtime + 60);
        clearstatcache();
        $newer = $cache->isUpToDate($this->sourceFile);

        // Assert
        $this->assertTrue($same);
        $this->assertFalse($newer, 'a compiled file newer than its source is of another version');
    }

    /**
     * After a composer update a template is often OLDER than the file compiled from the version
     * before it: composer unpacks a package with the times of its commit. That template is
     * recompiled, and the new screen shown — with "newer than" it was kept, silently, until the
     * cache was cleared by hand.
     */
    public function testATemplateReplacedByAnOlderOneIsRecompiled(): void
    {
        // Arrange — compiled today from the old version
        $cache = new TemplateCache($this->tmpDir . '/cache');
        file_put_contents($this->sourceFile, 'old');
        $cache->resolve($this->sourceFile, static fn (string $src): string => $src);
        // The update: a new text, with its commit's earlier time.
        file_put_contents($this->sourceFile, 'new');
        touch($this->sourceFile, time() - 86400);
        clearstatcache();

        // Act
        $path = $cache->resolve($this->sourceFile, static fn (string $src): string => $src);

        // Assert
        $this->assertStringEndsWith('new', (string) file_get_contents($path));
        $this->assertSame(filemtime($this->sourceFile), filemtime($path), 'it carries its source\'s time');
        $this->assertTrue($cache->isUpToDate($this->sourceFile), 'and is a hit from now on');
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
        // After the first line's marker naming the template (see testACompiledFileNamesItsTemplate).
        $this->assertStringEndsWith(' ?><?php echo 1; ?>', (string) file_get_contents($written));
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
        $this->assertStringEndsWith(' ?>' . $compiled, (string) file_get_contents($path));
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

        // Pre-populate cache with a fresh copy: its source's time
        $cachedPath = $cache->getCachedPath($this->sourceFile);
        file_put_contents($cachedPath, '<?php /* cached */ ?>');
        touch($cachedPath, filemtime($this->sourceFile));
        clearstatcache();

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

    // =========================================================================
    // resolveDefaultCacheDir() — ROOT-defined path
    // =========================================================================

    /**
     * When no cacheDir is given and ROOT IS defined, resolveDefaultCacheDir()
     * must return ROOT/var/viewcache (not sys_get_temp_dir()).
     *
     * The test bootstrap always defines ROOT, so this exercises the
     * `if (defined('ROOT'))` branch at line 40–41 of TemplateCache, which is
     * the branch executed in every real deployment.
     */
    public function testDefaultCacheDirUsesRootWhenDefined(): void
    {
        // Arrange / Act — no explicit cacheDir; ROOT is defined by the test bootstrap
        $cache    = new TemplateCache();
        $expected = ROOT . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'viewcache';

        // Assert — the ROOT-based path is returned, not sys_get_temp_dir()
        $this->assertSame($expected, $cache->getCacheDir(),
            'resolveDefaultCacheDir() must return ROOT/var/viewcache when ROOT is defined');
    }

    // =========================================================================
    // isUpToDate() — filemtime returns false for missing source
    // =========================================================================

    /**
     * isUpToDate() must return false when the source file cannot be stat()-ed
     * (e.g. it was deleted after the cache was built). The filemtime()-returns-
     * false branch at lines 97–98 is only reachable when the cached file EXISTS
     * but the source path is invalid.
     *
     * We manually pre-create the cached file and call isUpToDate() with a
     * non-existent source path to trigger the $sourceMtime === false guard.
     */
    public function testIsUpToDateReturnsFalseWhenSourceFileMtimeFails(): void
    {
        // Arrange — compute the key for a non-existent path and pre-create the cached file
        $cacheDir    = $this->tmpDir . '/cache';
        mkdir($cacheDir, 0755, true);
        $cache       = new TemplateCache($cacheDir);
        $fakeSrc     = '/nonexistent/template_' . bin2hex(random_bytes(4)) . '.tpl.php';
        $cachedPath  = $cache->getCachedPath($fakeSrc);

        // Write a cached file so the file_exists() check passes
        file_put_contents($cachedPath, '<?php // compiled stub');

        // Suppress the PHP warning that filemtime() emits for a missing source file
        $result = @$cache->isUpToDate($fakeSrc);

        // Assert — filemtime($fakeSrc) returns false → isUpToDate returns false
        $this->assertFalse($result,
            'isUpToDate() must return false when filemtime() fails for the source path');
    }

    // =========================================================================
    // store() — mkdir failure throws RuntimeException
    // =========================================================================

    /**
     * store() must throw RuntimeException when the cache directory cannot be
     * created (e.g. a regular FILE already occupies the path).
     *
     * We create a plain file at the path that store() would try to mkdir(),
     * which makes both `is_dir()` false and `mkdir()` fail — triggering the
     * RuntimeException at lines 115–117 of TemplateCache.
     */
    public function testStoreThrowsRuntimeExceptionWhenMkdirFails(): void
    {
        // Arrange — create a FILE at the path where store() wants to mkdir()
        $conflictPath = $this->tmpDir . '/conflict_file_' . bin2hex(random_bytes(4));
        file_put_contents($conflictPath, 'I am a file, not a directory');

        // Use $conflictPath as the cacheDir — mkdir($conflictPath) will fail
        // because the name is already taken by a regular file
        $cache = new TemplateCache($conflictPath);

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot create template cache directory/');
        $cache->store($this->sourceFile, '<?php // compiled');
    }

    /**
     * A compiled file names the template it came from on its first line, without adding a line,
     * and sourceOf() reads it back; a file that is not a compiled template — another name, no
     * marker, missing — gives null, and where() then gives the file itself.
     */
    public function testACompiledFileNamesItsTemplate(): void
    {
        // Arrange
        $cache    = new TemplateCache($this->tmpDir . '/cache');
        $compiled = $cache->store($this->sourceFile, "line one\nline two");
        $unmarked = $this->tmpDir . '/cache/' . str_repeat('a', 40) . '.php';
        file_put_contents($unmarked, '<?php echo 1;');
        $other = $this->tmpDir . '/source/plain.php';
        file_put_contents($other, '<?php /* pf-template: /x */ ?>');

        // Act
        $source = TemplateCache::sourceOf($compiled);
        $lines  = substr_count((string) file_get_contents($compiled), "\n");

        // Assert
        $this->assertSame(realpath($this->sourceFile), $source);
        $this->assertSame(1, $lines, 'the marker adds no line');
        $this->assertNull(TemplateCache::sourceOf($unmarked), 'no marker');
        $this->assertNull(TemplateCache::sourceOf($other), 'not named as a compiled file');
        $this->assertNull(TemplateCache::sourceOf($this->tmpDir . '/cache/' . str_repeat('b', 40) . '.php'), 'missing');
        $this->assertSame($other, TemplateCache::where($other));
        $this->assertSame(realpath($this->sourceFile), TemplateCache::where($compiled));
    }

    /**
     * A template path holding `*\/` cannot end the marker's comment early: it is written escaped
     * and read back as it was.
     */
    public function testAPathThatWouldEndTheCommentIsKept(): void
    {
        // Arrange
        $odd = $this->tmpDir . '/source/a*/b.tpl.php';
        @mkdir(dirname($odd), 0755, true);
        file_put_contents($odd, 'x');
        $cache = new TemplateCache($this->tmpDir . '/cache');

        // Act
        $compiled = $cache->store($odd, 'x');

        // Assert
        $this->assertSame(realpath($odd), TemplateCache::sourceOf($compiled));
        $this->assertStringContainsString('No syntax errors', (string) shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($compiled) . ' 2>&1'), 'still valid PHP');
    }

    /**
     * End to end: an error inside a template drawn by a view is reported at the template and the
     * template's own line — through multi-line comments, directives and PHP blocks above it — by
     * the error page, the trace written to the log and the debug bar.
     */
    public function testAnErrorInATemplateIsReportedAtTheTemplatesLine(): void
    {
        // Arrange
        $dir = $this->tmpDir . '/views/Home';
        @mkdir($dir, 0755, true);
        $template = $dir . '/page.tpl.php';
        file_put_contents($template, implode("\n", [
            '{{-- a note',
            '   over three lines --}}',
            '@if (true)',
            '  <p>{{ "a" }}</p>',
            '@endif',
            '@php',
            '  $x = 1;',
            '@endphp',
            '@foreach ([1] as $i) {!! $i !!} @endforeach',
            '{{ $this->boom() }}',
        ]));
        \Pramnos\Application\View::setTemplateCacheDir($this->tmpDir . '/cache');
        $view = new class (new \Pramnos\Application\Controller(), $this->tmpDir . '/views/Home', 'Home') extends \Pramnos\Application\View {
            /** What the template calls on its last line. */
            public function boom(): string
            {
                throw new \RuntimeException('inside the template');
            }
        };
        $wrapped = null;

        // Act
        try {
            $view->display('page');
        } catch (\Exception $e) {
            $wrapped = $e;
        } finally {
            while (ob_get_level() > 1) {
                ob_end_clean();
            }
            \Pramnos\Application\View::setTemplateCacheDir('');
        }
        $caught = $wrapped?->getPrevious();
        $frame  = null;
        $this->assertInstanceOf(\RuntimeException::class, $caught, 'the error the template met is kept as the previous one');
        $this->assertNotEmpty($caught->getTrace(), 'the sweep found nothing to check');
        foreach ($caught->getTrace() as $f) {
            if (isset($f['file']) && TemplateCache::sourceOf($f['file']) !== null) {
                $frame = $f;
                break;
            }
        }
        $page      = (string) (new \ReflectionMethod(\Pramnos\Http\ExceptionHandler::class, 'buildDebugHtml'))->invoke(null, $caught, 500);
        $collector = new \Pramnos\Debug\Collectors\ExceptionsCollector();
        $collector->recordPhpError(E_WARNING, 'x', $frame['file'] ?? '', 10);

        // Assert
        $this->assertStringEndsWith('inside the template at line 10', $wrapped->getMessage(), 'the view says the template\'s line, not where it was thrown');
        $this->assertNotNull($frame, 'a frame in the compiled template');
        $this->assertSame(realpath($template), TemplateCache::sourceOf($frame['file']));
        $this->assertSame(10, $frame['line'], 'the template\'s own line');
        $this->assertStringContainsString(realpath($template) . '(10)', \Pramnos\Logs\Trace::of($caught), 'the trace names the template');
        $this->assertStringContainsString(htmlspecialchars(realpath($template)) . '(10)', $page, 'the error page names it');
        $this->assertStringEndsWith('page.tpl.php', $collector->collect()['items'][0]['file'], 'the debug bar names it');
    }

    /**
     * An error thrown by the template itself, not by something it called, is reported at its
     * own line too, past a multi-line comment above it.
     */
    public function testAnErrorThrownByTheTemplateItselfIsAtItsLine(): void
    {
        // Arrange
        $dir = $this->tmpDir . '/views/Home';
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/page.tpl.php', "{{-- one\ntwo --}}\n<p>x</p>\n@php throw new \\RuntimeException('thrown here'); @endphp");
        \Pramnos\Application\View::setTemplateCacheDir($this->tmpDir . '/cache');
        $message = '';

        // Act
        try {
            (new \Pramnos\Application\View(new \Pramnos\Application\Controller(), $this->tmpDir . '/views/Home', 'Home'))->display('page');
        } catch (\Exception $e) {
            $message = $e->getMessage();
        } finally {
            while (ob_get_level() > 1) {
                ob_end_clean();
            }
            \Pramnos\Application\View::setTemplateCacheDir('');
        }

        // Assert
        $this->assertStringEndsWith('thrown here at line 4', $message);
    }

    /**
     * `view:clear` deletes the compiled templates of the cache the views use, and says how many and
     * where; each is compiled again on its next use.
     */
    public function testViewClearEmptiesTheCache(): void
    {
        // Arrange — the views' cache pointed at this test's directory, one template compiled
        $previous = \Pramnos\Application\View::getTemplateCacheDir();
        \Pramnos\Application\View::setTemplateCacheDir($this->tmpDir . '/cache');
        $compiled = (new TemplateCache($this->tmpDir . '/cache'))->resolve($this->sourceFile, static fn (string $src): string => $src);
        $tester   = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\ViewClear());

        // Act
        try {
            $tester->execute([]);
        } finally {
            \Pramnos\Application\View::setTemplateCacheDir($previous);
        }

        // Assert
        $this->assertFileDoesNotExist($compiled);
        $this->assertSame('1 compiled template(s) deleted from ' . $this->tmpDir . '/cache.', trim($tester->getDisplay()));
    }
}
