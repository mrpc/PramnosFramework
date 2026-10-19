<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Pramnos\Database\Database;

/**
 * {@see ChangelogEventsTest} on MariaDB, which refuses a timestamp with an offset — what the
 * events were written with before. Run by `./dockertest --mariadb`, outside the main suite.
 */
#[Group('mariadb')]
#[CoversClass(\Pramnos\Changelog\ChangelogWriter::class)]
#[CoversClass(\Pramnos\Changelog\History::class)]
#[CoversClass(\Pramnos\Changelog\HistoryApi::class)]
#[CoversClass(\Pramnos\Html\HistoryPanel::class)]
final class ChangelogEventsMariaDbTest extends ChangelogEventsTest
{
    /** The MariaDB container; null skips when it is not reachable. */
    protected function connection(): ?Database
    {
        \Pramnos\Application\Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
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
