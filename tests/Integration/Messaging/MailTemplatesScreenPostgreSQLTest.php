<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Messaging\Controllers\MailTemplatesController;

/**
 * Every template-screen assertion again, against PostgreSQL/TimescaleDB.
 *
 * Not a formality. The defect this pattern exists to catch was found the hard way: a `Model`
 * that addressed its own table correctly on MySQL and not on PostgreSQL, written and tested on
 * one engine, shipped green, and answered 500 on the first request from the other. A suite that
 * runs one backend cannot fail for the second — so the screen is exercised on both, from one set
 * of assertions.
 *
 * Skips itself when the container is not reachable, the way the other PostgreSQL tests do: a
 * developer without it should see a skip, not a failure they cannot act on.
 */
#[CoversClass(MailTemplatesController::class)]
class MailTemplatesScreenPostgreSQLTest extends MailTemplatesScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
