<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Middleware\ApplicationStatsMiddleware;

/**
 * {@see ApplicationStatsMiddlewareTest} on PostgreSQL, where the table is a hypertable.
 */
#[CoversClass(ApplicationStatsMiddleware::class)]
#[\PHPUnit\Framework\Attributes\Group('serial')]
class ApplicationStatsMiddlewarePostgreSQLTest extends ApplicationStatsMiddlewareTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
