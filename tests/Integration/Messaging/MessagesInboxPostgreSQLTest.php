<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Messaging\Controllers\MessagesController;

/**
 * Every inbox assertion again, against PostgreSQL/TimescaleDB.
 *
 * The `type` filtering here is `whereIn` and the ordering is on an integer `date` column — both
 * compiled per driver, so "it works" is a claim about one engine until the other has run it.
 */
#[CoversClass(MessagesController::class)]
class MessagesInboxPostgreSQLTest extends MessagesInboxTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
