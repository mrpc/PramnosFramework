<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Security\Encrypter;

/**
 * Tests for the encryption of settings named in `Settings::ENCRYPTED_SETTINGS`.
 *
 * The contract is that nothing outside `Settings` notices. A caller writes a
 * plaintext password and reads a plaintext password; only the row is different.
 * These tests therefore assert on both sides of that boundary — what a caller
 * sees, and what actually lands in the store — because a change that broke the
 * second while preserving the first would look like it worked.
 *
 * No database: `setSetting($k, $v, false)` writes to the in-memory store only,
 * which is enough to exercise the encrypt/decrypt path. The database round trip
 * is the QueryBuilder's business and is covered by the Settings integration tests.
 */
#[CoversClass(Settings::class)]
class SettingsEncryptionTest extends TestCase
{
    private string|false $originalKey = false;

    protected function setUp(): void
    {
        $this->originalKey = getenv('APP_KEY');
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;

        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->originalKey);
            $_ENV['APP_KEY'] = $this->originalKey;
        }

        $this->resetSettings();
    }

    /** Empty the static store so each test starts from nothing. */
    private function resetSettings(): void
    {
        $ref = new \ReflectionClass(Settings::class);
        $ref->getProperty('settings')->setValue(null, []);
        $ref->getProperty('loaded')->setValue(null, false);
        $ref->getProperty('bulkLoaded')->setValue(null, false);
        $ref->getProperty('database')->setValue(null, null);
    }

    /** Put a value straight into the store, as a database read would. */
    private function seedStore(string $key, string $value): void
    {
        $ref   = new \ReflectionClass(Settings::class);
        $prop  = $ref->getProperty('settings');
        $store = $prop->getValue();
        $store[$key] = $value;
        $prop->setValue(null, $store);
    }

    /** The raw value in the store, before getSetting() touches it. */
    private function rawFromStore(string $key): mixed
    {
        return (new \ReflectionClass(Settings::class))
            ->getProperty('settings')
            ->getValue()[$key] ?? null;
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /**
     * An encrypted row reads back as the plaintext the caller stored.
     *
     * This is the whole point: `Email::send()` asks for `smtp_pass` and gets a
     * password, exactly as it did before the column was encrypted.
     */
    public function testEncryptedSettingIsDecryptedOnRead(): void
    {
        // Arrange — the store holds what the database would hold.
        $this->seedStore('smtp_pass', Encrypter::encrypt('the-real-password'));

        // Act + Assert
        $this->assertSame('the-real-password', Settings::getSetting('smtp_pass'));
    }

    /**
     * A row written before encryption existed reads back unchanged.
     *
     * The migration path: no conversion step, no window where mail breaks. An
     * installation upgrading mid-week keeps sending mail on the old value until
     * something rewrites it.
     */
    public function testPlaintextSettingStillReadsBack(): void
    {
        // Arrange — a legacy row, no marker.
        $this->seedStore('smtp_pass', 'password-from-before-the-change');

        // Act + Assert
        $this->assertSame('password-from-before-the-change', Settings::getSetting('smtp_pass'));
    }

    /**
     * A value that will not decrypt yields the default, not the ciphertext.
     *
     * After an APP_KEY rotation the stored password is gone either way. Handing
     * back `enc:v1:…` would send that string to the SMTP server as a password, and
     * the operator would be debugging a mail authentication failure instead of
     * reading "no password configured".
     */
    public function testUndecryptableValueFallsBackToTheDefault(): void
    {
        // Arrange — encrypted under a key that is then replaced.
        $this->seedStore('smtp_pass', Encrypter::encrypt('lost-to-rotation'));
        $rotated = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $rotated);
        $_ENV['APP_KEY'] = $rotated;

        // Act
        $value = Settings::getSetting('smtp_pass', 'the-default');

        // Assert
        $this->assertSame('the-default', $value);
    }

    /**
     * Settings not on the list are untouched, including one whose value happens to
     * look like a password.
     */
    public function testOrdinarySettingsAreNotDecrypted(): void
    {
        // Arrange
        $this->seedStore('sitename', 'My Application');

        // Act + Assert
        $this->assertSame('My Application', Settings::getSetting('sitename'));
    }

    /**
     * A non-string value reaches the caller as it is. Settings holds arrays and
     * booleans too, and the encryption branch must not coerce them.
     */
    public function testNonStringSettingsPassThrough(): void
    {
        // Arrange
        $this->seedStore('smtp_pass', '');
        $ref   = new \ReflectionClass(Settings::class);
        $prop  = $ref->getProperty('settings');
        $store = $prop->getValue();
        $store['smtp_port'] = 587;
        $prop->setValue(null, $store);

        // Act + Assert
        $this->assertSame(587, Settings::getSetting('smtp_port'));
        $this->assertSame('', Settings::getSetting('smtp_pass'));
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * Within the same request, a value just written reads back as itself.
     *
     * The in-memory store keeps the plaintext deliberately: if it kept the
     * ciphertext, the answer to `getSetting()` would depend on whether the value
     * had been written yet in this request, which is the kind of difference that
     * shows up once in production and never in a test.
     */
    public function testValueWrittenThisRequestReadsBackAsPlaintext(): void
    {
        // Act
        Settings::setSetting('smtp_pass', 'freshly-set', false);

        // Assert
        $this->assertSame('freshly-set', Settings::getSetting('smtp_pass'));
        $this->assertSame('freshly-set', $this->rawFromStore('smtp_pass'));
    }

    /**
     * Encryption stays off when APP_KEY is missing.
     *
     * An installation that never ran `key:generate` must still be able to save its
     * mail settings. Refusing the write would trade a readable credential for a
     * broken settings screen, and the row converts itself once a key exists.
     */
    public function testWriteWithoutAnAppKeyStoresPlaintext(): void
    {
        // Arrange
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);

        // Act
        Settings::setSetting('smtp_pass', 'no-key-here', false);

        // Assert — readable, and still the plaintext on the way back.
        $this->assertSame('no-key-here', Settings::getSetting('smtp_pass'));
    }

    /**
     * The encryption list is what decides, and it names `smtp_pass`.
     *
     * Asserted through the constant rather than through behaviour so that removing
     * a key from the list is a test failure and not a silent loss of protection.
     */
    public function testSmtpPasswordIsOnTheEncryptedList(): void
    {
        // Arrange
        $list = (new \ReflectionClass(Settings::class))
            ->getConstant('ENCRYPTED_SETTINGS');

        // Assert
        $this->assertIsArray($list);
        $this->assertContains('smtp_pass', $list);
    }
}
