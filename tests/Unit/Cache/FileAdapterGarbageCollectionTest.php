<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\FileAdapter;

/**
 * Clearing one cache category must not cost a walk of the whole cache.
 *
 * `clear()` swept the entire tree on every call, reading and unserialising each
 * file to decide whether it had expired. `Database::cacheflush()` calls `clear()`,
 * and every model save calls `cacheflush()` — so writing one row cost an
 * inspection of everything cached.
 *
 * Measured at **1358 ms per call** on a cache holding no files at all: the walk
 * was of 3064 *empty directories*, which is the second half of the same bug.
 * `cleanup()` finished by calling `cleanEmptyDirectories($this->cacheDir)`, whose
 * first line returns when handed the root — it walks upward from a directory, so
 * giving it the root is a guaranteed no-op. Every directory a cache write created
 * stayed for good, and each one was walked again by the next sweep.
 *
 * Sweeping expired files is not a correctness requirement: `load()` checks the
 * timestamp before returning anything, so a stale file is never served. It only
 * reclaims disk, which is why it is sampled rather than done on every write.
 */
class FileAdapterGarbageCollectionTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-cache-gc-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    /**
     * An adapter whose garbage collection can be turned on and off by the test.
     */
    private function adapter(bool $collect): FileAdapter
    {
        return new class ($this->dir, $collect) extends FileAdapter {
            public int $sweeps = 0;

            public function __construct(string $dir, private bool $collect)
            {
                parent::__construct($dir, '');
            }

            protected function shouldCollectGarbage(): bool
            {
                return $this->collect;
            }

            protected function cleanup()
            {
                $this->sweeps++;
                parent::cleanup();
            }
        };
    }

    /**
     * Clearing does not sweep the tree every time.
     *
     * This is the whole fix: the sweep is O(everything cached) and `clear()` runs
     * on every model save.
     */
    public function testClearingDoesNotSweepEveryTime(): void
    {
        // Arrange
        $adapter = $this->adapter(false);

        // Act
        $adapter->clear('somecategory');

        // Assert
        $this->assertSame(0, $adapter->sweeps, 'clear() must not sweep the tree unconditionally');
    }

    /**
     * When it does sweep, it sweeps.
     *
     * Sampling would be indistinguishable from removing the sweep if the sampled
     * path never ran.
     */
    public function testTheSweepStillHappensWhenItIsDue(): void
    {
        // Arrange
        $adapter = $this->adapter(true);

        // Act
        $adapter->clear('somecategory');

        // Assert
        $this->assertSame(1, $adapter->sweeps);
    }

    /**
     * The sweep removes empty directories, which nothing used to.
     *
     * `cleanEmptyDirectories()` walks upward from the directory it is given, so
     * the old call — with the cache root — returned on its first line. The
     * directories a cache write creates were never reclaimed, and the tree only
     * grew.
     */
    public function testTheSweepRemovesEmptyDirectories(): void
    {
        // Arrange — the shape a cache write leaves behind
        $nested = $this->dir . '/prefix/category/deeper';
        mkdir($nested, 0777, true);
        $this->assertDirectoryExists($nested, 'precondition');

        // Act
        $this->adapter(true)->clear('category');

        // Assert
        $this->assertDirectoryDoesNotExist($nested, 'an empty directory must be reclaimed');
        $this->assertDirectoryDoesNotExist($this->dir . '/prefix');
        $this->assertDirectoryExists($this->dir, 'but the cache root itself stays');
    }

    /**
     * A directory that still holds a file is left alone.
     *
     * Pruning has to stop at the first thing anybody might still read.
     */
    public function testADirectoryWithAFileInItIsKept(): void
    {
        // Arrange
        $kept = $this->dir . '/prefix/keepme';
        mkdir($kept, 0777, true);
        file_put_contents($kept . '/entry.txt', 'not a cache entry, and not expired');

        // Act
        $this->adapter(true)->clear('category');

        // Assert
        $this->assertDirectoryExists($kept);
        $this->assertFileExists($kept . '/entry.txt');
    }

    /**
     * Clearing a cache on a tree of empty directories is fast.
     *
     * The number is the point: this is the operation every model save performs,
     * and it used to be linear in the size of the whole cache. A budget rather
     * than a benchmark — generous enough not to fail on a slow machine, tight
     * enough that a return to walking the tree cannot pass.
     */
    public function testClearingIsNotLinearInTheSizeOfTheCache(): void
    {
        // Arrange — 300 directories the old sweep would have walked on every call
        for ($i = 0; $i < 300; $i++) {
            mkdir($this->dir . '/prefix/bucket' . $i, 0777, true);
        }
        $adapter = $this->adapter(false);

        // Act
        $start = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $adapter->clear('somecategory');
        }
        $perCall = (microtime(true) - $start) * 1000 / 20;

        // Assert
        $this->assertLessThan(
            20.0,
            $perCall,
            sprintf('clear() took %.1f ms per call — it is walking the tree again', $perCall)
        );
    }
}
