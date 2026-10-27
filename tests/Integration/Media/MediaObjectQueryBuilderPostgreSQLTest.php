<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Media;

/**
 * The same query-builder conversions, against real PostgreSQL.
 *
 * Everything is inherited; see {@see MediaObjectQueryBuilderTest} for what this proves and
 * why — a `translate`/reserved-word/sequence mistake in the conversion would show up here and
 * nowhere else.
 */
class MediaObjectQueryBuilderPostgreSQLTest extends MediaObjectQueryBuilderTest
{
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
}
