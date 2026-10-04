<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The same, on PostgreSQL, where `users` is a different table and the id comes from a sequence.
 */
#[CoversClass(BaseTestCase::class)]
class TestUsersAreErasedPostgreSQLTest extends TestUsersAreErasedTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
