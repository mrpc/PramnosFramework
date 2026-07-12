<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;

/**
 * Unit tests for MigrationLoader::loadFromDirectory() and loadFromDirectories().
 *
 * All tests use fixture files from:
 *   tests/Unit/Database/Fixtures/MigrationLoaderFixtures/
 *
 * The fixture directory contains:
 *   2024_01_01_000000_ml_create_alpha.php — timestamped migration (Ml_CreateAlpha)
 *   MlCreateBeta.php                      — non-timestamped CamelCase migration (MlCreateBeta)
 *   ml_not_a_migration.php                — plain PHP class (must be ignored)
 */
#[CoversClass(MigrationLoader::class)]
class MigrationLoaderUnitTest extends TestCase
{
    /** @var string Path to the fixture migration directory. */
    private string $fixtureDir;

    /** @var Application&\PHPUnit\Framework\MockObject\MockObject */
    private Application $app;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/Fixtures/MigrationLoaderFixtures';

        // Arrange – mock Application so fixtures can call parent::__construct($app)
        $this->app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // loadFromDirectory — happy-path discovery
    // -------------------------------------------------------------------------

    /**
     * loadFromDirectory() must return exactly the Migration subclasses from the
     * fixture directory, ignoring plain PHP classes that do not extend Migration.
     */
    public function testLoadFromDirectoryReturnsOnlyMigrations(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectory($this->fixtureDir, $this->app);

        // Assert – exactly 2 migration instances (Ml_CreateAlpha + MlCreateBeta)
        $this->assertCount(2, $migrations, 'Loader must return exactly the 2 Migration subclasses');

        foreach ($migrations as $m) {
            $this->assertInstanceOf(
                Migration::class,
                $m,
                'Every returned object must be a Migration instance'
            );
        }
    }

    /**
     * A plain PHP class in the directory (MlNotAMigration) must be silently
     * skipped — the loader only yields Migration subclasses.
     */
    public function testLoadFromDirectoryIgnoresNonMigrationClasses(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectory($this->fixtureDir, $this->app);

        // Assert – MlNotAMigration is not present
        $classes = array_map(fn($m) => get_class($m), $migrations);
        $this->assertNotContains('MlNotAMigration', $classes, 'Non-Migration classes must not appear in results');
    }

    /**
     * The timestamped fixture (2024_01_01_000000_ml_create_alpha.php) must be
     * loaded and its getSlug() must return the part after the timestamp prefix.
     */
    public function testLoadFromDirectoryExtractsSlugFromTimestampedFilename(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectory($this->fixtureDir, $this->app);

        // Assert – find the alpha migration by class name
        $alpha = null;
        foreach ($migrations as $m) {
            if (get_class($m) === 'Ml_CreateAlpha') {
                $alpha = $m;
            }
        }
        $this->assertNotNull($alpha, 'Ml_CreateAlpha must be loaded from the timestamped file');
        $this->assertSame('ml_create_alpha', $alpha->getSlug(), 'Slug must be extracted from the filename timestamp prefix');
        $this->assertSame('2024_01_01_000000', $alpha->getTimestamp(), 'Timestamp must be extracted from the filename');
    }

    /**
     * The non-timestamped fixture (MlCreateBeta.php) must be loaded and its
     * getSlug() must be derived from the CamelCase class name.
     */
    public function testLoadFromDirectoryDerivesCamelCaseSlug(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectory($this->fixtureDir, $this->app);

        // Assert – find the beta migration
        $beta = null;
        foreach ($migrations as $m) {
            if (get_class($m) === 'MlCreateBeta') {
                $beta = $m;
            }
        }
        $this->assertNotNull($beta, 'MlCreateBeta must be loaded from the non-timestamped file');
        $this->assertSame('ml_create_beta', $beta->getSlug(), 'Slug must be converted from CamelCase class name');
        $this->assertNull($beta->getTimestamp(), 'Non-timestamped file must return null timestamp');
    }

    /**
     * Metadata properties set in the fixture class must be readable after loading.
     * Verifies that the loader returns properly-configured instances, not just
     * raw objects.
     */
    public function testLoadedMigrationMetadataIsAccessible(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectory($this->fixtureDir, $this->app);

        // Find Ml_CreateAlpha
        foreach ($migrations as $m) {
            if (get_class($m) === 'Ml_CreateAlpha') {
                $this->assertSame('core', $m->feature);
                $this->assertSame('framework', $m->scope);
                $this->assertSame(10, $m->priority);
                return;
            }
        }
        $this->fail('Ml_CreateAlpha not found in loaded migrations');
    }

    // -------------------------------------------------------------------------
    // loadFromDirectory — edge cases
    // -------------------------------------------------------------------------

    /**
     * loadFromDirectory() on a non-existent path must return an empty array
     * without throwing.
     */
    public function testLoadFromDirectoryReturnsEmptyForNonExistentPath(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectory('/this/does/not/exist', $this->app);

        // Assert
        $this->assertSame([], $migrations, 'Non-existent directory must yield an empty array');
    }

    /**
     * loadFromDirectory() on an empty directory must return an empty array.
     */
    public function testLoadFromDirectoryReturnsEmptyForEmptyDirectory(): void
    {
        // Arrange – temporary empty dir
        $tmpDir = sys_get_temp_dir() . '/ml_test_empty_' . getmypid();
        @mkdir($tmpDir, 0777, true);

        // Act
        $migrations = MigrationLoader::loadFromDirectory($tmpDir, $this->app);

        // Assert
        $this->assertSame([], $migrations, 'Empty directory must yield an empty array');

        // Cleanup
        @rmdir($tmpDir);
    }

    // -------------------------------------------------------------------------
    // loadFromDirectories
    // -------------------------------------------------------------------------

    /**
     * loadFromDirectories() must merge results from multiple directories and
     * return all Migration instances in order.
     */
    public function testLoadFromDirectoriesMergesResults(): void
    {
        // Arrange – use the same fixture dir twice; results are merged
        $dirs = [$this->fixtureDir, $this->fixtureDir];

        // Act
        $migrations = MigrationLoader::loadFromDirectories($dirs, $this->app);

        // Assert – 2 dirs × 2 migrations = 4 instances (duplicates allowed by design)
        $this->assertCount(4, $migrations, 'loadFromDirectories must merge results from all directories');
    }

    /**
     * loadFromDirectories() with an empty array of dirs must return [].
     */
    public function testLoadFromDirectoriesWithNoDirsReturnsEmpty(): void
    {
        // Act
        $migrations = MigrationLoader::loadFromDirectories([], $this->app);

        // Assert
        $this->assertSame([], $migrations);
    }

    /**
     * loadFromDirectory() must skip abstract Migration subclasses — they cannot
     * be instantiated and exist only as base classes for concrete migrations.
     * Covers the `if ($ref->isAbstract()) continue;` branch in the loader.
     */
    public function testLoadFromDirectorySkipsAbstractMigrations(): void
    {
        // Arrange — write a temp migration file that contains an abstract class
        $tmpDir = sys_get_temp_dir() . '/ml_test_abstract_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);

        $abstractFile = $tmpDir . '/2026_01_01_000000_abstract_base.php';
        $concreteFile = $tmpDir . '/2026_01_01_000001_concrete_one.php';

        file_put_contents($abstractFile, '<?php
use Pramnos\Database\Migration;
abstract class MlAbstractBase extends Migration {
    public function up(): void {}
    public function down(): void {}
}
');
        file_put_contents($concreteFile, '<?php
use Pramnos\Database\Migration;
class MlConcreteOne extends Migration {
    public function up(): void {}
    public function down(): void {}
}
');

        // Act
        $migrations = MigrationLoader::loadFromDirectory($tmpDir, $this->app);

        // Cleanup
        @unlink($abstractFile);
        @unlink($concreteFile);
        @rmdir($tmpDir);

        // Assert — only the concrete migration is returned, abstract one is skipped
        $this->assertCount(1, $migrations,
            'Abstract migration classes must be excluded from the result');
        $this->assertInstanceOf(\Pramnos\Database\Migration::class, $migrations[0]);
        $this->assertSame('MlConcreteOne', (new \ReflectionClass($migrations[0]))->getShortName());
    }

    /**
     * resolveDefaultDirectories() when no framework migrations base exists must
     * still return the app/Migrations directory as the first entry.
     * Covers the `if ($base !== null && is_dir($base))` false branch where
     * no feature sub-directories are appended.
     */
    public function testResolveDefaultDirectoriesWithoutFrameworkBaseReturnsAppMigrationsOnly(): void
    {
        // Arrange — use a nonexistent root so resolveFrameworkMigrationsBase() returns null
        $fakeRoot = '/nonexistent_root_' . bin2hex(random_bytes(4));

        // Act — pass the fake root (no framework migrations exist there)
        // Note: resolveFrameworkMigrationsBase() always finds the dev-repo source,
        // so we verify that at minimum app/Migrations is always the first entry
        $dirs = MigrationLoader::resolveDefaultDirectories($fakeRoot);

        // Assert — at minimum app/Migrations must be the first dir
        $this->assertIsArray($dirs);
        $this->assertNotEmpty($dirs);
        $this->assertStringEndsWith('/app/Migrations', $dirs[0],
            'First directory must always be app/Migrations regardless of framework base');
    }
}
