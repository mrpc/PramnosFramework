<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\Group;

/**
 * The grants table and the later `jwt_bearer` migration on MariaDB, with a prefix and without.
 *
 * MariaDB refuses `DROP CHECK`, and keeps a CHECK written on a column as part of the column,
 * beyond `DROP CONSTRAINT` — neither shows on MySQL. Run by `./dockertest --mariadb`, against
 * the profile-gated MariaDB service; not part of the main suite.
 */
#[Group('mariadb')]
class MariaDbOauthGrantsMigrationTest extends OauthGrantsMigrationTest
{
    /** @return array<string, array{string, string, int, string, string}> */
    public static function installations(): array
    {
        return [
            'mariadb'           => ['mysql', 'mariadb', 3306, 'root', ''],
            'mariadb, prefixed' => ['mysql', 'mariadb', 3306, 'root', 'pf_'],
        ];
    }
}
