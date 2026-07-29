<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Integration test for the optional session time zone applied on connect
 * (Database::$timezone), against the real PostgreSQL container.
 *
 * When configured, the timezone is issued as `SET TIME ZONE` per connection so
 * NOW()/timestamp rendering match the app's zone; when unset (the default) no
 * SET is issued and the server default is left untouched — the null-default is
 * what keeps this backward-compatible for existing apps.
 */
class DatabaseTimezonePostgreSQLTest extends TestCase
{
    private function db(?string $timezone): Database
    {
        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->timezone = $timezone;
        $db->connect(true);

        return $db;
    }

    private function sessionTimezone(Database $db): string
    {
        $result = $db->query("SELECT current_setting('TIMEZONE') AS tz");
        return (string) $result->fields['tz'];
    }

    /**
     * A configured timezone is applied to the session on connect.
     */
    public function testConfiguredTimezoneIsAppliedOnConnect(): void
    {
        $this->assertSame('Europe/Athens', $this->sessionTimezone($this->db('Europe/Athens')));
    }

    /**
     * It is driven by config, not hard-coded: a different zone is honoured too.
     */
    public function testTimezoneIsConfigDriven(): void
    {
        $this->assertSame('UTC', $this->sessionTimezone($this->db('UTC')));
    }

    /**
     * With no timezone configured (null), no SET is issued — the connection keeps
     * the server default rather than being forced to the app zone. Proves the
     * backward-compatible no-op path.
     */
    public function testUnsetTimezoneLeavesServerDefault(): void
    {
        $tz = $this->sessionTimezone($this->db(null));
        $this->assertNotSame('Europe/Athens', $tz, 'null timezone must not force the app zone');
    }
}
