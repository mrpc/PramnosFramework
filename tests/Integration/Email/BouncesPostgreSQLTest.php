<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;

/** {@see BouncesTest} on PostgreSQL. */
#[CoversClass(\Pramnos\Email\Bounces::class)]
#[CoversClass(\Pramnos\Email\Email::class)]
#[CoversClass(\Pramnos\Console\Commands\MailFlush::class)]
#[CoversClass(\Pramnos\Console\Commands\MailBounces::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Messaging\CreateBouncesTable::class)]
final class BouncesPostgreSQLTest extends BouncesTest
{
    /** The PostgreSQL container; null skips when it is not reachable. */
    protected function connection(): ?Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            return $db->connect(false) ? $db : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
