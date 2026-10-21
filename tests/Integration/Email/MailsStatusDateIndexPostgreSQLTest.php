<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

/**
 * {@see MailsStatusDateIndexTest} on PostgreSQL, where an index is a schema object of its own and
 * is found through the catalogue rather than `SHOW INDEX`.
 */
class MailsStatusDateIndexPostgreSQLTest extends MailsStatusDateIndexTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
