<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\FileAdapter;

/**
 * A cache directory that disappears while it is being listed must not break the caller.
 *
 * `listDirectoryFiles()` already guarded with `is_dir()`, and a guard cannot fix a race: the
 * directory can go **between** the check and the iterator — another request flushing the same
 * group, or this adapter's own `cleanEmptyDirectories()` from a concurrent call — and then
 * `RecursiveDirectoryIterator` throws.
 *
 * Observed rather than imagined, in a coverage run of this framework's own suite:
 *
 * ```
 * UnexpectedValueException: RecursiveDirectoryIterator::__construct(…/var/cache/userlist):
 *     Failed to open directory: No such file or directory
 *   FileAdapter.php:610 → Cache.php:728 → Database.php:2673 → User.php:755
 *   ← User::activate()
 * ```
 *
 * The last line is the point. The throw did not break a cache flush — it broke a **user
 * activation**. `save()` flushes the user list, the flush raised, and the operation somebody
 * asked for failed because of housekeeping that had *already succeeded*: the directory was gone,
 * which is the state the flush wanted.
 *
 * A race cannot be reproduced by arranging files, so `directoryIterator()` is a seam. Making it
 * throw is the only honest way to cover a `catch` for something that happens between two
 * statements — and six call sites in the adapter go through it.
 */
#[CoversClass(FileAdapter::class)]
class FileAdapterVanishingDirectoryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-cache-race-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    /** An adapter whose directory walk fails the way a vanished directory makes it fail. */
    private function racingAdapter(): FileAdapter
    {
        return new class ($this->dir) extends FileAdapter {
            public int $walks = 0;

            protected function directoryIterator($path)
            {
                $this->walks++;

                throw new \UnexpectedValueException(
                    'RecursiveDirectoryIterator::__construct(' . $path
                    . '): Failed to open directory: No such file or directory'
                );
            }
        };
    }

    /**
     * A flush over a directory that vanishes mid-walk succeeds and reports nothing wrong.
     *
     * This is the assertion that would have saved the user activation: the caller of a flush is
     * almost always in the middle of something else, and a raise here fails that instead.
     */
    public function testAFlushSurvivesTheDirectoryDisappearing(): void
    {
        // Arrange
        $adapter = $this->racingAdapter();
        $adapter->connect();
        $adapter->setCategory('userlist');
        $adapter->save('entry', ['a' => 1], 3600);

        // Act
        $adapter->clear('userlist');

        // Assert
        $this->assertGreaterThan(0, $adapter->walks, 'the walk was never attempted');
        $this->addToAssertionCount(1);
    }

    /** And a flush of everything, which takes the other pruning branch. */
    public function testAFullFlushSurvivesItToo(): void
    {
        // Arrange
        $adapter = $this->racingAdapter();
        $adapter->connect();
        $adapter->setCategory('userlist');
        $adapter->save('entry', ['a' => 1], 3600);

        // Act
        $adapter->clear('');

        // Assert
        $this->assertGreaterThan(0, $adapter->walks);
    }

    /**
     * `getStats()` answers rather than raising.
     *
     * It is read by a dashboard, and a dashboard that 500s because a cache directory was being
     * cleaned at that moment is a dashboard nobody trusts about anything else either.
     */
    public function testStatsAnswerWhileTheCacheIsBeingCleaned(): void
    {
        // Arrange
        $adapter = $this->racingAdapter();
        $adapter->connect();

        // Act
        $stats = $adapter->getStats();

        // Assert
        $this->assertIsArray($stats);
    }

    /**
     * A missing directory is still the cheap path — no walk attempted at all.
     *
     * The `is_dir()` guard is not redundant now there is a `catch`: it is the common case, and
     * an exception per flush of a group that was never written would be a cost paid constantly
     * for a condition that is normal.
     */
    public function testAMissingDirectoryIsNotWalkedAtAll(): void
    {
        // Arrange
        $adapter = $this->racingAdapter();
        $adapter->connect();

        // Act
        $adapter->clear('a-group-nothing-ever-wrote-to');

        // Assert
        $this->assertSame(0, $adapter->walks, 'a missing directory should not reach the iterator');
    }

    /**
     * And with no race, the files really are listed.
     *
     * So the tests above cannot be passing because the walk returns nothing in every case.
     */
    public function testWithoutARaceTheFilesAreFound(): void
    {
        // Arrange
        $adapter = new FileAdapter($this->dir);
        $adapter->connect();
        $adapter->setCategory('userlist');
        $adapter->save('entry', ['a' => 1], 3600);

        // Act
        $adapter->clear('userlist');

        // Assert
        $this->assertFalse(
            $adapter->load('entry', 'userlist'),
            'the entry survived a flush, so the walk found nothing'
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
