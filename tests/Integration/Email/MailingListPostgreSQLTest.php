<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

/**
 * The same lists, on PostgreSQL/TimescaleDB — the table lives in the `pramnos` schema there.
 */
class MailingListPostgreSQLTest extends MailingListTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
