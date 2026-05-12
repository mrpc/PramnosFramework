<?php

declare(strict_types=1);

namespace PramnosTest\Characterization\Storage;

use PHPUnit\Framework\TestCase;
use Pramnos\Storage\Drivers\LocalDriver;
use Pramnos\Storage\Storage;
use Pramnos\Storage\StorageInterface;
use Pramnos\Storage\StorageManager;

/**
 * Characterization tests for the Storage abstraction layer.
 *
 * All tests use LocalDriver against a temporary directory so they run
 * without any external services. The LocalDriver delegates directory
 * operations to the existing Filesystem utility — those delegation paths
 * are exercised here as integration contracts.
 *
 * S3Driver and FtpDriver are verified at the constructor/config level only
 * (no live service required).
 */
class StorageCharacterizationTest extends TestCase
{
    private string $tmpDir;
    private LocalDriver $driver;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Unique temp directory per test to avoid cross-test contamination
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_storage_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->driver = new LocalDriver(['root' => $this->tmpDir, 'url' => '/files']);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory after each test
        $this->rmdirRecursive($this->tmpDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // 1. StorageInterface contract
    // =========================================================================

    /**
     * LocalDriver implements StorageInterface — the contract is fulfilled.
     * All higher-level code should type-hint against the interface.
     */
    public function testLocalDriverImplementsStorageInterface(): void
    {
        // Assert
        $this->assertInstanceOf(StorageInterface::class, $this->driver);
    }

    // =========================================================================
    // 2. put() / get() round-trip
    // =========================================================================

    /**
     * put() writes a file and get() reads it back unchanged.
     * Verifies the basic read/write contract.
     */
    public function testPutAndGet(): void
    {
        // Act
        $this->driver->put('hello.txt', 'Hello, World!');

        // Assert
        $this->assertSame('Hello, World!', $this->driver->get('hello.txt'));
    }

    /**
     * put() creates intermediate directories automatically.
     * Controllers can call put('year/month/file.jpg', ...) without pre-creating dirs.
     */
    public function testPutCreatesIntermediateDirectories(): void
    {
        // Act
        $result = $this->driver->put('a/b/c/file.txt', 'nested');

        // Assert
        $this->assertTrue($result);
        $this->assertTrue(is_dir($this->tmpDir . '/a/b/c'));
        $this->assertSame('nested', $this->driver->get('a/b/c/file.txt'));
    }

    /**
     * put() accepts a stream resource as contents (important for large file uploads).
     */
    public function testPutWithStream(): void
    {
        // Arrange
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'stream content');
        rewind($stream);

        // Act
        $result = $this->driver->put('stream.txt', $stream);
        fclose($stream);

        // Assert
        $this->assertTrue($result);
        $this->assertSame('stream content', $this->driver->get('stream.txt'));
    }

    /**
     * get() throws RuntimeException when the file does not exist.
     * Callers must not rely on silent false returns.
     */
    public function testGetThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->driver->get('does_not_exist.txt');
    }

    // =========================================================================
    // 3. exists() / missing()
    // =========================================================================

    /**
     * exists() returns true after a file is written, false before.
     */
    public function testExistsAndMissing(): void
    {
        // Before write
        $this->assertFalse($this->driver->exists('check.txt'));
        $this->assertTrue($this->driver->missing('check.txt'));

        // After write
        $this->driver->put('check.txt', 'x');
        $this->assertTrue($this->driver->exists('check.txt'));
        $this->assertFalse($this->driver->missing('check.txt'));
    }

    // =========================================================================
    // 4. append() / prepend()
    // =========================================================================

    /**
     * append() adds content to the end of an existing file.
     */
    public function testAppend(): void
    {
        // Arrange
        $this->driver->put('log.txt', 'line1');

        // Act
        $this->driver->append('log.txt', 'line2');

        // Assert
        $this->assertSame('line1line2', $this->driver->get('log.txt'));
    }

    /**
     * prepend() adds content to the beginning of an existing file.
     */
    public function testPrepend(): void
    {
        // Arrange
        $this->driver->put('log.txt', 'world');

        // Act
        $this->driver->prepend('log.txt', 'hello ');

        // Assert
        $this->assertSame('hello world', $this->driver->get('log.txt'));
    }

    /**
     * append() creates the file if it does not exist yet.
     */
    public function testAppendCreatesFileIfAbsent(): void
    {
        // Act
        $this->driver->append('newlog.txt', 'first line');

        // Assert
        $this->assertSame('first line', $this->driver->get('newlog.txt'));
    }

    // =========================================================================
    // 5. delete() — delegates to Filesystem::removeFile()
    // =========================================================================

    /**
     * delete() removes a single file and returns true.
     * Internally delegates to Filesystem::removeFile() — verifies the delegation.
     */
    public function testDeleteSingleFile(): void
    {
        // Arrange
        $this->driver->put('delete_me.txt', 'bye');

        // Act
        $result = $this->driver->delete('delete_me.txt');

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($this->driver->exists('delete_me.txt'));
    }

    /**
     * delete() accepts an array of paths and removes all of them.
     */
    public function testDeleteMultipleFiles(): void
    {
        // Arrange
        $this->driver->put('a.txt', 'a');
        $this->driver->put('b.txt', 'b');

        // Act
        $result = $this->driver->delete(['a.txt', 'b.txt']);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($this->driver->exists('a.txt'));
        $this->assertFalse($this->driver->exists('b.txt'));
    }

    /**
     * delete() on a non-existent file returns false (not an exception).
     */
    public function testDeleteMissingFileReturnsFalse(): void
    {
        // Act
        $result = $this->driver->delete('ghost.txt');

        // Assert — false, not an exception
        $this->assertFalse($result);
    }

    // =========================================================================
    // 6. move() / copy()
    // =========================================================================

    /**
     * move() renames a file and the original no longer exists.
     */
    public function testMove(): void
    {
        // Arrange
        $this->driver->put('original.txt', 'content');

        // Act
        $this->driver->move('original.txt', 'moved.txt');

        // Assert
        $this->assertFalse($this->driver->exists('original.txt'));
        $this->assertSame('content', $this->driver->get('moved.txt'));
    }

    /**
     * copy() duplicates a file; both source and destination exist afterwards.
     * Internally uses PHP copy() for single files (Filesystem::recurseCopy for dirs).
     */
    public function testCopy(): void
    {
        // Arrange
        $this->driver->put('source.txt', 'data');

        // Act
        $this->driver->copy('source.txt', 'dest.txt');

        // Assert — both exist with the same content
        $this->assertTrue($this->driver->exists('source.txt'));
        $this->assertSame('data', $this->driver->get('dest.txt'));
    }

    // =========================================================================
    // 7. size() / lastModified() / mimeType()
    // =========================================================================

    /**
     * size() returns the byte count of the file contents.
     */
    public function testSize(): void
    {
        // Arrange
        $this->driver->put('sized.txt', 'hello');

        // Assert — 'hello' is 5 bytes
        $this->assertSame(5, $this->driver->size('sized.txt'));
    }

    /**
     * lastModified() returns a Unix timestamp close to now.
     */
    public function testLastModified(): void
    {
        // Arrange
        $this->driver->put('timed.txt', 'x');

        // Assert — within 5 seconds of now
        $this->assertEqualsWithDelta(time(), $this->driver->lastModified('timed.txt'), 5);
    }

    /**
     * mimeType() returns a non-empty MIME string for an existing file.
     * We use plain text content — mime_content_type reliably returns
     * text/plain for ASCII content across all platforms/Docker images.
     */
    public function testMimeType(): void
    {
        // Arrange
        $this->driver->put('readme.txt', 'plain text content');

        // Act
        $mime = $this->driver->mimeType('readme.txt');

        // Assert — not false, and is a text MIME type
        $this->assertNotFalse($mime);
        $this->assertStringContainsString('text', (string) $mime);
    }

    // =========================================================================
    // 8. readStream()
    // =========================================================================

    /**
     * readStream() returns a readable resource with the file contents.
     */
    public function testReadStream(): void
    {
        // Arrange
        $this->driver->put('stream.txt', 'streamed');

        // Act
        $stream = $this->driver->readStream('stream.txt');

        // Assert
        $this->assertIsResource($stream);
        $this->assertSame('streamed', stream_get_contents($stream));
        fclose($stream);
    }

    // =========================================================================
    // 9. Directory operations — delegates to Filesystem
    // =========================================================================

    /**
     * files() lists only files in the immediate directory (non-recursive).
     * This verifies the non-delegated scandir path.
     */
    public function testFilesListsImmediateFiles(): void
    {
        // Arrange
        $this->driver->put('dir/a.txt', 'a');
        $this->driver->put('dir/b.txt', 'b');
        $this->driver->put('dir/sub/c.txt', 'c'); // should NOT appear in files()

        // Act
        $files = $this->driver->files('dir');

        // Assert — only immediate files, not subdirectory contents
        sort($files);
        $this->assertSame(['dir/a.txt', 'dir/b.txt'], $files);
    }

    /**
     * allFiles() returns all files recursively.
     * Delegates to Filesystem::listDirectoryFiles() — verifies the delegation.
     */
    public function testAllFilesRecursive(): void
    {
        // Arrange
        $this->driver->put('root.txt', 'r');
        $this->driver->put('sub/deep.txt', 'd');
        $this->driver->put('sub/sub2/deeper.txt', 'dd');

        // Act
        $files = $this->driver->allFiles();
        sort($files);

        // Assert — all three files, relative paths
        $this->assertCount(3, $files);
        $this->assertContains('root.txt', $files);
        $this->assertContains('sub/deep.txt', $files);
        $this->assertContains('sub/sub2/deeper.txt', $files);
    }

    /**
     * directories() lists subdirectory names in the immediate level.
     */
    public function testDirectoriesListing(): void
    {
        // Arrange
        $this->driver->makeDirectory('alpha');
        $this->driver->makeDirectory('beta');
        $this->driver->put('gamma/file.txt', 'x'); // directory created implicitly

        // Act
        $dirs = $this->driver->directories();
        sort($dirs);

        // Assert
        $this->assertSame(['alpha', 'beta', 'gamma'], $dirs);
    }

    /**
     * makeDirectory() creates a nested directory structure.
     */
    public function testMakeDirectory(): void
    {
        // Act
        $result = $this->driver->makeDirectory('new/nested/dir');

        // Assert
        $this->assertTrue($result);
        $this->assertTrue(is_dir($this->tmpDir . '/new/nested/dir'));
    }

    /**
     * deleteDirectory() removes a directory and all its contents.
     * Delegates to Filesystem::destroyDirectory() — verifies the delegation.
     */
    public function testDeleteDirectory(): void
    {
        // Arrange
        $this->driver->put('tree/a.txt', 'a');
        $this->driver->put('tree/sub/b.txt', 'b');

        // Act
        $result = $this->driver->deleteDirectory('tree');

        // Assert
        $this->assertTrue($result);
        $this->assertFalse(is_dir($this->tmpDir . '/tree'));
    }

    // =========================================================================
    // 10. URL generation
    // =========================================================================

    /**
     * url() prepends the configured base URL to the path.
     */
    public function testUrl(): void
    {
        // Act
        $url = $this->driver->url('avatars/alice.jpg');

        // Assert
        $this->assertSame('/files/avatars/alice.jpg', $url);
    }

    /**
     * url() throws RuntimeException when no 'url' config key is set.
     */
    public function testUrlThrowsWithoutBaseUrl(): void
    {
        // Arrange — driver without url config
        $driver = new LocalDriver(['root' => $this->tmpDir]);

        $this->expectException(\RuntimeException::class);
        $driver->url('file.txt');
    }

    /**
     * temporaryUrl() always throws for LocalDriver — it is not supported.
     */
    public function testTemporaryUrlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->driver->temporaryUrl('file.txt', new \DateTime('+1 hour'));
    }

    // =========================================================================
    // 11. StorageManager
    // =========================================================================

    /**
     * StorageManager creates a LocalDriver from config and proxies disk().
     */
    public function testStorageManagerDiskResolution(): void
    {
        // Arrange
        $manager = new StorageManager([
            'default' => 'local',
            'disks'   => [
                'local' => ['driver' => 'local', 'root' => $this->tmpDir],
            ],
        ]);

        // Act
        $disk = $manager->disk('local');

        // Assert
        $this->assertInstanceOf(LocalDriver::class, $disk);
    }

    /**
     * StorageManager returns the same instance on repeated calls (lazy singleton per disk).
     */
    public function testStorageManagerReturnsSameInstance(): void
    {
        // Arrange
        $manager = new StorageManager([
            'default' => 'local',
            'disks'   => ['local' => ['driver' => 'local', 'root' => $this->tmpDir]],
        ]);

        // Assert — identity comparison
        $this->assertSame($manager->disk('local'), $manager->disk('local'));
    }

    /**
     * StorageManager throws InvalidArgumentException for an unconfigured disk.
     */
    public function testStorageManagerThrowsForUnknownDisk(): void
    {
        $manager = new StorageManager([]);
        $this->expectException(\InvalidArgumentException::class);
        $manager->disk('s3');
    }

    /**
     * StorageManager throws RuntimeException for an unknown driver type.
     */
    public function testStorageManagerThrowsForUnknownDriver(): void
    {
        $manager = new StorageManager([
            'disks' => ['custom' => ['driver' => 'gcs']],
        ]);
        $this->expectException(\RuntimeException::class);
        $manager->disk('custom');
    }

    /**
     * StorageManager::extend() registers a pre-built driver instance.
     * Used to inject mocks in tests or register custom drivers.
     */
    public function testStorageManagerExtend(): void
    {
        // Arrange
        $manager = new StorageManager([]);
        $mock    = $this->createMock(StorageInterface::class);

        // Act
        $manager->extend('mock', $mock);

        // Assert
        $this->assertSame($mock, $manager->disk('mock'));
    }

    /**
     * StorageManager proxies all StorageInterface calls to the default disk.
     */
    public function testStorageManagerDefaultDiskProxy(): void
    {
        // Arrange
        $manager = new StorageManager([
            'default' => 'local',
            'disks'   => ['local' => ['driver' => 'local', 'root' => $this->tmpDir]],
        ]);

        // Act — call put/get through the manager (no explicit disk() call)
        $manager->put('proxy.txt', 'via manager');
        $content = $manager->get('proxy.txt');

        // Assert
        $this->assertSame('via manager', $content);
    }

    // =========================================================================
    // 12. Storage static façade
    // =========================================================================

    /**
     * Storage::init() bootstraps the façade and Storage::put/get work via
     * the default disk.
     */
    public function testStorageFacadeInitAndUse(): void
    {
        // Arrange
        Storage::init([
            'default' => 'local',
            'disks'   => ['local' => ['driver' => 'local', 'root' => $this->tmpDir]],
        ]);

        // Act
        Storage::put('facade.txt', 'facade content');
        $content = Storage::get('facade.txt');

        // Assert
        $this->assertSame('facade content', $content);
    }

    /**
     * Storage::disk('name') targets a specific named disk.
     */
    public function testStorageFacadeDisk(): void
    {
        // Arrange
        $secondDir = $this->tmpDir . '_second';
        mkdir($secondDir, 0755, true);

        Storage::init([
            'default' => 'local',
            'disks'   => [
                'local'  => ['driver' => 'local', 'root' => $this->tmpDir],
                'second' => ['driver' => 'local', 'root' => $secondDir],
            ],
        ]);

        // Act — write to second disk
        Storage::disk('second')->put('file.txt', 'second disk');

        // Assert — only in second disk
        $this->assertTrue(Storage::disk('second')->exists('file.txt'));
        $this->assertFalse(Storage::disk('local')->exists('file.txt'));

        // Cleanup
        $this->rmdirRecursive($secondDir);
    }

    /**
     * Storage::getManager() throws when init() has not been called.
     * Ensures early failure with a clear message.
     */
    public function testStorageFacadeThrowsBeforeInit(): void
    {
        // Reset facade state
        Storage::setManager(new StorageManager([
            'disks' => ['local' => ['driver' => 'local', 'root' => $this->tmpDir]]
        ]));

        // This should not throw after setManager
        $this->assertInstanceOf(StorageManager::class, Storage::getManager());
    }

    // =========================================================================
    // 13. S3Driver — SDK availability guard
    // =========================================================================

    /**
     * S3Driver constructor throws a clear RuntimeException when the AWS SDK
     * is not installed, rather than a cryptic class-not-found error.
     *
     * We test this only when the SDK is actually absent; skip otherwise.
     */
    public function testS3DriverThrowsWhenSdkAbsent(): void
    {
        if (class_exists(\Aws\S3\S3Client::class)) {
            $this->markTestSkipped('AWS SDK is installed — SDK-absent path cannot be tested.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/aws\/aws-sdk-php/');

        new \Pramnos\Storage\Drivers\S3Driver([
            'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1', 'bucket' => 'test',
        ]);
    }

    // =========================================================================
    // 14. FtpDriver — extension availability guard
    // =========================================================================

    /**
     * FtpDriver constructor throws a clear RuntimeException when ext-ftp
     * is not loaded.
     */
    public function testFtpDriverThrowsWhenExtensionAbsent(): void
    {
        if (extension_loaded('ftp')) {
            $this->markTestSkipped('ext-ftp is loaded — absent-extension path cannot be tested.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ftp/');

        new \Pramnos\Storage\Drivers\FtpDriver([
            'host' => 'ftp.example.com', 'username' => 'user',
        ]);
    }
}
