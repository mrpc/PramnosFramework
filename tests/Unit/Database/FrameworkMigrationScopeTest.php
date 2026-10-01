<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Migration;

/**
 * Every migration the framework ships declares itself a framework migration.
 *
 * `Migration::$scope` defaults to `app`, so a framework migration that forgets to say
 * otherwise is treated as the application's: `migrate --scope=framework` leaves it out, and
 * its ledger row is filed under the application. Two shipped that way, unnoticed, because
 * nothing reads the declaration until somebody filters on it.
 */
class FrameworkMigrationScopeTest extends TestCase
{
    /**
     * Every PHP file under `database/migrations/framework/`, keyed by its path in the tree.
     *
     * @return array<string, array{string}>
     */
    public static function frameworkMigrations(): array
    {
        $base  = dirname(__DIR__, 3) . '/database/migrations/framework';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($base) + 1)] = [$file->getPathname()];
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The migration class in $path has `scope` = `framework` by default.
     *
     * Read from the class rather than the source text, so a declaration inherited from a
     * shared parent counts as well as one written in the file.
     */
    #[DataProvider('frameworkMigrations')]
    public function testItDeclaresTheFrameworkScope(string $path): void
    {
        // Arrange — load the file and find the migration class declared in it, whether this
        // test loaded it or an earlier one in the same process did
        require_once $path;
        $classes = array_values(array_filter(
            get_declared_classes(),
            static fn (string $c): bool => is_subclass_of($c, Migration::class)
                && (new \ReflectionClass($c))->getFileName() === realpath($path)
        ));
        $this->assertNotEmpty($classes, 'no migration class found in ' . $path);
        $class = $classes[0];

        // Act
        $scope = (new \ReflectionProperty($class, 'scope'))->getDefaultValue();

        // Assert
        $this->assertSame('framework', $scope, $class . ' is shipped by the framework but declares scope "' . $scope . '"');
    }
}
