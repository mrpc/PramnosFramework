<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\Encrypter;

/**
 * Tests for {@see Encrypter} — authenticated encryption of stored credentials.
 *
 * The properties that matter here are the ones a caller relies on without
 * checking: that a round trip returns exactly what went in, that two encryptions
 * of the same value do not look alike, that a tampered value is refused rather
 * than decrypted to something plausible, and that a column still holding
 * plaintext reads back unchanged so a table can convert itself as it is written.
 *
 * APP_KEY is set per test and restored afterwards; the suite must not depend on
 * what the surrounding environment happens to have.
 */
#[CoversClass(Encrypter::class)]
class EncrypterTest extends TestCase
{
    /** APP_KEY as it was before this test, restored in tearDown. */
    private string|false $originalKey = false;

    /** Whether $_ENV carried an APP_KEY before this test. */
    private bool $hadEnvKey = false;

    /** Its value, when it did. */
    private ?string $originalEnvKey = null;

    protected function setUp(): void
    {
        $this->originalKey    = getenv('APP_KEY');
        $this->hadEnvKey      = array_key_exists('APP_KEY', $_ENV);
        $this->originalEnvKey = $this->hadEnvKey ? (string) $_ENV['APP_KEY'] : null;

        $this->useKey('base64:' . base64_encode(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === false) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY=' . $this->originalKey);
        }

        if ($this->hadEnvKey) {
            $_ENV['APP_KEY'] = $this->originalEnvKey;
        } else {
            unset($_ENV['APP_KEY']);
        }
    }

    /** Point the encrypter at a specific APP_KEY for the rest of the test. */
    private function useKey(string $key): void
    {
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;
    }

    /** Remove APP_KEY entirely, as an installation that never ran key:generate. */
    private function clearKey(): void
    {
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);
    }

    // ── Round trip ────────────────────────────────────────────────────────────

    /**
     * The contract in one line: what comes out of decrypt() is what went into
     * encrypt(), byte for byte.
     */
    public function testRoundTripReturnsTheOriginalValue(): void
    {
        // Arrange
        $plaintext = 'hunter2-the-smtp-password';

        // Act
        $decrypted = Encrypter::decrypt(Encrypter::encrypt($plaintext));

        // Assert
        $this->assertSame($plaintext, $decrypted);
    }

    /**
     * Credentials are not ASCII by policy — a passphrase may hold UTF-8, and a TOTP
     * seed handled as raw bytes may hold NUL. Both must survive the round trip, so
     * the class is byte-transparent rather than string-shaped.
     */
    public function testRoundTripSurvivesUnicodeAndBinary(): void
    {
        // Arrange
        $cases = [
            'unicode'   => 'κωδικός-πρόσβασης-ñ-日本語',
            'binary'    => "\x00\x01\x02\xff\xfe binary \x00 tail",
            'newlines'  => "line one\nline two\r\n",
            'long'      => str_repeat('x', 4096),
        ];

        // Act + Assert
        foreach ($cases as $label => $plaintext) {
            $this->assertSame(
                $plaintext,
                Encrypter::decrypt(Encrypter::encrypt($plaintext)),
                "Round trip failed for: {$label}"
            );
        }
    }

    /**
     * The empty string passes through untouched.
     *
     * An empty column means "nothing configured here". Encrypting it would turn the
     * absence of a credential into a value, and every caller checking `!== ''`
     * would start seeing one.
     */
    public function testEmptyStringIsNotEncrypted(): void
    {
        // Act + Assert
        $this->assertSame('', Encrypter::encrypt(''));
        $this->assertFalse(Encrypter::isEncrypted(''));
    }

    /**
     * Encrypting the same value twice must not produce the same ciphertext.
     *
     * The nonce is fresh per call, so equal plaintexts are indistinguishable in the
     * column. Without this, anyone reading the table learns which accounts share a
     * password without decrypting anything.
     */
    public function testSamePlaintextEncryptsDifferentlyEachTime(): void
    {
        // Arrange
        $plaintext = 'the-same-secret';

        // Act
        $first  = Encrypter::encrypt($plaintext);
        $second = Encrypter::encrypt($plaintext);

        // Assert
        $this->assertNotSame($first, $second, 'Ciphertexts repeated — nonce is not fresh.');
        $this->assertSame($plaintext, Encrypter::decrypt($first));
        $this->assertSame($plaintext, Encrypter::decrypt($second));
    }

    /**
     * The plaintext must not be recoverable by eye from the stored form — a
     * regression guard against an "encrypt" that ever became an encode.
     */
    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        // Arrange
        $plaintext = 'recognisable-secret-value';

        // Act
        $stored = Encrypter::encrypt($plaintext);

        // Assert
        $this->assertStringNotContainsString($plaintext, $stored);
        $this->assertStringNotContainsString($plaintext, base64_decode(
            substr($stored, strlen(Encrypter::PREFIX)),
            true
        ) ?: '');
    }

    // ── The marker ────────────────────────────────────────────────────────────

    /**
     * Encrypted values carry the version marker, which is what every other
     * behaviour keys off.
     */
    public function testEncryptedValuesCarryTheMarker(): void
    {
        // Act
        $stored = Encrypter::encrypt('anything');

        // Assert
        $this->assertStringStartsWith(Encrypter::PREFIX, $stored);
        $this->assertTrue(Encrypter::isEncrypted($stored));
    }

    /**
     * Plaintext is not mistaken for ciphertext — including a plaintext that is
     * itself valid base64, which any shape-guessing heuristic would misread.
     */
    public function testPlaintextIsNotReportedAsEncrypted(): void
    {
        // Act + Assert
        $this->assertFalse(Encrypter::isEncrypted('plain-password'));
        $this->assertFalse(Encrypter::isEncrypted(base64_encode('looks encoded')));
        $this->assertFalse(Encrypter::isEncrypted('enc:v0:something'));
    }

    // ── maybeDecrypt(): the migration path ────────────────────────────────────

    /**
     * The whole adoption strategy: a column still holding plaintext reads back
     * unchanged, so it can be read through maybeDecrypt() from the first deploy and
     * convert itself as rows are rewritten.
     */
    public function testMaybeDecryptPassesPlaintextThrough(): void
    {
        // Arrange
        $legacy = 'password-stored-before-encryption-existed';

        // Act + Assert
        $this->assertSame($legacy, Encrypter::maybeDecrypt($legacy));
    }

    /** And the other half: an encrypted value is decrypted. */
    public function testMaybeDecryptDecryptsEncryptedValues(): void
    {
        // Arrange
        $stored = Encrypter::encrypt('new-password');

        // Act + Assert
        $this->assertSame('new-password', Encrypter::maybeDecrypt($stored));
    }

    /**
     * decrypt() refuses plaintext instead of returning it.
     *
     * The split between the two methods is the point: a caller that knows the value
     * must be encrypted gets an error when it is not, rather than a secret that
     * looks like a successful decryption.
     */
    public function testDecryptRefusesPlaintext(): void
    {
        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not encrypted/');

        // Act
        Encrypter::decrypt('just-a-password');
    }

    // ── Authentication failures ───────────────────────────────────────────────

    /**
     * A tampered ciphertext is refused, not decrypted.
     *
     * This is the difference between authenticated encryption and a raw stream
     * cipher: someone with write access to the column cannot flip bits in a stored
     * credential and have the application use the result.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        // Arrange — flip the last byte of the payload.
        $stored  = Encrypter::encrypt('the-original-secret');
        $raw     = base64_decode(substr($stored, strlen(Encrypter::PREFIX)), true);
        $raw[strlen($raw) - 1] = ($raw[strlen($raw) - 1] === "\x00") ? "\x01" : "\x00";
        $tampered = Encrypter::PREFIX . base64_encode($raw);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');

        // Act
        Encrypter::decrypt($tampered);
    }

    /**
     * A value encrypted under one APP_KEY does not open under another.
     *
     * Which is also the warning about rotation: the old values become unreadable,
     * loudly rather than silently.
     */
    public function testValueEncryptedWithAnotherKeyIsRejected(): void
    {
        // Arrange
        $stored = Encrypter::encrypt('secret-under-the-first-key');
        $this->useKey('base64:' . base64_encode(random_bytes(32)));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');

        // Act
        Encrypter::decrypt($stored);
    }

    /**
     * A truncated column — the classic result of a VARCHAR too short for the
     * ciphertext — is reported as malformed rather than reaching libsodium.
     */
    public function testTruncatedValueIsRejected(): void
    {
        // Arrange — keep the marker, cut the payload below one nonce.
        $truncated = Encrypter::PREFIX . base64_encode(random_bytes(8));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed or truncated/');

        // Act
        Encrypter::decrypt($truncated);
    }

    /** A marker followed by something that is not base64 at all. */
    public function testNonBase64PayloadIsRejected(): void
    {
        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed or truncated/');

        // Act
        Encrypter::decrypt(Encrypter::PREFIX . 'not!valid!base64!!!');
    }

    // ── APP_KEY handling ──────────────────────────────────────────────────────

    /**
     * With no APP_KEY the class refuses to encrypt, and says what to run.
     *
     * Failing here is correct: the alternative is writing a credential to the
     * database in the clear while the caller believes it was protected.
     */
    public function testEncryptFailsWithoutAnAppKey(): void
    {
        // Arrange
        $this->clearKey();

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/key:generate/');

        // Act
        Encrypter::encrypt('secret');
    }

    /** isAvailable() answers the same question without throwing. */
    public function testIsAvailableReflectsWhetherAKeyIsConfigured(): void
    {
        // Assert — setUp configured one.
        $this->assertTrue(Encrypter::isAvailable());

        // Act
        $this->clearKey();

        // Assert
        $this->assertFalse(Encrypter::isAvailable());
    }

    /**
     * A key that is not in `base64:` form still works: it is hashed to the required
     * length rather than refused.
     *
     * An installation whose APP_KEY was set by hand, or carried over from before
     * `key:generate` existed, must not find its credentials unreadable — and the
     * derivation has to be stable, or every request would compute a different key.
     */
    public function testPlainAppKeyIsHashedToAUsableKey(): void
    {
        // Arrange
        $this->useKey('a-hand-written-passphrase');

        // Act
        $stored = Encrypter::encrypt('secret');

        // Assert — same key, same derivation, so it opens again.
        $this->assertSame('secret', Encrypter::decrypt($stored));
    }

    /**
     * A `base64:` key of the wrong length falls through to the hash branch instead
     * of producing a libsodium length error.
     */
    public function testBase64KeyOfWrongLengthStillWorks(): void
    {
        // Arrange — 16 bytes, not the 32 secretbox wants.
        $this->useKey('base64:' . base64_encode(random_bytes(16)));

        // Act + Assert
        $this->assertSame('secret', Encrypter::decrypt(Encrypter::encrypt('secret')));
    }

    /**
     * APP_KEY set only in `$_ENV` — the shape a `.env` loader leaves behind when it
     * populates the superglobal but not the process environment — is found.
     */
    public function testAppKeyIsReadFromEnvSuperglobalWhenPutenvIsUnset(): void
    {
        // Arrange
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY');
        $_ENV['APP_KEY'] = $key;

        // Act + Assert
        $this->assertTrue(Encrypter::isAvailable());
        $this->assertSame('secret', Encrypter::decrypt(Encrypter::encrypt('secret')));
    }
}
