<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Pramnos\Database\Database;

/**
 * {@see BouncesTest} on MariaDB — where applications keep their own `bounces`. Run by
 * `./dockertest --mariadb`, outside the main suite.
 */
#[Group('mariadb')]
#[CoversClass(\Pramnos\Email\Bounces::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Messaging\CreateBouncesTable::class)]
final class BouncesMariaDbTest extends BouncesTest
{
    /** The MariaDB container; null skips when it is not reachable. */
    protected function connection(): ?Database
    {
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = 'mariadb';
        $db->port     = 3306;
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            return $db->connect(false) ? $db : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
