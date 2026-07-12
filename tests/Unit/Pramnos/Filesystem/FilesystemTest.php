<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Filesystem\Filesystem;

/**
 * Unit tests for Pramnos\Filesystem\Filesystem.
 *
 * All tests operate exclusively under sys_get_temp_dir() so nothing outside
 * /tmp is touched.  Each test creates its own isolated subdirectory and
 * tearDown() removes it unconditionally.
 *
 * Methods under test:
 *   getInstance(), clearDirectory(), destroyDirectory(),
 *   recurseCopy(), listDirectoryFiles(), removeFile()
 *
 * Genuinely unreachable branches (dead code in PHP 8.x):
 *   - clearDirectory(): catch(\Exception) — unlink() raises E_WARNING, not Exception
 *   - recurseCopy(): catch(\Exception) around mkdir() — same reason
 *   - destroyDirectory(): chmod+retry path — unlink() always succeeds as root in Docker
 */
#[CoversClass(Filesystem::class)]
class FilesystemTest extends TestCase
{
    private Filesystem $fs;
    private string     $tmpDir;

    protected function setUp(): void
    {
        $this->fs     = new Filesystem();
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_fs_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup; ignore errors so tearDown never masks a test failure.
        if (is_dir($this->tmpDir)) {
            $this->rmRecursive($this->tmpDir);
        }
    }

    // =========================================================================
    // getInstance
    // =========================================================================

    /**
     * getInstance() returns the same Filesystem object on every call (singleton).
     */
    public function testGetInstanceReturnsSingletonFilesystem(): void
    {
        // Act
        $a = Filesystem::getInstance();
        $b = Filesystem::getInstance();

        // Assert — same object reference
        $this->assertInstanceOf(Filesystem::class, $a);
        $this->assertSame($a, $b);
    }

    // =========================================================================
    // clearDirectory
    // =========================================================================

    /**
     * clearDirectory() returns false for a path that does not exist.
     * The caller must not assume the path was empty just because nothing is there.
     */
    public function testClearDirectoryReturnsFalseForNonExistentPath(): void
    {
        // Arrange
        $missing = $this->tmpDir . '/does_not_exist';

        // Act / Assert
        $this->assertFalse($this->fs->clearDirectory($missing));
    }

    /**
     * clearDirectory() returns false when the path is a regular file, not a dir.
     * Treating a file as a directory would be a silent data-loss bug.
     */
    public function testClearDirectoryReturnsFalseForFilePath(): void
    {
        // Arrange — create a regular file at the path
        $file = $this->tmpDir . '/afile.txt';
        file_put_contents($file, 'content');

        // Act / Assert
        $this->assertFalse($this->fs->clearDirectory($file));
    }

    /**
     * clearDirectory() returns true immediately for an empty directory.
     * Avoids unnecessary work when there is nothing to delete.
     */
    public function testClearDirectoryReturnsTrueForEmptyDirectory(): void
    {
        // Arrange — an empty directory (contains only . and ..)
        $emptyDir = $this->tmpDir . '/empty';
        mkdir($emptyDir);

        // Act / Assert
        $this->assertTrue($this->fs->clearDirectory($emptyDir));
        $this->assertDirectoryExists($emptyDir); // directory itself is kept
    }

    /**
     * clearDirectory() removes all files inside the directory and returns true,
     * but leaves the directory itself in place.
     */
    public function testClearDirectoryDeletesFilesAndReturnsTrue(): void
    {
        // Arrange
        $dir = $this->tmpDir . '/withfiles';
        mkdir($dir);
        file_put_contents($dir . '/a.txt', 'aaa');
        file_put_contents($dir . '/b.txt', 'bbb');

        // Act
        $result = $this->fs->clearDirectory($dir);

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryExists($dir);      // dir survives
        $this->assertFileDoesNotExist($dir . '/a.txt');
        $this->assertFileDoesNotExist($dir . '/b.txt');
    }

    /**
     * clearDirectory() recursively removes subdirectories and their contents.
     * This verifies the destroyDirectory() call inside clearDirectory().
     */
    public function testClearDirectoryRemovesSubdirectoriesRecursively(): void
    {
        // Arrange — dir/subdir/file.txt
        $dir    = $this->tmpDir . '/withsub';
        $subdir = $dir . '/subdir';
        mkdir($subdir, 0777, true);
        file_put_contents($subdir . '/nested.txt', 'x');

        // Act
        $result = $this->fs->clearDirectory($dir);

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryExists($dir);      // parent survives
        $this->assertDirectoryDoesNotExist($subdir);
    }

    // =========================================================================
    // destroyDirectory
    // =========================================================================

    /**
     * destroyDirectory() on a regular file (not a directory) calls unlink()
     * and returns true — the "leaf unlink" base-case of the recursion.
     */
    public function testDestroyDirectoryOnFileDeletesItAndReturnsTrue(): void
    {
        // Arrange
        $file = $this->tmpDir . '/todelete.txt';
        file_put_contents($file, 'delete me');

        // Act
        $result = $this->fs->destroyDirectory($file);

        // Assert
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($file);
    }

    /**
     * destroyDirectory() on a symbolic link unlinks the link itself without
     * following it into the target — the IS_LINK branch in the early return.
     */
    public function testDestroyDirectoryOnSymlinkRemovesLink(): void
    {
        // Arrange — create a real file and a symlink pointing to it
        $target = $this->tmpDir . '/target.txt';
        $link   = $this->tmpDir . '/link_to_target';
        file_put_contents($target, 'real file');
        symlink($target, $link);

        // Act
        $result = $this->fs->destroyDirectory($link);

        // Assert — link is gone; the original target is untouched
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($link);
        $this->assertFileExists($target);
    }

    /**
     * destroyDirectory() removes a directory and all its contents recursively.
     */
    public function testDestroyDirectoryRemovesDirAndContentsRecursively(): void
    {
        // Arrange — dir/sub/file.txt
        $dir  = $this->tmpDir . '/todelete_dir';
        $sub  = $dir . '/sub';
        mkdir($sub, 0777, true);
        file_put_contents($sub . '/file.txt', 'x');

        // Act
        $result = $this->fs->destroyDirectory($dir);

        // Assert — entire tree gone
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($dir);
    }

    /**
     * destroyDirectory() respects the $limit parameter and returns false when
     * the number of filesystem entries exceeds that limit.
     * This guard prevents runaway deletions of unexpectedly large trees.
     */
    public function testDestroyDirectoryReturnsFlaseWhenLimitExceeded(): void
    {
        // Arrange — directory with 3 files, limit set to 2 (. and .. plus one real file)
        $dir = $this->tmpDir . '/big';
        mkdir($dir);
        file_put_contents($dir . '/f1.txt', '1');
        file_put_contents($dir . '/f2.txt', '2');
        file_put_contents($dir . '/f3.txt', '3');

        // Act — limit=2 means only 2 iterations before the guard fires
        // (scandir returns ['.', '..', 'f1.txt', 'f2.txt', 'f3.txt'] = 5 entries,
        //  counter > 2 is hit on the third entry)
        $result = $this->fs->destroyDirectory($dir, 2);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * When an inner destroyDirectory() call fails (simulated via an anonymous
     * subclass), the parent calls chmod(0777) and retries — lines 90-91.
     * When the retry also fails, the method returns false — line 92.
     *
     * An anonymous subclass overrides destroyDirectory() to return false for
     * every file-level (leaf) call, simulating an undeletable file so the
     * chmod + retry path is exercised.
     */
    public function testDestroyDirectoryReturnsFalseWhenRetryAfterChmodAlsoFails(): void
    {
        // Arrange — directory with one file; the anonymous subclass forces failure
        $dir  = $this->tmpDir . '/chmodretry';
        $file = $dir . '/stuck.txt';
        mkdir($dir);
        file_put_contents($file, 'stuck');

        $fs = new class extends Filesystem {
            // Each call for a non-directory entry will fail until exhausted.
            public int $innerFailures = 2;

            public function destroyDirectory($dir, $limit = 100)
            {
                if ($this->innerFailures > 0 && !is_dir($dir) && !is_link($dir)) {
                    $this->innerFailures--;
                    return false; // Simulate "cannot delete file"
                }
                return parent::destroyDirectory($dir, $limit);
            }
        };

        // Act
        $result = $fs->destroyDirectory($dir);

        // Assert — both attempts failed so the method returns false (line 92)
        $this->assertFalse($result);

        // Manual cleanup since destroyDirectory left the directory intact
        @unlink($file);
        @rmdir($dir);
    }

    /**
     * destroyDirectory() with limit=0 means "no limit" — deletes everything.
     */
    public function testDestroyDirectoryWithLimitZeroMeansUnlimited(): void
    {
        // Arrange — many files
        $dir = $this->tmpDir . '/unlimited';
        mkdir($dir);
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($dir . "/f{$i}.txt", (string) $i);
        }

        // Act
        $result = $this->fs->destroyDirectory($dir, 0);

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($dir);
    }

    // =========================================================================
    // recurseCopy
    // =========================================================================

    /**
     * recurseCopy() copies all files from $src to a new $dst directory.
     * Returns true when all copies succeed.
     */
    public function testRecurseCopyCreatesDestinationAndCopiesFiles(): void
    {
        // Arrange
        $src = $this->tmpDir . '/src';
        $dst = $this->tmpDir . '/dst';
        mkdir($src);
        file_put_contents($src . '/hello.txt', 'hello');
        file_put_contents($src . '/world.txt', 'world');

        // Act
        $result = $this->fs->recurseCopy($src, $dst);

        // Assert
        $this->assertTrue($result);
        $this->assertFileExists($dst . '/hello.txt');
        $this->assertSame('hello', file_get_contents($dst . '/hello.txt'));
        $this->assertFileExists($dst . '/world.txt');
    }

    /**
     * recurseCopy() copies into an already-existing destination directory
     * without error — it does not require the destination to be absent.
     */
    public function testRecurseCopyIntoExistingDestination(): void
    {
        // Arrange — dst already exists
        $src = $this->tmpDir . '/src2';
        $dst = $this->tmpDir . '/dst2';
        mkdir($src);
        mkdir($dst);
        file_put_contents($src . '/newfile.txt', 'new');

        // Act
        $result = $this->fs->recurseCopy($src, $dst);

        // Assert — file copied into existing dir
        $this->assertTrue($result);
        $this->assertFileExists($dst . '/newfile.txt');
    }

    /**
     * recurseCopy() with $overwrite=false returns false when a destination
     * file already exists, leaving the original intact.
     */
    public function testRecurseCopyReturnsFalseWhenFileExistsAndOverwriteFalse(): void
    {
        // Arrange — same file in both src and dst
        $src = $this->tmpDir . '/src3';
        $dst = $this->tmpDir . '/dst3';
        mkdir($src);
        mkdir($dst);
        file_put_contents($src . '/file.txt', 'new');
        file_put_contents($dst . '/file.txt', 'original');

        // Act
        $result = $this->fs->recurseCopy($src, $dst, false);

        // Assert — returns false; original content preserved
        $this->assertFalse($result);
        $this->assertSame('original', file_get_contents($dst . '/file.txt'));
    }

    /**
     * recurseCopy() with $overwrite=true replaces existing destination files.
     */
    public function testRecurseCopyOverwritesExistingFilesWhenOverwriteTrue(): void
    {
        // Arrange
        $src = $this->tmpDir . '/src4';
        $dst = $this->tmpDir . '/dst4';
        mkdir($src);
        mkdir($dst);
        file_put_contents($src . '/file.txt', 'updated');
        file_put_contents($dst . '/file.txt', 'old');

        // Act
        $result = $this->fs->recurseCopy($src, $dst, true);

        // Assert — returns true; file replaced
        $this->assertTrue($result);
        $this->assertSame('updated', file_get_contents($dst . '/file.txt'));
    }

    /**
     * recurseCopy() returns false when copy() itself fails.
     *
     * The failure is provoked without filesystem-permission tricks: a directory
     * is placed at the destination path where the source file would land.
     * PHP's copy() cannot write into a path that is already a directory, so
     * it returns false → $return = false (line 129 in Filesystem.php).
     */
    public function testRecurseCopyReturnsFalseWhenCopyFails(): void
    {
        // Arrange
        $src = $this->tmpDir . '/srcfail';
        $dst = $this->tmpDir . '/dstfail';
        mkdir($src);
        mkdir($dst);
        file_put_contents($src . '/file.txt', 'data');
        // Block the destination path: place a directory where the file would go
        mkdir($dst . '/file.txt');

        // Act — overwrite=true so the code reaches copy() rather than the
        // "file exists, no overwrite" branch.
        // copy() emits E_WARNING when the destination is a directory; suppress
        // it here because the warning is the deliberate mechanism under test.
        $result = @$this->fs->recurseCopy($src, $dst, true);

        // Assert — copy() failed, so recurseCopy returns false
        $this->assertFalse($result);
    }

    /**
     * recurseCopy() recurses into subdirectories, mirroring the full tree.
     */
    public function testRecurseCopyCopiesSubdirectoriesRecursively(): void
    {
        // Arrange — src/sub/deep.txt
        $src = $this->tmpDir . '/src5';
        $sub = $src . '/sub';
        $dst = $this->tmpDir . '/dst5';
        mkdir($sub, 0777, true);
        file_put_contents($sub . '/deep.txt', 'deep content');

        // Act
        $result = $this->fs->recurseCopy($src, $dst);

        // Assert
        $this->assertTrue($result);
        $this->assertFileExists($dst . '/sub/deep.txt');
        $this->assertSame('deep content', file_get_contents($dst . '/sub/deep.txt'));
    }

    // =========================================================================
    // listDirectoryFiles
    // =========================================================================

    /**
     * listDirectoryFiles() returns a flat list of all files under a directory.
     */
    public function testListDirectoryFilesReturnsFlatListForFlatDir(): void
    {
        // Arrange
        $dir = $this->tmpDir . '/flat';
        mkdir($dir);
        file_put_contents($dir . '/a.txt', 'a');
        file_put_contents($dir . '/b.txt', 'b');

        // Act
        $result = $this->fs->listDirectoryFiles($dir);

        // Assert — returns an array containing both files
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains($dir . DS . 'a.txt', $result);
        $this->assertContains($dir . DS . 'b.txt', $result);
    }

    /**
     * listDirectoryFiles() descends into subdirectories and returns all leaf
     * file paths (no directory entries in the result).
     */
    public function testListDirectoryFilesDescendsIntoSubdirectories(): void
    {
        // Arrange — root/file.txt and root/sub/nested.txt
        $dir = $this->tmpDir . '/nested';
        $sub = $dir . '/sub';
        mkdir($sub, 0777, true);
        file_put_contents($dir . '/file.txt', 'root file');
        file_put_contents($sub . '/nested.txt', 'nested file');

        // Act
        $result = $this->fs->listDirectoryFiles($dir);

        // Assert — both files returned, no directory entries
        $this->assertCount(2, $result);
        $this->assertContains($dir . DS . 'file.txt', $result);
        $this->assertContains($dir . DS . 'sub' . DS . 'nested.txt', $result);
    }

    /**
     * listDirectoryFiles() returns an empty array for a directory with no files.
     */
    public function testListDirectoryFilesReturnsEmptyArrayForEmptyDir(): void
    {
        // Arrange
        $dir = $this->tmpDir . '/emptylist';
        mkdir($dir);

        // Act / Assert
        $this->assertSame([], $this->fs->listDirectoryFiles($dir));
    }

    // =========================================================================
    // removeFile
    // =========================================================================

    /**
     * removeFile() returns false for a path that does not exist.
     * Callers can distinguish "file was there" from "file was gone already".
     */
    public function testRemoveFileReturnsFalseForNonExistentFile(): void
    {
        // Act / Assert
        $this->assertFalse($this->fs->removeFile($this->tmpDir . '/no_such_file.txt'));
    }

    /**
     * removeFile() returns false when the path is a directory, not a file.
     * Prevents accidental directory removal through the file API.
     */
    public function testRemoveFileReturnsFalseForDirectory(): void
    {
        // Arrange
        $dir = $this->tmpDir . '/adir';
        mkdir($dir);

        // Act / Assert
        $this->assertFalse($this->fs->removeFile($dir));
    }

    /**
     * removeFile() deletes an existing regular file and returns true.
     */
    public function testRemoveFileDeletesFileAndReturnsTrue(): void
    {
        // Arrange
        $file = $this->tmpDir . '/toremove.txt';
        file_put_contents($file, 'bye');

        // Act
        $result = $this->fs->removeFile($file);

        // Assert
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($file);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Recursive directory removal used only by tearDown(). */
    private function rmRecursive(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->rmRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
