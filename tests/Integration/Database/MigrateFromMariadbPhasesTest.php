<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\Group;

/**
 * The same move with MariaDB 10.11 as the source: the database most installations that move to
 * TimescaleDB actually come from. It differs where a move feels it — JSON kept as `longtext`,
 * defaults quoted, `CURRENT_TIMESTAMP` as `current_timestamp()` — and every assertion of the
 * MySQL run has to hold here too. Runs with `./dockertest --mariadb`.
 */
#[Group('mariadb')]
final class MigrateFromMariadbPhasesTest extends MigrateFromMysqlPhasesTest
{
    /** The MariaDB service. */
    protected static function sourceHost(): string
    {
        return 'mariadb';
    }
}
