<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Storage\Storage;
use Pramnos\Storage\StorageInterface;
use Pramnos\Storage\StorageManager;

/**
 * Unit tests for the Pramnos\Storage\Storage static façade.
 *
 * Storage is a thin static wrapper around StorageManager.  All methods proxy
 * to the underlying singleton StorageManager instance.  Tests inject a
 * pre-configured StorageManager via Storage::setManager() to avoid real
 * filesystem / S3 / FTP access.
 *
 * Static state is reset in tearDown() so these tests do not leak into each
 * other or into characterisation tests that also use the Storage façade.
 */
#[CoversClass(Storage::class)]
class StorageTest extends TestCase
{
    // =========================================================================
    // Setup / teardown
    // =========================================================================

    protected function tearDown(): void
    {
        // Reset the private static $manager to null so the next test starts clean.
        $ref = new \ReflectionProperty(Storage::class, 'manager');
        $ref->setValue(null, null);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a minimal StorageInterface stub that returns predictable values.
     * The stub is injected via StorageManager::extend() so no real driver runs.
     */
    private function makeDriver(): StorageInterface
    {
        return new class implements StorageInterface {
            public function get(string $path): string { return 'content'; }
            public function readStream(string $path) { return null; }
            public function put(string $path, $contents, array $options = []): bool { return true; }
            public function prepend(string $path, string $data): bool { return true; }
            public function append(string $path, string $data): bool { return true; }
            public function exists(string $path): bool { return true; }
            public function missing(string $path): bool { return false; }
            public function size(string $path): int { return 42; }
            public function lastModified(string $path): int { return 1000; }
            public function mimeType(string $path): string|false { return 'text/plain'; }
            public function delete(string|array $paths): bool { return true; }
            public function move(string $from, string $to): bool { return true; }
            public function copy(string $from, string $to): bool { return true; }
            public function files(string $directory = ''): array { return ['a.txt']; }
            public function allFiles(string $directory = ''): array { return ['a.txt', 'b.txt']; }
            public function directories(string $directory = ''): array { return ['dir/']; }
            public function allDirectories(string $directory = ''): array { return ['dir/']; }
            public function makeDirectory(string $path): bool { return true; }
            public function deleteDirectory(string $directory): bool { return true; }
            public function url(string $path): string { return 'https://example.com/' . $path; }
            public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string { return 'https://example.com/signed/' . $path; }
        };
    }

    /** Inject a StorageManager wired to the stub driver and return the manager. */
    private function wireStorage(): StorageManager
    {
        $manager = new StorageManager(['default' => 'local']);
        $manager->extend('local', $this->makeDriver());
        Storage::setManager($manager);
        return $manager;
    }

    // =========================================================================
    // setManager / getManager
    // =========================================================================

    /**
     * setManager() replaces the underlying singleton so subsequent façade calls
     * target the injected manager.  This is the primary testing hook.
     */
    public function testSetManagerReplacesUnderlyingManager(): void
    {
        // Arrange
        $manager = new StorageManager();
        $manager->extend('local', $this->makeDriver());

        // Act
        Storage::setManager($manager);

        // Assert — getManager() returns the injected instance
        $this->assertSame($manager, Storage::getManager());
    }

    /**
     * getManager() must throw RuntimeException when Storage::init() has never
     * been called and setManager() has not been used.
     *
     * Preventing silent null-pointer dereferences is the only purpose of the
     * early check in getManager().
     */
    public function testGetManagerThrowsWhenNotInitialised(): void
    {
        // Arrange — manager is null (tearDown clears it; no init() called)

        // Assert + Act
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Storage has not been initialised/');
        Storage::getManager();
    }

    // =========================================================================
    // init()
    // =========================================================================

    /**
     * init() with a valid config creates a StorageManager and makes getManager()
     * available without throwing.
     */
    public function testInitCreatesManagerFromConfig(): void
    {
        // Act
        Storage::init(['default' => 'local', 'disks' => []]);

        // Assert — manager is now available
        $this->assertInstanceOf(StorageManager::class, Storage::getManager());
    }

    // =========================================================================
    // Proxy methods — all 20 façade methods delegate to the default disk
    // =========================================================================

    /**
     * Every proxy method on the Storage façade must forward the call to the
     * underlying StorageManager (and ultimately to the default disk driver).
     * This single test exercises all 20 one-liner proxy methods to ensure no
     * method is accidentally broken to return null or throw an exception.
     *
     * A stub driver with known return values is injected via setManager() so no
     * real filesystem, S3 bucket, or FTP server is accessed.
     */
    public function testProxyMethodsDelegateToDefaultDisk(): void
    {
        // Arrange — inject stub via setManager()
        $this->wireStorage();

        // Act + Assert — call every proxy method

        $this->assertSame('content', Storage::get('file.txt'),
            'get() must proxy to the default disk');

        $this->assertNull(Storage::readStream('file.txt'),
            'readStream() must proxy to the default disk');

        $this->assertTrue(Storage::put('file.txt', 'data'),
            'put() must proxy to the default disk');

        $this->assertTrue(Storage::prepend('file.txt', 'prefix'),
            'prepend() must proxy to the default disk');

        $this->assertTrue(Storage::append('file.txt', 'suffix'),
            'append() must proxy to the default disk');

        $this->assertTrue(Storage::exists('file.txt'),
            'exists() must proxy to the default disk');

        $this->assertFalse(Storage::missing('file.txt'),
            'missing() must proxy to the default disk');

        $this->assertSame(42, Storage::size('file.txt'),
            'size() must proxy to the default disk');

        $this->assertSame(1000, Storage::lastModified('file.txt'),
            'lastModified() must proxy to the default disk');

        $this->assertSame('text/plain', Storage::mimeType('file.txt'),
            'mimeType() must proxy to the default disk');

        $this->assertTrue(Storage::delete('file.txt'),
            'delete() must proxy to the default disk');

        $this->assertTrue(Storage::move('a.txt', 'b.txt'),
            'move() must proxy to the default disk');

        $this->assertTrue(Storage::copy('a.txt', 'b.txt'),
            'copy() must proxy to the default disk');

        $this->assertSame(['a.txt'], Storage::files('dir/'),
            'files() must proxy to the default disk');

        $this->assertSame(['a.txt', 'b.txt'], Storage::allFiles('dir/'),
            'allFiles() must proxy to the default disk');

        $this->assertSame(['dir/'], Storage::directories('dir/'),
            'directories() must proxy to the default disk');

        $this->assertTrue(Storage::makeDirectory('new-dir/'),
            'makeDirectory() must proxy to the default disk');

        $this->assertTrue(Storage::deleteDirectory('old-dir/'),
            'deleteDirectory() must proxy to the default disk');

        $this->assertStringContainsString('example.com', Storage::url('file.txt'),
            'url() must proxy to the default disk');

        $this->assertStringContainsString(
            'example.com',
            Storage::temporaryUrl('file.txt', new \DateTimeImmutable('+1 hour')),
            'temporaryUrl() must proxy to the default disk'
        );
    }

    // =========================================================================
    // disk() — named disk selection
    // =========================================================================

    /**
     * Storage::disk('name') delegates to StorageManager::disk() and returns the
     * registered driver for that name.
     *
     * This is the primary entry-point for applications that need to address a
     * non-default disk (e.g. Storage::disk('s3')->put(...)).
     */
    public function testDiskReturnsDriveForNamedDisk(): void
    {
        // Arrange — register two disks
        $manager = new StorageManager(['default' => 'local']);
        $driverA = $this->makeDriver();
        $driverB = $this->makeDriver();
        $manager->extend('local', $driverA);
        $manager->extend('archive', $driverB);
        Storage::setManager($manager);

        // Act
        $resolved = Storage::disk('archive');

        // Assert — the archive driver is returned, not the default
        $this->assertSame($driverB, $resolved);
    }
}
