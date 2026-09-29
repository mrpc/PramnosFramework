<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Middleware\ApiKeyScopeMiddleware;

/**
 * {@see ApiKeyScopeResolverTest} on PostgreSQL.
 */
#[CoversClass(ApiKeyScopeMiddleware::class)]
class ApiKeyScopeResolverPostgreSQLTest extends ApiKeyScopeResolverTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
