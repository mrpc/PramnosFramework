<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\TwoFactorAuthService;

/**
 * The same cleanup on PostgreSQL, where a boolean is not an integer and a timestamp is not a
 * Unix time — the older shape is the one that failed every night.
 */
#[CoversClass(TwoFactorAuthService::class)]
#[\PHPUnit\Framework\Attributes\Group('serial')]
class TwoFactorSetupCleanupPostgreSQLTest extends TwoFactorSetupCleanupTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
