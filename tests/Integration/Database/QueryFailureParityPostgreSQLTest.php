<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;

/**
 * The same parity assertions, on the backend they exist for.
 *
 * Every one of them passes on MySQL whatever the flag says, because mysqli throws regardless. It
 * is this class that proves the flag does anything.
 */
#[CoversClass(Database::class)]
class QueryFailureParityPostgreSQLTest extends QueryFailureParityTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
