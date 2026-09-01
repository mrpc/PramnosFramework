<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Model;

/**
 * The same pagination on PostgreSQL/TimescaleDB.
 *
 * `LIMIT`/`OFFSET` and the aliased `count(a.key)` are spelled the same on both backends and answered
 * by different drivers — and the count is a *second* query whose answer has to agree with the first.
 * A total that came back as a string on one backend and an integer on the other would make
 * `ceil($total / $items)` differ in the last page, which is the page nobody checks.
 */
#[CoversClass(Model::class)]
class ModelPaginationPostgreSQLTest extends ModelPaginationTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
