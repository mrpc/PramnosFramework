<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\User\UrlRegistry;

/** The same registry made sound, on PostgreSQL. */
#[CoversClass(UrlRegistry::class)]
class UrlRegistryShaPostgreSQLTest extends UrlRegistryShaTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
