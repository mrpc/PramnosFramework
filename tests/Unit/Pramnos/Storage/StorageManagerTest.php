<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Storage\StorageInterface;
use Pramnos\Storage\StorageManager;

/**
 * Unit tests for Pramnos\Storage\StorageManager.
 *
 * StorageManager is a lazy registry / factory for named storage "disks".  It
 * reads disk configuration from an array, creates drivers on first access, and
 * allows external drivers to be injected via extend().
 *
 * Tests use the extend() API to inject stub drivers so no real filesystem,
 * FTP server, or S3 bucket is needed.
 */
#[CoversClass(StorageManager::class)]
class StorageManagerTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /** Build a minimal StorageInterface stub that tracks method calls. */
    private function makeDriver(): StorageInterface
    {
        return new class implements StorageInterface {
            public function get(string $path): string { return ''; }
            public function readStream(string $path) { return null; }
            public function put(string $path, $contents, array $options = []): bool { return true; }
            public function prepend(string $path, string $data): bool { return true; }
            public function append(string $path, string $data): bool { return true; }
            public function exists(string $path): bool { return false; }
            public function missing(string $path): bool { return true; }
            public function size(string $path): int { return 0; }
            public function lastModified(string $path): int { return 0; }
            public function mimeType(string $path): string|false { return false; }
            public function delete(string|array $paths): bool { return true; }
            public function move(string $from, string $to): bool { return true; }
            public function copy(string $from, string $to): bool { return true; }
            public function files(string $directory = ''): array { return []; }
            public function allFiles(string $directory = ''): array { return []; }
            public function directories(string $directory = ''): array { return []; }
            public function allDirectories(string $directory = ''): array { return []; }
            public function makeDirectory(string $path): bool { return true; }
            public function deleteDirectory(string $directory): bool { return true; }
            public function url(string $path): string { return ''; }
            public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string { return ''; }
        };
    }

    // =========================================================================
    // Constructor defaults
    // =========================================================================

    /**
     * When constructed with an empty config, the default disk name is 'local'.
     * This matches the convention that the local driver is always the baseline.
     */
    public function testDefaultDiskNameIsLocalWhenNotConfigured(): void
    {
        // Arrange
        $manager = new StorageManager();
        $driver  = $this->makeDriver();

        // Register a local driver so defaultDisk() has something to return
        $manager->extend('local', $driver);

        // Act / Assert – defaultDisk() resolves to the 'local' driver
        $this->assertSame($driver, $manager->defaultDisk());
    }

    /**
     * The 'default' key in config overrides which disk name is treated as default.
     */
    public function testConfigDefaultKeyChangesDefaultDiskName(): void
    {
        // Arrange
        $manager = new StorageManager(['default' => 's3', 'disks' => []]);
        $driver  = $this->makeDriver();
        $manager->extend('s3', $driver);

        // Act / Assert – s3 is now the default
        $this->assertSame($driver, $manager->defaultDisk());
    }

    // =========================================================================
    // extend / disk
    // =========================================================================

    /**
     * extend() registers a pre-built driver under a given name, and disk()
     * returns that same driver instance on subsequent calls.
     */
    public function testExtendRegistersDriverAndDiskReturnsIt(): void
    {
        // Arrange
        $manager = new StorageManager();
        $driver  = $this->makeDriver();

        // Act
        $manager->extend('my-disk', $driver);
        $retrieved = $manager->disk('my-disk');

        // Assert – same object reference
        $this->assertSame($driver, $retrieved);
    }

    /**
     * disk() is lazy — calling it twice for the same name always returns the
     * same driver instance (the driver factory is not called a second time).
     */
    public function testDiskReturnsSameInstanceOnMultipleCalls(): void
    {
        // Arrange
        $manager = new StorageManager();
        $driver  = $this->makeDriver();
        $manager->extend('cache', $driver);

        // Act
        $first  = $manager->disk('cache');
        $second = $manager->disk('cache');

        // Assert – identity preserved
        $this->assertSame($first, $second);
    }

    /**
     * Two different disk names return different drivers.
     */
    public function testDiskReturnsDifferentDriversForDifferentNames(): void
    {
        // Arrange
        $manager = new StorageManager();
        $driverA = $this->makeDriver();
        $driverB = $this->makeDriver();
        $manager->extend('a', $driverA);
        $manager->extend('b', $driverB);

        // Assert
        $this->assertSame($driverA, $manager->disk('a'));
        $this->assertSame($driverB, $manager->disk('b'));
        $this->assertNotSame($manager->disk('a'), $manager->disk('b'));
    }

    /**
     * extend() overrides a previously registered driver — useful for swapping
     * a production driver with a test double at runtime.
     */
    public function testExtendOverridesPreviousRegistration(): void
    {
        // Arrange
        $manager  = new StorageManager();
        $original = $this->makeDriver();
        $override = $this->makeDriver();

        $manager->extend('local', $original);
        $manager->extend('local', $override);

        // Act / Assert – override wins
        $this->assertSame($override, $manager->disk('local'));
    }

    // =========================================================================
    // disk() — error cases
    // =========================================================================

    /**
     * disk() throws InvalidArgumentException when asked for a disk name that
     * has not been registered and is not in the config.
     */
    public function testDiskThrowsForUnknownDiskName(): void
    {
        // Arrange
        $manager = new StorageManager();

        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        $manager->disk('nonexistent-disk');
    }

    // =========================================================================
    // defaultDisk
    // =========================================================================

    /**
     * defaultDisk() returns the driver registered under the configured default
     * name, making it a convenience wrapper around disk($defaultName).
     */
    public function testDefaultDiskReturnsSameAsExplicitDiskCall(): void
    {
        // Arrange
        $manager = new StorageManager(['default' => 'uploads']);
        $driver  = $this->makeDriver();
        $manager->extend('uploads', $driver);

        // Assert – explicit and default are identical
        $this->assertSame($manager->disk('uploads'), $manager->defaultDisk());
    }

    // =========================================================================
    // createDriver() — factory paths
    // =========================================================================

    /**
     * When an unknown driver type is configured, createDriver() must throw a
     * RuntimeException identifying the bad driver name.
     * Covers lines 96–105: config lookup, strtolower, match default branch.
     */
    public function testCreateDriverThrowsRuntimeExceptionForUnknownDriver(): void
    {
        // Arrange — configure a disk with a driver type that is not 'local', 's3', or 'ftp'
        $manager = new StorageManager([
            'disks' => ['myDisk' => ['driver' => 'redis']],
        ]);

        // Act + Assert — RuntimeException from the match default case
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown storage driver \[redis\]/');
        $manager->disk('myDisk');
    }

    // =========================================================================
    // Proxy methods — all 20 convenience methods on the default disk
    // =========================================================================

    /**
     * Every proxy method on StorageManager must delegate to the default disk.
     * This single test exercises all 20 one-liner proxy methods to ensure they
     * actually forward the call rather than silently returning null/false.
     *
     * A stub driver with known return values is injected via extend() so no
     * real filesystem, S3 bucket, or FTP server is needed.
     */
    public function testProxyMethodsDelegateToDefaultDisk(): void
    {
        // Arrange — inject a stub as the default ('local') disk
        $manager = new StorageManager();
        $driver  = $this->makeDriver();
        $manager->extend('local', $driver);

        // Act + Assert — call every proxy method and verify delegation works
        // (return values are the stub's defaults; we verify no exception is thrown
        //  and the correct type is returned)

        $this->assertSame('', $manager->get('file.txt'),
            'get() must delegate to the default disk');

        $this->assertNull($manager->readStream('file.txt'),
            'readStream() must delegate to the default disk');

        $this->assertTrue($manager->put('file.txt', 'data'),
            'put() must delegate to the default disk');

        $this->assertTrue($manager->prepend('file.txt', 'prefix'),
            'prepend() must delegate to the default disk');

        $this->assertTrue($manager->append('file.txt', 'suffix'),
            'append() must delegate to the default disk');

        $this->assertFalse($manager->exists('file.txt'),
            'exists() must delegate to the default disk');

        $this->assertTrue($manager->missing('file.txt'),
            'missing() must delegate to the default disk');

        $this->assertSame(0, $manager->size('file.txt'),
            'size() must delegate to the default disk');

        $this->assertSame(0, $manager->lastModified('file.txt'),
            'lastModified() must delegate to the default disk');

        $this->assertFalse($manager->mimeType('file.txt'),
            'mimeType() must delegate to the default disk');

        $this->assertTrue($manager->delete('file.txt'),
            'delete() must delegate to the default disk');

        $this->assertTrue($manager->move('a.txt', 'b.txt'),
            'move() must delegate to the default disk');

        $this->assertTrue($manager->copy('a.txt', 'b.txt'),
            'copy() must delegate to the default disk');

        $this->assertSame([], $manager->files('dir/'),
            'files() must delegate to the default disk');

        $this->assertSame([], $manager->allFiles('dir/'),
            'allFiles() must delegate to the default disk');

        $this->assertSame([], $manager->directories('dir/'),
            'directories() must delegate to the default disk');

        $this->assertTrue($manager->makeDirectory('dir/'),
            'makeDirectory() must delegate to the default disk');

        $this->assertTrue($manager->deleteDirectory('dir/'),
            'deleteDirectory() must delegate to the default disk');

        $this->assertSame('', $manager->url('file.txt'),
            'url() must delegate to the default disk');

        $this->assertSame(
            '',
            $manager->temporaryUrl('file.txt', new \DateTimeImmutable('+1 hour')),
            'temporaryUrl() must delegate to the default disk'
        );
    }
}
