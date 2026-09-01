<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Media;

/**
 * The same rendition policy, against real PostgreSQL.
 *
 * None of the behaviour under test is SQL — the only statement involved is the existing md5 dedupe
 * `SELECT` — so what this lane proves is that the two tables the shipped migration builds carry these
 * rows identically on the other engine, and that `MediaObject` reads them back the same. Which is
 * exactly the class of thing that has broken here before: the same PHP over a schema whose `text`
 * column was a `varchar` on one side, or an auto-increment that came back as a string.
 *
 * Everything else is inherited. A lane that repeated the assertions could disagree with the first
 * one about what it was asserting.
 */
class MediaRenditionPolicyPostgreSQLTest extends MediaRenditionPolicyTest
{
    /**
     * PostgreSQL, in the timescaledb container.
     *
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }
}
