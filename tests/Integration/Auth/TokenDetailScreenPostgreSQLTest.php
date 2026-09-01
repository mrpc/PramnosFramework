<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\TokensController;

/**
 * The same token detail page, on PostgreSQL/TimescaleDB.
 *
 * Worth the second lane for one line in particular: `($page - 1) * $limit` with a page below one
 * is a negative offset, which this engine refuses outright and the other tolerates. And
 * `tokenactions` is a hypertable here, so the `COUNT(*)` and the `LIMIT`/`OFFSET` run against a
 * partitioned table rather than a plain one.
 */
#[CoversClass(TokensController::class)]
class TokenDetailScreenPostgreSQLTest extends TokenDetailScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
