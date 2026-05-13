<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Storage\Drivers\LocalDriver;

/**
 * Unit tests for Pramnos\Storage\Drivers\LocalDriver.
 *
 * LocalDriver implements StorageInterface against a real local filesystem.
 * Tests use a temporary directory created per-test, guaranteeing hermetic runs.
 *
 * Tests verify:
 *   - Constructor: throws InvalidArgumentException when 'root' is missing.
 *   - put() / get(): round-trip for string content; nested path creates dirs.
 *   - put() with a resource stream writes the stream content correctly.
 *   - exists() / missing(): reflect the actual filesystem state.
 *   - append(): concatenates content onto an existing file.
 *   - prepend(): inserts content before existing content.
 *   - size(): returns the byte count of a written file.
 *   - lastModified(): returns a positive integer (Unix timestamp).
 *   - mimeType(): returns false for a non-existent path, valid string otherwise.
 *   - delete(): removes a file; re-read returns false.
 *   - move(): relocates a file; old path gone, new path present.
 *   - copy(): duplicates a file; both source and destination exist.
 *   - files(): lists only files (not dirs) in a directory.
 *   - allFiles(): recursively lists all files under a directory.
 *   - directories(): lists only subdirectories.
 *   - makeDirectory(): creates a subdirectory.
 *   - deleteDirectory(): removes a directory and its contents.
 *   - url(): composes base URL + path; throws when no 'url' config key.
 *   - temporaryUrl(): always throws RuntimeException.
 *   - get() / size() / lastModified() / move() / readStream(): throw for missing files.
 */
#[CoversClass(LocalDriver::class)]
class LocalDriverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pramnos_ld_test_' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $item) {
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    private function makeDriver(array $extra = []): LocalDriver
    {
        return new LocalDriver(['root' => $this->root] + $extra);
    }

    // =========================================================================
    // Constructor validation
    // =========================================================================

    /**
     * Constructor throws InvalidArgumentException when the 'root' key is absent.
     */
    public function testConstructorThrowsWhenRootMissing(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        new LocalDriver([]);
    }

    /**
     * Constructor throws InvalidArgumentException when 'root' is an empty string.
     */
    public function testConstructorThrowsWhenRootIsEmptyString(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        new LocalDriver(['root' => '']);
    }

    // =========================================================================
    // put() / get()
    // =========================================================================

    /**
     * put() writes string content; get() retrieves the exact same string.
     */
    public function testPutAndGetRoundTripString(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Act
        $driver->put('hello.txt', 'world');
        $result = $driver->get('hello.txt');

        // Assert
        $this->assertSame('world', $result);
    }

    /**
     * put() creates intermediate directories automatically when the path
     * contains subdirectories that do not yet exist.
     */
    public function testPutCreatesIntermediateDirectories(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Act — nested path with two non-existent subdirs
        $driver->put('a/b/file.txt', 'deep content');

        // Assert — file was created
        $this->assertSame('deep content', $driver->get('a/b/file.txt'));
    }

    /**
     * put() with a resource (stream) copies the stream content to the file.
     */
    public function testPutWithResourceStream(): void
    {
        // Arrange
        $driver  = $this->makeDriver();
        $stream  = tmpfile();
        fwrite($stream, 'stream data');
        rewind($stream);

        // Act
        $driver->put('streamed.bin', $stream);
        fclose($stream);

        // Assert
        $this->assertSame('stream data', $driver->get('streamed.bin'));
    }

    /**
     * get() throws RuntimeException when the file does not exist.
     */
    public function testGetThrowsForMissingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->get('nonexistent.txt');
    }

    // =========================================================================
    // readStream()
    // =========================================================================

    /**
     * readStream() opens a readable stream resource for an existing file.
     */
    public function testReadStreamReturnsStreamForExistingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('streamable.txt', 'streamed content');

        // Act
        $stream = $driver->readStream('streamable.txt');

        // Assert — stream is a valid resource
        $this->assertIsResource($stream);
        $this->assertSame('streamed content', stream_get_contents($stream));
        fclose($stream);
    }

    /**
     * readStream() throws RuntimeException for a missing file.
     */
    public function testReadStreamThrowsForMissingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->readStream('ghost.txt');
    }

    // =========================================================================
    // exists() / missing()
    // =========================================================================

    /**
     * exists() returns true for a file that has been written.
     */
    public function testExistsReturnsTrueForWrittenFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('check.txt', 'here');

        // Assert
        $this->assertTrue($driver->exists('check.txt'));
    }

    /**
     * exists() returns false before a file is written.
     */
    public function testExistsReturnsFalseForMissingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->assertFalse($driver->exists('nope.txt'));
    }

    /**
     * missing() is the inverse of exists().
     */
    public function testMissingIsInverseOfExists(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert — initially missing
        $this->assertTrue($driver->missing('absent.txt'));

        // Act — create the file
        $driver->put('absent.txt', 'present');

        // Assert — no longer missing
        $this->assertFalse($driver->missing('absent.txt'));
    }

    // =========================================================================
    // append() / prepend()
    // =========================================================================

    /**
     * append() adds content to the end of an existing file.
     */
    public function testAppendAddsContentToEndOfFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('log.txt', 'line1\n');

        // Act
        $driver->append('log.txt', 'line2\n');

        // Assert
        $this->assertSame('line1\nline2\n', $driver->get('log.txt'));
    }

    /**
     * prepend() inserts content before existing content.
     */
    public function testPrependInsertsBeforeExistingContent(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('head.txt', 'tail');

        // Act
        $driver->prepend('head.txt', 'head');

        // Assert
        $this->assertSame('headtail', $driver->get('head.txt'));
    }

    /**
     * prepend() to a non-existent file effectively creates the file.
     */
    public function testPrependCreatesFileWhenMissing(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Act
        $driver->prepend('new.txt', 'initial');

        // Assert
        $this->assertSame('initial', $driver->get('new.txt'));
    }

    // =========================================================================
    // size() / lastModified() / mimeType()
    // =========================================================================

    /**
     * size() returns the byte count of a file.
     */
    public function testSizeReturnsFileByteCount(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('sized.txt', 'abcde'); // 5 bytes

        // Act / Assert
        $this->assertSame(5, $driver->size('sized.txt'));
    }

    /**
     * size() throws RuntimeException for a missing file.
     */
    public function testSizeThrowsForMissingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->size('ghost.txt');
    }

    /**
     * lastModified() returns a positive Unix timestamp for an existing file.
     */
    public function testLastModifiedReturnsPositiveTimestamp(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('ts.txt', 'x');

        // Act
        $ts = $driver->lastModified('ts.txt');

        // Assert — timestamp is in the past or now; definitely positive
        $this->assertGreaterThan(0, $ts);
        $this->assertLessThanOrEqual(time(), $ts);
    }

    /**
     * lastModified() throws RuntimeException for a missing file.
     */
    public function testLastModifiedThrowsForMissingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->lastModified('ghost.txt');
    }

    /**
     * mimeType() returns false for a path that does not exist.
     */
    public function testMimeTypeReturnsFalseForMissingPath(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Act / Assert
        $this->assertFalse($driver->mimeType('missing.txt'));
    }

    /**
     * mimeType() returns a string (mime type) for an existing file.
     */
    public function testMimeTypeReturnsMimeStringForExistingFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('image.png', "\x89PNG\r\n\x1a\n"); // PNG magic bytes

        // Act
        $mime = $driver->mimeType('image.png');

        // Assert — should be a string (the exact value depends on the system)
        $this->assertIsString($mime);
        $this->assertNotEmpty($mime);
    }

    // =========================================================================
    // delete()
    // =========================================================================

    /**
     * delete() removes a file; exists() returns false afterwards.
     */
    public function testDeleteRemovesFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('del.txt', 'remove me');

        // Act
        $result = $driver->delete('del.txt');

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($driver->exists('del.txt'));
    }

    /**
     * delete() accepts an array of paths and removes all of them.
     */
    public function testDeleteAcceptsArrayOfPaths(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('a.txt', 'A');
        $driver->put('b.txt', 'B');

        // Act
        $driver->delete(['a.txt', 'b.txt']);

        // Assert — both removed
        $this->assertFalse($driver->exists('a.txt'));
        $this->assertFalse($driver->exists('b.txt'));
    }

    // =========================================================================
    // move()
    // =========================================================================

    /**
     * move() relocates a file: source gone, destination exists with same content.
     */
    public function testMoveRelocatesFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('src.txt', 'content');

        // Act
        $result = $driver->move('src.txt', 'dst.txt');

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($driver->exists('src.txt'));
        $this->assertSame('content', $driver->get('dst.txt'));
    }

    /**
     * move() throws RuntimeException when the source file does not exist.
     */
    public function testMoveThrowsWhenSourceMissing(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->move('ghost.txt', 'dst.txt');
    }

    // =========================================================================
    // copy()
    // =========================================================================

    /**
     * copy() duplicates a file: both source and destination exist afterwards.
     */
    public function testCopyDuplicatesFile(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('orig.txt', 'original');

        // Act
        $result = $driver->copy('orig.txt', 'copy.txt');

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($driver->exists('orig.txt'));
        $this->assertSame('original', $driver->get('copy.txt'));
    }

    /**
     * copy() throws RuntimeException when the source file does not exist.
     */
    public function testCopyThrowsWhenSourceMissing(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->copy('ghost.txt', 'copy.txt');
    }

    // =========================================================================
    // files() / allFiles() / directories()
    // =========================================================================

    /**
     * files() lists only files (not directories) in the root.
     */
    public function testFilesListsOnlyFilesInRoot(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('f1.txt', '1');
        $driver->put('f2.txt', '2');
        $driver->makeDirectory('subdir');

        // Act
        $files = $driver->files();

        // Assert — two files, no directory entry
        sort($files);
        $this->assertSame(['f1.txt', 'f2.txt'], $files);
    }

    /**
     * files() lists only files in a specific subdirectory.
     */
    public function testFilesListsFilesInSubdirectory(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('sub/a.txt', 'a');
        $driver->put('sub/b.txt', 'b');

        // Act
        $files = $driver->files('sub');

        // Assert
        sort($files);
        $this->assertSame(['sub/a.txt', 'sub/b.txt'], $files);
    }

    /**
     * allFiles() recursively lists all files under the root.
     */
    public function testAllFilesRecursivelyListsFiles(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('top.txt', 't');
        $driver->put('nested/deep.txt', 'd');

        // Act
        $all = $driver->allFiles();

        // Assert — both files in the result, order may vary
        sort($all);
        $this->assertContains('top.txt', $all);
        $this->assertContains('nested/deep.txt', $all);
    }

    /**
     * directories() lists only subdirectory names in the root.
     */
    public function testDirectoriesListsSubdirectories(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('file.txt', 'f');
        $driver->makeDirectory('dir1');
        $driver->makeDirectory('dir2');

        // Act
        $dirs = $driver->directories();

        // Assert — only directories returned, not files
        sort($dirs);
        $this->assertSame(['dir1', 'dir2'], $dirs);
    }

    /**
     * files(), allFiles(), directories() return [] for a non-existent path.
     */
    public function testListMethodsReturnEmptyForNonExistentPath(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->assertSame([], $driver->files('nope'));
        $this->assertSame([], $driver->allFiles('nope'));
        $this->assertSame([], $driver->directories('nope'));
    }

    // =========================================================================
    // makeDirectory() / deleteDirectory()
    // =========================================================================

    /**
     * makeDirectory() creates the specified subdirectory.
     */
    public function testMakeDirectoryCreatesSubdirectory(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Act
        $result = $driver->makeDirectory('newdir');

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->root . '/newdir');
    }

    /**
     * makeDirectory() returns true when the directory already exists (idempotent).
     */
    public function testMakeDirectoryIsIdempotent(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->makeDirectory('existing');

        // Act
        $result = $driver->makeDirectory('existing');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * deleteDirectory() removes a directory and all its contents.
     */
    public function testDeleteDirectoryRemovesDirAndContents(): void
    {
        // Arrange
        $driver = $this->makeDriver();
        $driver->put('doomed/file.txt', 'bye');

        // Act
        $result = $driver->deleteDirectory('doomed');

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($this->root . '/doomed');
    }

    /**
     * deleteDirectory() returns false for a path that is not a directory.
     */
    public function testDeleteDirectoryReturnsFalseForMissingPath(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Act
        $result = $driver->deleteDirectory('ghost_dir');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // url() / temporaryUrl()
    // =========================================================================

    /**
     * url() returns the base URL joined with the path.
     */
    public function testUrlComposesBaseUrlAndPath(): void
    {
        // Arrange
        $driver = new LocalDriver(['root' => $this->root, 'url' => '/uploads']);

        // Act
        $url = $driver->url('images/photo.jpg');

        // Assert — base URL + path separator + path
        $this->assertSame('/uploads/images/photo.jpg', $url);
    }

    /**
     * url() strips a leading slash from the path to avoid double slashes.
     */
    public function testUrlStripsLeadingSlashFromPath(): void
    {
        // Arrange
        $driver = new LocalDriver(['root' => $this->root, 'url' => 'https://cdn.example.com']);

        // Act
        $url = $driver->url('/avatar.png');

        // Assert — no double slash
        $this->assertSame('https://cdn.example.com/avatar.png', $url);
    }

    /**
     * url() throws RuntimeException when no 'url' config key was provided.
     */
    public function testUrlThrowsWhenNoBaseUrlConfigured(): void
    {
        // Arrange
        $driver = $this->makeDriver(); // no 'url' config key

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->url('anything.txt');
    }

    /**
     * temporaryUrl() always throws RuntimeException — local storage cannot
     * generate pre-signed URLs.
     */
    public function testTemporaryUrlAlwaysThrows(): void
    {
        // Arrange
        $driver = $this->makeDriver();

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $driver->temporaryUrl('file.txt', new \DateTime('+1 hour'));
    }
}
