<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Webhook;

/**
 * The same registration assertions, on PostgreSQL/TimescaleDB.
 *
 * `storeEndpoint()` is an upsert, which compiles to two different statements — so "one endpoint per
 * application per event type" is a claim about both, and only one had ever run.
 */
#[CoversClass(Webhook::class)]
class WebhookStoragePostgreSQLTest extends WebhookStorageTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
