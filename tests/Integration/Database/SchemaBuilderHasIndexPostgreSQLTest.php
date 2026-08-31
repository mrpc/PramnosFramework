<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;

/**
 * `hasIndex()` on PostgreSQL.
 *
 * The same assertions as the MySQL parent, against the other grammar. PostgreSQL has
 * no `information_schema` view for indexes — they are not part of the standard — so
 * it reads `pg_indexes` instead, and the two implementations share nothing but the
 * method name. A green MySQL run says nothing about this one.
 *
 * Requires the Docker TimescaleDB/PostgreSQL container (host: timescaledb).
 */
class SchemaBuilderHasIndexPostgreSQLTest extends SchemaBuilderHasIndexTest
{
    protected function connect(): Database
    {
        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->schema   = 'public';
        $db->connect(true);

        // The schema builder reads the singleton in places, so the connection under
        // test has to be the one it finds.
        $ref = &Database::getInstance();
        $ref = $db;

        return $db;
    }
}
