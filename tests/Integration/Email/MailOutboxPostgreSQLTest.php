<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * The same outbox, against PostgreSQL / TimescaleDB.
 *
 * Extends the MySQL lane and overrides only the connection, so every assertion runs identically
 * on both. What differs underneath is worth stating: the deadline sweep is one `UPDATE` over a
 * range, and PostgreSQL takes no `LIMIT` on an `UPDATE` — so the statement `expire()` issues is
 * the same statement here and the row count comes back off the `Result` either way.
 *
 * The singleton is replaced for the duration and **restored in tearDown**. `Email::writeMailRow()`
 * reaches the database through `Factory::getDatabase()` rather than through an injected handle,
 * so without the swap the rows would be written to MySQL while the assertions read PostgreSQL —
 * and without the restore every later test in the suite would inherit this connection.
 */
class MailOutboxPostgreSQLTest extends MailOutboxMySQLTest
{
    protected function setUp(): void
    {
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }

        $this->rememberSingleton(Factory::getDatabase());

        $pgDb = new Database();
        $pgDb->type     = 'postgresql';
        $pgDb->server   = $pgSettings->hostname;
        $pgDb->user     = $pgSettings->user;
        $pgDb->password = $pgSettings->password;
        $pgDb->database = $pgSettings->database;
        $pgDb->port     = $pgSettings->port ?? 5432;
        $pgDb->schema   = $pgSettings->schema ?? 'public';

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $exception) {
            $this->markTestSkipped(
                'TimescaleDB container not reachable ('
                . $pgSettings->hostname . ':' . ($pgSettings->port ?? 5432) . '): '
                . $exception->getMessage()
            );
        }

        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db = $pgDb;

        $this->createMailsTable();
        $this->tag = 'outbox-test-' . bin2hex(random_bytes(4));
    }
}
