<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Messaging\Controllers\MassMessagesController;

/**
 * The same compose-and-queue assertions, on PostgreSQL/TimescaleDB.
 *
 * `request` is a text column holding JSON and `status` is compared as an integer — both places
 * where the two engines have disagreed before in this codebase.
 */
#[CoversClass(MassMessagesController::class)]
class MassMessagesScreenPostgreSQLTest extends MassMessagesScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
