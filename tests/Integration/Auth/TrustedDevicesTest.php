<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Auth\TrustedDevices;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;

/**
 * "Don't ask again on this device", against a real database.
 *
 * What is being held to: the cookie carries a random token and the table only its hash;
 * trust belongs to one account and one browser; it ends at the days it was given, when it
 * is revoked, or when the site stops allowing it — and it is off until the site turns it on.
 */
#[CoversClass(TrustedDevices::class)]
class TrustedDevicesTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

    private const OWNER = 4701;
    private const OTHER = 4702;

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->db->schema()->ensureSchema('authserver');
        $this->db->schema()->dropTableIfExists(TrustedDevices::TABLE);
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverTrustedDevicesTable::class,
        ], $this->db);

        foreach ([TrustedDevices::ENABLED_SETTING, TrustedDevices::DAYS_SETTING, TrustedDevices::EXCLUDE_ADMINS_SETTING] as $name) {
            $this->savedSettings[$name] = Settings::getSetting($name, null);
        }
        Settings::setSetting(TrustedDevices::ENABLED_SETTING, '1', false);
        Settings::setSetting(TrustedDevices::DAYS_SETTING, '30', false);
        Settings::setSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, '0', false);
        unset($_COOKIE[TrustedDevices::COOKIE]);
    }

    protected function tearDown(): void
    {
        if ($this->db->connected) {
            $this->db->schema()->dropTableIfExists(TrustedDevices::TABLE);
        }
        foreach ($this->savedSettings as $name => $value) {
            Settings::setSetting($name, $value === null ? '' : (string) $value, false);
        }
        unset($_COOKIE[TrustedDevices::COOKIE]);

        parent::tearDown();
    }

    /** A service with the clock, the browser and the account's usertype in the test's hands. */
    private function devices(int $usertype = 1): TrustedDevices
    {
        return new class ($usertype) extends TrustedDevices {
            public int $now;
            public ?string $written = null;
            public int $writtenUntil = 0;

            public function __construct(public int $usertype)
            {
                $this->now = time();
            }

            protected function now(): int
            {
                return $this->now;
            }

            protected function writeCookie(string $token, int $expires): void
            {
                $this->written      = $token;
                $this->writtenUntil = $expires;
                $_COOKIE[self::COOKIE] = $token;
            }

            protected function userAgent(): string
            {
                return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
            }

            protected function ip(): string
            {
                return '203.0.113.9';
            }

            protected function usertypeOf(int $userId): int
            {
                return $this->usertype;
            }
        };
    }

    /** @return array<string, mixed>|null */
    private function row(int $deviceId): ?array
    {
        $row = $this->db->queryBuilder()->table(TrustedDevices::TABLE)->where('device_id', $deviceId)->first();

        return $row && ($row->numRows ?? 0) > 0 ? (array) $row->fields : null;
    }

    /**
     * Trusting a browser writes the cookie's token to the browser and only its hash to the
     * table, for the configured days, with what the list needs to name the device.
     *
     * A copy of the table must not be a way to skip anybody's second factor.
     */
    public function testTheCookieHoldsTheTokenAndTheTableOnlyItsHash(): void
    {
        // Arrange
        $devices = $this->devices();

        // Act
        $id = $devices->trust(self::OWNER);

        // Assert
        $this->assertNotNull($id);
        $row = $this->row((int) $id);
        $this->assertSame(Token::lookup((string) $devices->written), $row['token_lookup']);
        $this->assertStringNotContainsString((string) $devices->written, json_encode($row));
        $this->assertSame($devices->now + 30 * 86400, (int) $row['expires_at'], 'the days the site gives');
        $this->assertSame($devices->writtenUntil, (int) $row['expires_at'], 'the cookie lasts as long as the row');
        $this->assertSame('safari|ios', $row['fingerprint']);
        $this->assertSame('203.0.113.9', $row['ip']);
    }

    /**
     * The trusted browser is recognised for its own account — and for no other, even
     * carrying the same cookie. A trust is a promise about one person on one browser.
     */
    public function testTrustBelongsToOneAccount(): void
    {
        // Arrange
        $devices = $this->devices();
        $devices->trust(self::OWNER);

        // Act & Assert
        $this->assertTrue($devices->isTrusted(self::OWNER));
        $this->assertFalse($devices->isTrusted(self::OTHER), 'the cookie means nothing for another account');
        $this->assertNull($devices->current(self::OTHER));
    }

    /**
     * Without the cookie — a different browser, or cleared site data — nothing is trusted,
     * whatever the table holds.
     */
    public function testAnotherBrowserIsNotTrusted(): void
    {
        // Arrange
        $devices = $this->devices();
        $devices->trust(self::OWNER);

        // Act
        unset($_COOKIE[TrustedDevices::COOKIE]);

        // Assert
        $this->assertFalse($devices->isTrusted(self::OWNER));

        // And a forged cookie is not a trusted one.
        $_COOKIE[TrustedDevices::COOKIE] = str_repeat('a', 64);
        $this->assertFalse($devices->isTrusted(self::OWNER));
    }

    /**
     * Trust ends when its days do. Using the browser does not extend it — the days are the
     * days the person was told on the page.
     */
    public function testTrustEndsAtItsDaysAndIsNotExtendedByUse(): void
    {
        // Arrange
        $devices = $this->devices();
        $id      = (int) $devices->trust(self::OWNER);
        $expires = (int) $this->row($id)['expires_at'];

        // Act — used a day before it ends, then the day after
        $devices->now = $expires - 86400;
        $usedBefore   = $devices->isTrusted(self::OWNER);
        $devices->now = $expires + 1;

        // Assert
        $this->assertTrue($usedBefore);
        $this->assertSame($expires, (int) $this->row($id)['expires_at'], 'use did not extend it');
        $this->assertSame($expires - 86400, (int) $this->row($id)['last_used_at'], 'but it was recorded');
        $this->assertFalse($devices->isTrusted(self::OWNER));
        $this->assertSame([], $devices->forUser(self::OWNER), 'and the list no longer shows it');
    }

    /**
     * A revoked device is asked again; revoking another account's device does nothing; and
     * "forget all" forgets every device and says how many.
     */
    public function testDevicesAreRevokedOneOrAllAndOnlyTheirOwn(): void
    {
        // Arrange — two browsers of the owner, one of another account
        $phone = $this->devices();
        $one   = (int) $phone->trust(self::OWNER);
        $two   = (int) $this->devices()->trust(self::OWNER);
        $theirs = (int) $this->devices()->trust(self::OTHER);

        // Act & Assert — somebody else's device cannot be revoked from this account
        $this->assertFalse($phone->revoke(self::OWNER, $theirs));
        $this->assertNull($this->row($theirs)['revoked_at']);

        // One
        $this->assertTrue($phone->revoke(self::OWNER, $one));
        $this->assertFalse($phone->revoke(self::OWNER, $one), 'a second revoke finds nothing to revoke');
        $this->assertCount(1, $phone->forUser(self::OWNER));

        // All
        $this->assertSame(1, $phone->revokeAll(self::OWNER));
        $this->assertSame(0, $phone->revokeAll(self::OWNER));
        $this->assertNotNull($this->row($two)['revoked_at']);
        $this->assertSame([], $phone->forUser(self::OWNER));
    }

    /**
     * The list names each device and marks the one being looked at, newest first.
     */
    public function testTheListNamesEachDeviceAndMarksThisOne(): void
    {
        // Arrange — an older device, then this browser
        $older = $this->devices();
        $older->now -= 100;
        $older->trust(self::OWNER);
        $this_ = $this->devices();
        $this_->trust(self::OWNER);

        // Act
        $list = $this_->forUser(self::OWNER);

        // Assert
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['is_current'], 'the newest is this browser');
        $this->assertFalse($list[1]['is_current']);
        $this->assertSame('Safari on iPhone or iPad', $list[0]['name']);
    }

    /**
     * Off until the site turns it on; and with administrators excluded, an administrator can
     * neither trust a browser nor use one trusted before the setting changed.
     */
    public function testTheSiteDecidesWhoMayTrustADevice(): void
    {
        // Arrange
        $admin = $this->devices(TrustedDevices::ADMIN_USERTYPE);
        $admin->trust(self::OWNER);

        // Act — the site excludes administrators
        Settings::setSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, '1', false);

        // Assert
        $this->assertFalse($admin->isTrusted(self::OWNER), 'an earlier trust ends with the setting');
        $this->assertNull($admin->trust(self::OWNER));
        $this->assertTrue($this->devices(1)->allowedFor(self::OTHER), 'everybody else still may');

        // And switched off, nobody may, and nothing is read as trusted.
        Settings::setSetting(TrustedDevices::ENABLED_SETTING, '0', false);
        $this->assertFalse($this->devices(1)->allowedFor(self::OTHER));
        $this->assertNull($this->devices(1)->current(self::OWNER));
        $this->assertFalse($this->devices(1)->allowedFor(0), 'and never an anonymous account');
    }

    /**
     * The days are the site's, kept between one and 365; nonsense is the default thirty.
     */
    public function testTheDaysAreBounded(): void
    {
        // Arrange
        $devices = $this->devices();
        $read = function (string $value) use ($devices): int {
            Settings::setSetting(TrustedDevices::DAYS_SETTING, $value, false);

            return $devices->days();
        };

        // Act & Assert
        $this->assertSame(7, $read('7'));
        $this->assertSame(365, $read('9999'));
        $this->assertSame(TrustedDevices::DEFAULT_DAYS, $read('0'));
        $this->assertSame(TrustedDevices::DEFAULT_DAYS, $read('soon'));
    }

    /**
     * Without its table the service answers "not trusted" rather than failing a sign-in.
     */
    public function testAMissingTableIsNotTrustRatherThanAnError(): void
    {
        // Arrange
        $devices = $this->devices();
        $devices->trust(self::OWNER);
        $this->db->schema()->dropTableIfExists(TrustedDevices::TABLE);

        // Act & Assert
        $this->assertFalse($devices->isTrusted(self::OWNER));
        $this->assertNull($devices->trust(self::OWNER));
        $this->assertSame([], $devices->forUser(self::OWNER));
        $this->assertFalse($devices->revoke(self::OWNER, 1));
        $this->assertSame(0, $devices->revokeAll(self::OWNER));
        $devices->touch(1);
        $this->assertSame([], $devices->forUser(0));
    }

    /**
     * The real usertype lookup: an account that cannot be read is not an administrator, so the
     * exclusion does not lock it out of trusting a device.
     */
    public function testAnUnreadableAccountIsNotAnAdministrator(): void
    {
        // Arrange
        $saved = Settings::getSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, null);
        Settings::setSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, '1', false);

        try {
            // Act
            $allowed = (new TrustedDevices())->allowedFor(987654);

            // Assert
            $this->assertTrue($allowed);
        } finally {
            Settings::setSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, $saved === null ? '' : (string) $saved, false);
        }
    }
}
