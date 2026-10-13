<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\Group;

/**
 * Every framework migration on a fresh MariaDB installation with a table prefix — the database
 * an application runs in production and the suite does not. Run by `./dockertest --mariadb`;
 * not part of the main suite.
 */
#[Group('mariadb')]
class MariaDbPrefixedFrameworkMigrationsTest extends PrefixedFrameworkMigrationsTest
{
    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return ['mariadb' => ['mysql', 'mariadb', 3306, 'root']];
    }
}
