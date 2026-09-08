<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

/**
 * The same contract on PostgreSQL.
 *
 * The lane that matters most for this report: the message the customer received was
 * PostgreSQL's, and its `DETAIL:` line volunteers the colliding key where MySQL's does not.
 */
class ModelSaveHidesTheDriverMessagePostgreSQLTest extends ModelSaveHidesTheDriverMessageTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
