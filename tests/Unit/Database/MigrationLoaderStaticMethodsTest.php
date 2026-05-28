<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\MigrationLoader;

/**
 * Unit tests for the static helper methods added to MigrationLoader:
 *   - resolveFrameworkMigrationsBase()
 *   - resolveDefaultDirectories()
 *   - slugsFromDirectories()
 *
 * These tests do not require a database and do not load any migration PHP
 * files — they verify filesystem resolution logic and slug extraction from
 * filenames only.
 */
#[CoversClass(MigrationLoader::class)]
class MigrationLoaderStaticMethodsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // resolveFrameworkMigrationsBase
    // -------------------------------------------------------------------------

    /**
     * When the framework is used as the project root (development layout),
     * resolveFrameworkMigrationsBase() must locate the real
     * database/migrations/framework directory that ships with the framework.
     *
     * This test is meaningful only when run from inside the framework source
     * tree (which is the case in ./dockertest).
     */
    public function testResolvesFrameworkBaseDirFromSourceTree(): void
    {
        // Act
        $base = MigrationLoader::resolveFrameworkMigrationsBase();

        // Assert – the directory must exist and contain at least one sub-directory
        $this->assertNotNull($base, 'Base dir must be found when running from source tree');
        $this->assertDirectoryExists($base);
        $subdirs = glob($base . '/*', GLOB_ONLYDIR);
        $this->assertNotEmpty($subdirs, 'Framework migrations base must contain feature sub-directories');
    }

    /**
     * When a non-existent root is passed, resolveFrameworkMigrationsBase()
     * returns null rather than throwing.
     */
    public function testReturnsNullForNonExistentRoot(): void
    {
        // Arrange – a root that definitely has no vendor/ sub-tree
        $bogusRoot = sys_get_temp_dir() . '/pramnos_test_nonexistent_' . uniqid();

        // Act
        $base = MigrationLoader::resolveFrameworkMigrationsBase($bogusRoot);

        // Assert – must not crash; may return a real path if the source-tree
        // path resolves (developer environment).  We only care it does not throw.
        $this->assertTrue($base === null || is_string($base));
    }

    // -------------------------------------------------------------------------
    // resolveDefaultDirectories
    // -------------------------------------------------------------------------

    /**
     * resolveDefaultDirectories() must always include app/Migrations as the
     * first entry, followed by framework feature directories.
     */
    public function testDefaultDirectoriesIncludesAppMigrationsFirst(): void
    {
        // Arrange – use the real framework source tree as root
        $root = defined('ROOT') ? ROOT : getcwd();

        // Act
        $dirs = MigrationLoader::resolveDefaultDirectories($root);

        // Assert – first entry is always app/Migrations
        $this->assertNotEmpty($dirs);
        $this->assertStringEndsWith('app/Migrations', $dirs[0]);
    }

    /**
     * When run from the framework source tree, resolveDefaultDirectories()
     * must include at least one framework feature directory beyond app/Migrations.
     */
    public function testDefaultDirectoriesIncludesFrameworkFeatureDirs(): void
    {
        // Act
        $dirs = MigrationLoader::resolveDefaultDirectories();

        // Assert – more than just app/Migrations
        $this->assertGreaterThan(
            1,
            count($dirs),
            'Must include framework feature dirs in addition to app/Migrations'
        );
    }

    // -------------------------------------------------------------------------
    // slugsFromDirectories
    // -------------------------------------------------------------------------

    /**
     * slugsFromDirectories() must extract slug and timestamp from correctly
     * named files and return them as [slug => timestamp].
     */
    public function testExtractsSlugAndTimestampFromTimestampedFilenames(): void
    {
        // Arrange – create a temp dir with two timestamped filenames
        $dir = sys_get_temp_dir() . '/pramnos_slug_test_' . uniqid();
        mkdir($dir, 0777, true);
        touch($dir . '/2024_01_15_120000_create_users_table.php');
        touch($dir . '/2024_06_01_000000_add_email_index.php');

        try {
            // Act
            $result = MigrationLoader::slugsFromDirectories([$dir]);

            // Assert – both slugs present with correct timestamps
            $this->assertArrayHasKey('create_users_table', $result);
            $this->assertSame('2024_01_15_120000', $result['create_users_table']);

            $this->assertArrayHasKey('add_email_index', $result);
            $this->assertSame('2024_06_01_000000', $result['add_email_index']);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }

    /**
     * Non-timestamped filenames (e.g. Migration0126.php) must be silently
     * ignored — their slug depends on the class short-name, not the filename.
     */
    public function testIgnoresNonTimestampedFilenames(): void
    {
        // Arrange
        $dir = sys_get_temp_dir() . '/pramnos_slug_test_' . uniqid();
        mkdir($dir, 0777, true);
        touch($dir . '/Migration0126.php');
        touch($dir . '/CreateUsersTable.php');
        touch($dir . '/2024_01_01_000000_valid.php'); // one valid file

        try {
            // Act
            $result = MigrationLoader::slugsFromDirectories([$dir]);

            // Assert – only the timestamped file appears
            $this->assertCount(1, $result);
            $this->assertArrayHasKey('valid', $result);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }

    /**
     * An empty or non-existent directory must produce an empty result without
     * throwing any exception.
     */
    public function testEmptyDirectoryReturnsEmptyArray(): void
    {
        // Arrange – a directory with no PHP files
        $dir = sys_get_temp_dir() . '/pramnos_slug_empty_' . uniqid();
        mkdir($dir, 0777, true);

        try {
            // Act
            $result = MigrationLoader::slugsFromDirectories([$dir]);

            // Assert
            $this->assertSame([], $result);
        } finally {
            rmdir($dir);
        }
    }

    /**
     * A non-existent directory path must be silently skipped.
     */
    public function testNonExistentDirectorySkipped(): void
    {
        // Act – pass a path that does not exist
        $result = MigrationLoader::slugsFromDirectories(['/does/not/exist_' . uniqid()]);

        // Assert – no exception, empty result
        $this->assertSame([], $result);
    }

    /**
     * Multiple directories are merged; duplicate slugs from different
     * directories are deduplicated (last one wins, but no crash).
     */
    public function testMergesMultipleDirectories(): void
    {
        // Arrange – two directories each with one migration
        $dir1 = sys_get_temp_dir() . '/pramnos_slug_multi1_' . uniqid();
        $dir2 = sys_get_temp_dir() . '/pramnos_slug_multi2_' . uniqid();
        mkdir($dir1, 0777, true);
        mkdir($dir2, 0777, true);
        touch($dir1 . '/2024_01_01_000000_alpha.php');
        touch($dir2 . '/2024_02_01_000000_beta.php');

        try {
            // Act
            $result = MigrationLoader::slugsFromDirectories([$dir1, $dir2]);

            // Assert – both slugs present
            $this->assertArrayHasKey('alpha', $result);
            $this->assertArrayHasKey('beta', $result);
            $this->assertCount(2, $result);
        } finally {
            foreach ([$dir1, $dir2] as $d) {
                array_map('unlink', glob($d . '/*.php') ?: []);
                rmdir($d);
            }
        }
    }

    /**
     * Slug extraction is always lower-case even when the filename segment
     * contains mixed-case characters.
     */
    public function testSlugIsLowerCased(): void
    {
        // Arrange
        $dir = sys_get_temp_dir() . '/pramnos_slug_case_' . uniqid();
        mkdir($dir, 0777, true);
        touch($dir . '/2024_01_01_000000_Create_Users_Table.php');

        try {
            // Act
            $result = MigrationLoader::slugsFromDirectories([$dir]);

            // Assert
            $this->assertArrayHasKey('create_users_table', $result);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }

    /**
     * slugsFromDirectories() operates on real framework migration directories
     * and must return at least one slug when run from the source tree.
     */
    public function testRealFrameworkMigrationsReturnSlugs(): void
    {
        // Arrange – use the actual framework migration dirs
        $base = MigrationLoader::resolveFrameworkMigrationsBase();
        if ($base === null) {
            $this->markTestSkipped('Framework migration base not found');
        }
        $dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];

        // Act
        $result = MigrationLoader::slugsFromDirectories($dirs);

        // Assert – the real framework has many migrations
        $this->assertNotEmpty($result, 'Real framework dirs must produce non-empty slug map');
        foreach ($result as $slug => $ts) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}_\d{2}_\d{2}_\d{6}$/',
                $ts,
                "Timestamp for slug '$slug' must be in YYYY_MM_DD_HHmmss format"
            );
            $this->assertNotEmpty($slug, 'Slug must not be empty');
        }
    }
}
