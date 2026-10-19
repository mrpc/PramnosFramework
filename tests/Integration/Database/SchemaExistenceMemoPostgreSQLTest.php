<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Database\SchemaBuilder;

/**
 * {@see SchemaExistenceMemoTest} on PostgreSQL (the TimescaleDB container): the catalogue query
 * and the multi-row INSERT are the other dialect's, and a schema is part of what is remembered.
 */
#[CoversClass(SchemaBuilder::class)]
#[CoversClass(QueryBuilder::class)]
#[CoversClass(Database::class)]
class SchemaExistenceMemoPostgreSQLTest extends SchemaExistenceMemoTest
{
    /** @return array<string, mixed> PostgreSQL, in the timescaledb container */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => TEST_DATABASE,
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }

    /** @return string[] One table for insertMany() */
    protected static function schemaStatements(): array
    {
        return ['CREATE TABLE sem_rows (id SERIAL PRIMARY KEY, name VARCHAR(50) NOT NULL, qty INT NOT NULL, note VARCHAR(50) NULL)'];
    }
}
