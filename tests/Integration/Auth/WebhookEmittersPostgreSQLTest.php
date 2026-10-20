<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\WebhookService;

/**
 * The same emitters, on PostgreSQL/TimescaleDB, where the events' `user_id` is a foreign key to
 * `users` — so an event about an erased account must leave the column empty.
 */
#[CoversClass(WebhookService::class)]
class WebhookEmittersPostgreSQLTest extends WebhookEmittersTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
