<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the loginlockout table — progressive brute-force lockout state store.
 *
 * Tracks failed login attempts per (locktype, lookupvalue) pair. Three lock types
 * are supported: 'user' (by user ID string), 'identifier' (by normalised
 * username/email), and 'ip' (by IP address).
 *
 * Timestamps are stored as TIMESTAMPTZ (PostgreSQL) / DATETIME (MySQL) to align
 * with the Urbanwater production schema. NULL timestamps mean "never occurred"
 * (replaces the old integer 0 sentinel). createdat and updatedat are NOT NULL
 * with a DEFAULT NOW() / CURRENT_TIMESTAMP so rows are always auditable.
 *
 * String columns that were previously nullable (displayvalue, lastipaddress,
 * lastuseragent, lastchannel, unlockreason) are now NOT NULL DEFAULT '' to
 * simplify query-side comparisons and match Urbanwater.
 *
 * @package PramnosFramework
 */
class CreateLoginlockoutTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 70;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.loginlockouts progressive brute-force state table';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($db->schema()->hasTable('authserver.loginlockouts')) {
            return;
        }

        $t = $db->schema()->quoteTable('authserver.loginlockouts');

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                lockoutid      SERIAL PRIMARY KEY,
                locktype       VARCHAR(20)   NOT NULL,
                lookupvalue    VARCHAR(255)  NOT NULL,
                displayvalue   VARCHAR(255)  NOT NULL DEFAULT '',
                userid         BIGINT,
                failedattempts INTEGER       NOT NULL DEFAULT 0,
                firstfailedat  TIMESTAMPTZ,
                lastfailedat   TIMESTAMPTZ,
                lockoutuntil   TIMESTAMPTZ,
                lastipaddress  VARCHAR(45)   NOT NULL DEFAULT '',
                lastuseragent  TEXT          NOT NULL DEFAULT '',
                lastchannel    VARCHAR(50)   NOT NULL DEFAULT '',
                lastunlockedat TIMESTAMPTZ,
                lastunlockedby BIGINT,
                unlockreason   TEXT          NOT NULL DEFAULT '',
                createdat      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                updatedat      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
            )");
            $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uniq_loginlockouts_lookup
                ON {$t} (locktype, lookupvalue)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_loginlockouts_active
                ON {$t} (locktype, lockoutuntil DESC, updatedat DESC)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_loginlockouts_userid
                ON {$t} (userid, lockoutuntil DESC)");
            $db->query("COMMENT ON TABLE {$t} IS
                'Progressive brute-force lockout state: tracks failed login attempts per scope+identifier pair'");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                `lockoutid`      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `locktype`       VARCHAR(20)  NOT NULL,
                `lookupvalue`    VARCHAR(255) NOT NULL,
                `displayvalue`   VARCHAR(255) NOT NULL DEFAULT '',
                `userid`         BIGINT,
                `failedattempts` INT          NOT NULL DEFAULT 0,
                `firstfailedat`  DATETIME     NULL,
                `lastfailedat`   DATETIME     NULL,
                `lockoutuntil`   DATETIME     NULL,
                `lastipaddress`  VARCHAR(45)  NOT NULL DEFAULT '',
                `lastuseragent`  TEXT         NULL,
                `lastchannel`    VARCHAR(50)  NOT NULL DEFAULT '',
                `lastunlockedat` DATETIME     NULL,
                `lastunlockedby` BIGINT,
                `unlockreason`   TEXT         NULL,
                `createdat`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updatedat`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_loginlockouts_lookup` (`locktype`, `lookupvalue`),
                KEY `idx_loginlockouts_active` (`locktype`, `lockoutuntil` DESC, `updatedat` DESC),
                KEY `idx_loginlockouts_userid` (`userid`, `lockoutuntil` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Progressive brute-force lockout state: tracks failed login attempts per scope+identifier pair'");
        }
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.loginlockouts');
    }
}
