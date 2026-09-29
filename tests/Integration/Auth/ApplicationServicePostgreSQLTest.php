<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\ApplicationService;

/**
 * {@see ApplicationServiceTest} on PostgreSQL.
 */
#[CoversClass(ApplicationService::class)]
class ApplicationServicePostgreSQLTest extends ApplicationServiceTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
