<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Integration tests for the GDPR data export (Account::buildExportData and its
 * per-source collectors) against a real MySQL database.
 *
 * Verifies the observable end-to-end result: every section is present and
 * populated from the real tables, the app-extensibility hook merges extra
 * sections without clobbering core ones, and — critically — that NO secret ever
 * leaves the building: passwords, token values, TOTP secrets/backup codes,
 * passkey public keys/credential ids, request bodies (tokenactions.params) and
 * password-reset hashes are all excluded from the payload.
 *
 * Tables that ship a migration are built from it (single source of truth);
 * the rest are created with the exact columns the collectors read.
 *
 * Runs on MySQL only (authserver.* maps to the authserver_ prefix); the
 * QueryBuilder abstracts the driver so the logic is identical on PostgreSQL.
 */
#[CoversClass(Account::class)]
class AccountExportTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('AccountExportTest runs on MySQL only.');
        }

        User::setupDb(); // users, usertokens, userdetails, …
        $this->buildTables();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Event::forget('account.data_export');
        // Remove seeded rows (leave structure for the next test in the run).
        if ($this->uid > 0) {
            foreach (['users', 'usertokens', 'userdetails', 'applications', 'sessions',
                      'authserver.user_activity_log', 'authserver.oauth2_user_consents',
                      'authserver.passkey_credentials', 'authserver.user_twofactor',
                      'authserver.user_privacy_settings', 'tokenactions', 'urls'] as $t) {
                try {
                    $col = ($t === 'applications') ? 'appid' : (($t === 'urls') ? 'urlid' : 'userid');
                    if ($t === 'applications' || $t === 'urls') {
                        continue; // truncate-free: leave lookup rows
                    }
                    $this->db->queryBuilder()->table($t)->where('userid', $this->uid)->delete();
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }

    // ── Fixture ────────────────────────────────────────────────────────────────

    /** Build every table the export reads — from migrations where they exist. */
    private function buildTables(): void
    {
        $p = $this->db->prefix;

        // Tables with a canonical migration → build from it (single source of truth).
        foreach ([
            'authserver_user_activity_log'  => \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
            'authserver_user_twofactor'     => \Pramnos\Framework\Migrations\Auth\CreateUserTwofactorTable::class,
            'authserver_passkey_credentials'=> \Pramnos\Framework\Migrations\AuthServer\CreatePasskeyCredentialsTable::class,
            'urls'                          => \Pramnos\Framework\Migrations\Auth\CreateUrlsTable::class,
            'tokenactions'                  => \Pramnos\Framework\Migrations\Auth\CreateTokenactionsTable::class,
        ] as $table => $migration) {
            $this->db->query("DROP TABLE IF EXISTS `{$p}{$table}`");
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserTwofactorTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreatePasskeyCredentialsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUrlsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateTokenactionsTable::class,
        ], $this->db);

        // Hand-rolled tables (no standalone migration or authserver naming) with
        // exactly the columns the collectors read.
        $this->db->query("DROP TABLE IF EXISTS `{$p}applications`");
        $this->db->query("CREATE TABLE `{$p}applications` (
            `appid` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `apikey` VARCHAR(255) NOT NULL DEFAULT '',
            `apisecret` VARCHAR(255) NOT NULL DEFAULT '',
            `description` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}authserver_oauth2_user_consents` (
            `id` bigint AUTO_INCREMENT PRIMARY KEY,
            `userid` bigint NOT NULL,
            `applicationid` int NOT NULL,
            `scope` text DEFAULT NULL,
            `created_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}authserver_user_privacy_settings` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `userid` bigint NOT NULL,
            `share_usage_analytics` tinyint NOT NULL DEFAULT 0,
            `marketing_emails` tinyint NOT NULL DEFAULT 0,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}sessions` (
            `sid` varchar(255) NOT NULL,
            `userid` bigint NOT NULL DEFAULT 0,
            `guest` tinyint NOT NULL DEFAULT 1,
            `logout` tinyint NOT NULL DEFAULT 0,
            `host_addr` varchar(45) DEFAULT NULL,
            `agent` varchar(255) DEFAULT NULL,
            `time` int NOT NULL DEFAULT 0,
            `url` varchar(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Insert one user plus a secret-bearing row in every source table. */
    private function seed(): void
    {
        $u = new User();
        $u->username = 'export_' . bin2hex(random_bytes(4));
        $u->email    = $u->username . '@example.com';
        $u->setPassword('Secr3t!pass');
        $u->save();
        $this->uid = (int) $u->userid;
        $now = time();

        $this->db->queryBuilder()->table('applications')->insert([
            'appid' => 1, 'name' => 'Export App', 'apikey' => 'k', 'apisecret' => 's', 'status' => 1,
        ]);
        // usertokens: token value is a SECRET that must never be exported.
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid' => $this->uid, 'tokentype' => 'oauth', 'token' => 'SECRET_TOKEN_VALUE',
            'applicationid' => 1, 'status' => 1, 'created' => $now, 'lastused' => $now, 'expires' => 0,
        ]);
        // userdetails: a normal field + a password-reset hash that must be excluded.
        $this->db->queryBuilder()->table('userdetails')->insert([
            'userid' => $this->uid, 'fieldname' => 'displayname', 'value' => 'Exported User',
        ]);
        $this->db->queryBuilder()->table('userdetails')->insert([
            'userid' => $this->uid, 'fieldname' => 'password_reset_hash', 'value' => 'SECRET_RESET_HASH',
        ]);
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid' => $this->uid, 'action' => 'login', 'created_at' => date('c', $now),
        ]);
        $this->db->queryBuilder()->table('authserver.oauth2_user_consents')->insert([
            'userid' => $this->uid, 'applicationid' => 1, 'scope' => 'profile email',
            'created_at' => date('Y-m-d H:i:s', $now), 'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
        // passkey: public_key + credential_id are SECRETS that must be excluded.
        $this->db->queryBuilder()->table('authserver.passkey_credentials')->insert([
            'userid' => $this->uid, 'credential_id' => 'SECRET_CRED_ID', 'public_key' => 'SECRET_PUBLIC_KEY',
            'sign_count' => 0, 'name' => 'My Security Key', 'transports' => '["usb"]',
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s', $now),
        ]);
        // 2FA: secret + backup_codes are SECRETS that must be excluded.
        $this->db->queryBuilder()->table('authserver.user_twofactor')->insert([
            'userid' => $this->uid, 'enabled' => 1, 'secret' => 'SECRET_TOTP', 'backup_codes' => '["a","b"]',
            'last_used' => 0, 'setup_completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->queryBuilder()->table('authserver.user_privacy_settings')->insert([
            'userid' => $this->uid, 'share_usage_analytics' => 1, 'marketing_emails' => 0,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
        $this->db->queryBuilder()->table('sessions')->insert([
            'sid' => 'sess-abc', 'userid' => $this->uid, 'guest' => 0, 'logout' => 0,
            'host_addr' => '203.0.113.1', 'agent' => 'Firefox', 'time' => $now, 'url' => '/dashboard',
        ]);
        // tokenactions.params holds a raw request body (possible secret) — excluded.
        $this->db->queryBuilder()->table('urls')->insert(['urlid' => 1, 'url' => '/api/x', 'hash' => 0]);
        // Find the token's id for the action row.
        $tok = $this->db->queryBuilder()->table('usertokens')->select(['tokenid'])
            ->where('userid', $this->uid)->first();
        $tokenId = (int) ($tok->fields['tokenid'] ?? 0);
        $this->db->queryBuilder()->table('tokenactions')->insert([
            'tokenid' => $tokenId, 'urlid' => 1, 'method' => 'POST', 'params' => '{"password":"secret"}',
            'return_status' => 200, 'servertime' => $now,
        ]);
    }

    /** Invoke a protected Account method. */
    private function export(): array
    {
        $account = new Account();
        $m = new \ReflectionMethod(Account::class, 'buildExportData');
        return $m->invoke($account, $this->uid);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * Every documented section is present and the collectors that had seeded
     * rows come back populated.
     */
    public function testExportContainsAllSectionsPopulated(): void
    {
        $data = $this->export();

        foreach (['export_date','userid','profile','authorized_apps','oauth_consents',
                  'passkeys','two_factor','active_sessions','tokens','token_actions',
                  'account_details','privacy_settings','activity_log'] as $key) {
            $this->assertArrayHasKey($key, $data, "export must contain the '$key' section");
        }
        $this->assertSame($this->uid, $data['userid']);
        $this->assertNotEmpty($data['tokens'], 'tokens section must be populated');
        $this->assertNotEmpty($data['passkeys'], 'passkeys section must be populated');
        $this->assertNotEmpty($data['oauth_consents'], 'oauth_consents must be populated');
        $this->assertNotEmpty($data['active_sessions'], 'active_sessions must be populated');
        $this->assertNotEmpty($data['token_actions'], 'token_actions must be populated');
        $this->assertNotEmpty($data['activity_log'], 'activity_log must be populated');
        $this->assertSame(1, (int) ($data['two_factor']['enabled'] ?? 0));
        $this->assertSame('Exported User', $data['account_details']['displayname'] ?? null);
    }

    /**
     * No secret ever appears in the export payload.
     */
    public function testExportExcludesAllSecrets(): void
    {
        $data = $this->export();
        $blob = json_encode($data);

        // Profile must not carry password/salt.
        $this->assertArrayNotHasKey('password', $data['profile']);
        $this->assertArrayNotHasKey('salt', $data['profile']);

        // The concrete secret values must be nowhere in the serialised payload.
        foreach (['SECRET_TOKEN_VALUE','SECRET_PUBLIC_KEY','SECRET_CRED_ID',
                  'SECRET_TOTP','SECRET_RESET_HASH','"password":"secret"'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $blob,
                "export must not leak the secret: {$secret}");
        }

        // Column-level checks on the structured rows.
        $this->assertArrayNotHasKey('token', $data['tokens'][0] ?? []);
        $this->assertArrayNotHasKey('secret', $data['two_factor']);
        $this->assertArrayNotHasKey('backup_codes', $data['two_factor']);
        $this->assertArrayNotHasKey('public_key', $data['passkeys'][0] ?? []);
        $this->assertArrayNotHasKey('credential_id', $data['passkeys'][0] ?? []);
        $this->assertArrayNotHasKey('params', $data['token_actions'][0] ?? []);
        $this->assertArrayNotHasKey('password_reset_hash', $data['account_details']);
    }

    /**
     * Applications contribute extra sections via the 'account.data_export' event,
     * and a listener cannot overwrite a core section.
     */
    public function testExtensibilityHookMergesWithoutClobbering(): void
    {
        Event::listen('account.data_export', static function ($userId) {
            return [
                'licensing' => ['plan' => 'pro', 'user' => $userId],
                'profile'   => ['HIJACKED'], // must NOT overwrite the core profile
            ];
        });

        $data = $this->export();

        $this->assertArrayHasKey('licensing', $data, 'listener section must be merged in');
        $this->assertSame('pro', $data['licensing']['plan']);
        $this->assertNotSame(['HIJACKED'], $data['profile'],
            'a listener must not overwrite a core section');
        $this->assertArrayHasKey('username', $data['profile'],
            'the real profile row must survive');
    }

    /**
     * A collector whose table is missing degrades to an empty section instead
     * of throwing (guarded try/catch).
     */
    public function testMissingCollectorTableDegradesGracefully(): void
    {
        $p = $this->db->prefix;
        $this->db->query("DROP TABLE IF EXISTS `{$p}authserver_passkey_credentials`");

        $data = $this->export();

        $this->assertSame([], $data['passkeys'], 'a missing passkeys table must yield an empty section, not an error');
    }
}
