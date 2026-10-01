<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Pins that no framework migration creates `userlog`.
 *
 * `userlog` is deprecated: it records only the user a row is about, never who acted, and
 * `pramnos.changelog_events` (the `changelog` feature) records both, for any entity. Two
 * stores for the same events means every reader has to consult both, so a new installation
 * gets only the one that is kept. Installations that already have `userlog` keep it; the
 * framework never drops it.
 *
 * A property of the migrations tree rather than of any class, so nothing else would notice
 * a migration that brought it back.
 */
class UserlogIsNotCreatedTest extends TestCase
{
    /**
     * No file under `database/migrations/framework/` creates the table, whatever its name.
     */
    public function testNoFrameworkMigrationCreatesUserlog(): void
    {
        // Arrange
        $base  = dirname(__DIR__, 3) . '/database/migrations/framework';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        // Act
        $creators = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Either way a table is created: through the schema builder or in raw SQL.
            if (preg_match('/createTable\(\s*[\'"](#PREFIX#)?userlog[\'"]|CREATE TABLE[^;(]*\buserlog\b/i', $source) === 1) {
                $creators[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }

        // Assert
        $this->assertSame([], $creators, 'userlog is deprecated and must not be created by a framework migration');
        // The scan really looked: the tree is not empty.
        $this->assertGreaterThan(10, iterator_count($files));
    }
}
