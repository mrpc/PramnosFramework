<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\Group;

/**
 * The analyst's SQL on MariaDB, where the time limit is `SET STATEMENT max_statement_time … FOR`
 * rather than MySQL's session variable — the one branch MySQL cannot reach. Run by
 * `./dockertest --mariadb`, outside the main suite.
 */
#[Group('mariadb')]
class MariaDbAnalystSqlTest extends AnalystSqlTest
{
    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mariadb' => ['mysql', 'mariadb', 3306, 'root'],
        ];
    }
}
