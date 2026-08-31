<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Security\Encrypter;

/**
 * The encrypted settings are encrypted *in the database* — asserted by reading
 * the row, not the API.
 *
 * The unit tests around `Settings` prove that a caller writing a password reads a
 * password back. That is exactly what a completely broken implementation would
 * also prove: store the plaintext, return the plaintext, everything passes. The
 * only assertion that distinguishes the two is on the column itself, which is why
 * this test talks to a real database and reads the raw value with SQL that does
 * not go through `Settings` at all.
 *
 * Requires the Docker MySQL container.
 */
#[CoversClass(Settings::class)]
class SettingsEncryptionAtRestTest extends TestCase
{
    private Database $db;
    private string $table;
    private string|false $originalKey = false;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        $this->table = $this->db->prefix . 'settings';

        $this->db->execute('DROP TABLE IF EXISTS `' . $this->table . '`');
        $this->db->execute('CREATE TABLE `' . $this->table . '` (
            `setting` VARCHAR(255) NOT NULL PRIMARY KEY,
            `value`   TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->originalKey = getenv('APP_KEY');
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;

        $this->resetStatics();
        Settings::setDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `' . $this->table . '`');
        $this->db->close();

        if ($this->originalKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->originalKey);
            $_ENV['APP_KEY'] = $this->originalKey;
        }

        $this->resetStatics();
    }

    /** Wipe the static store so a read has to reach the database. */
    private function resetStatics(): void
    {
        $ref = new \ReflectionClass(Settings::class);
        $ref->getProperty('settings')->setValue(null, []);
        $ref->getProperty('loaded')->setValue(null, false);
        $ref->getProperty('bulkLoaded')->setValue(null, false);
        $ref->getProperty('database')->setValue(null, null);
    }

    /** The column as it actually is, read without going through Settings. */
    private function rawColumn(string $setting): ?string
    {
        $result = $this->db->query(
            "SELECT `value` FROM `{$this->table}` WHERE `setting` = '"
            . $this->db->prepareInput($setting) . "'"
        );

        return ($result && $result->numRows > 0)
            ? (string) $result->fields['value']
            : null;
    }

    /**
     * The SMTP password does not appear in the database in readable form.
     *
     * This is the finding this change exists for: anyone who reads the settings
     * table — a leaked backup, an SQL injection somewhere else in the application,
     * a hosting neighbour, a DBA — could previously send mail as the operator.
     */
    public function testSmtpPasswordIsCiphertextInTheColumn(): void
    {
        // Act
        Settings::setSetting('smtp_pass', 'the-real-smtp-password');

        // Assert — the column, not the API.
        $stored = $this->rawColumn('smtp_pass');
        $this->assertNotNull($stored, 'The setting was not written at all.');
        $this->assertStringNotContainsString('the-real-smtp-password', $stored);
        $this->assertTrue(
            Encrypter::isEncrypted($stored),
            'smtp_pass was stored without the encryption marker: ' . $stored
        );
    }

    /**
     * And it comes back out again through a fresh read that has to hit the
     * database — an encrypted column nobody can read is not a feature.
     */
    public function testEncryptedSettingSurvivesARoundTripThroughTheDatabase(): void
    {
        // Arrange
        Settings::setSetting('smtp_pass', 'the-real-smtp-password');

        // Act — forget everything in memory, so the answer has to come from the row.
        $this->resetStatics();
        Settings::setDatabase($this->db);

        // Assert
        $this->assertSame('the-real-smtp-password', Settings::getSetting('smtp_pass'));
    }

    /**
     * A row already holding plaintext keeps working, and converts itself when it is
     * next written.
     *
     * The two halves of the migration story, in the order an installation
     * experiences them: upgrade (still readable), then save the settings screen
     * once (now encrypted).
     */
    public function testPlaintextRowIsReadableThenConvertsOnTheNextWrite(): void
    {
        // Arrange — a row as it exists on an installation upgrading today.
        $this->db->execute(
            "INSERT INTO `{$this->table}` (`setting`, `value`) "
            . "VALUES ('smtp_pass', 'legacy-plaintext-password')"
        );

        // The bulk read is cached for Settings::CACHE_TTL under the "settings"
        // category, and a write that goes around Settings does not invalidate it —
        // only setSetting() does. Without this the row inserted above is invisible
        // for the next five minutes and the test reads a stale empty result.
        $this->db->cacheflush('settings');

        // Assert — readable before anything converts it.
        $this->assertSame('legacy-plaintext-password', Settings::getSetting('smtp_pass'));
        $this->assertFalse(Encrypter::isEncrypted((string) $this->rawColumn('smtp_pass')));

        // Act — the operator saves the settings screen.
        Settings::setSetting('smtp_pass', 'legacy-plaintext-password');

        // Assert — same value, now encrypted at rest.
        $this->assertTrue(Encrypter::isEncrypted((string) $this->rawColumn('smtp_pass')));

        $this->resetStatics();
        Settings::setDatabase($this->db);
        $this->assertSame('legacy-plaintext-password', Settings::getSetting('smtp_pass'));
    }

    /**
     * Settings that are not credentials stay readable in the column.
     *
     * Encrypting everything would make the settings table unreadable to an operator
     * with `psql`, for no gain — a site name is not a secret.
     */
    public function testOrdinarySettingsAreStoredInTheClear(): void
    {
        // Act
        Settings::setSetting('sitename', 'My Application');

        // Assert
        $this->assertSame('My Application', $this->rawColumn('sitename'));
    }
}
