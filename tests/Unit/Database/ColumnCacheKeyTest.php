<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * One cache key for a table's columns, however the table is named.
 *
 * A model names its table qualified (`public.properties` on PostgreSQL), the catalogue a
 * migration flushes from names it bare, and callers write `#PREFIX#`. Every one of those has to be
 * the same key, or a flush reports success and the old list stays.
 */
#[CoversClass(Database::class)]
class ColumnCacheKeyTest extends TestCase
{
    private function connection(string $prefix, string $database, ?string $schema): Database
    {
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $db->prefix   = $prefix;
        $db->database = $database;
        $db->schema   = $schema;

        return $db;
    }

    /**
     * On PostgreSQL the connection's own schema is dropped; another schema is kept.
     */
    public function testEveryNameForOneTableIsOneKey(): void
    {
        // Arrange
        $pg = $this->connection('', 'app', 'public');

        // Act & Assert
        $this->assertSame($pg->columnCacheKey('properties'), $pg->columnCacheKey('public.properties'));
        $this->assertSame($pg->columnCacheKey('properties'), $pg->columnCacheKey('"properties"'));
        $this->assertNotSame($pg->columnCacheKey('roles'), $pg->columnCacheKey('authserver.roles'), 'another schema is another table');
    }

    /**
     * On MySQL the prefix is resolved, so `#PREFIX#users` and `pr_users` are one key.
     */
    public function testThePrefixIsResolved(): void
    {
        // Arrange
        $mysql = $this->connection('pr_', 'app', null);

        // Act & Assert
        $this->assertSame($mysql->columnCacheKey('pr_users'), $mysql->columnCacheKey('#PREFIX#users'));
        $this->assertSame($mysql->columnCacheKey('pr_users'), $mysql->columnCacheKey('`pr_users`'));
        $this->assertStringStartsWith('schema_columns_app_', $mysql->columnCacheKey('pr_users'), 'scoped to the database');
    }
}
