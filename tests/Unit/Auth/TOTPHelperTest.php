<?php

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\TOTPHelper;

/**
 * Unit tests for Pramnos\Auth\TOTPHelper.
 *
 * TOTPHelper is a pure computation class with no database access. These tests
 * verify the RFC 6238 TOTP algorithm implementation without requiring Docker.
 *
 * Coverage:
 * - generateSecret() produces a non-empty valid base32 string
 * - generateCode() produces a 6-digit zero-padded string
 * - verifyCode() accepts the current code (no drift) and adjacent windows (drift=1)
 * - verifyCode() rejects codes from windows beyond the drift tolerance
 * - verifyCode() rejects codes for the correct window but wrong secret
 * - verifyCode() and generateCode() are consistent across repeated calls for same time
 * - generateBackupCodes() returns the requested count; all codes are 8 chars
 * - hashBackupCode() + verifyBackupCode() round-trip (case-insensitive)
 * - verifyBackupCode() rejects a wrong code against a hash
 * - isValidSecret() accepts valid base32 and rejects non-base32 strings
 * - getRemainingTime() returns a value in [1, 30]
 */
class TOTPHelperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // generateSecret()
    // -------------------------------------------------------------------------

    /**
     * generateSecret() must return a non-empty string of valid base32 characters.
     *
     * The secret is the shared key between server and authenticator app — it must
     * use only the base32 alphabet (A-Z + 2-7) so the user can enter it manually
     * if QR scanning fails.
     */
    public function testGenerateSecretReturnsValidBase32(): void
    {
        // Act
        $secret = TOTPHelper::generateSecret();

        // Assert
        $this->assertNotEmpty($secret, 'generated secret must not be empty');
        $this->assertMatchesRegularExpression(
            '/^[A-Z2-7]+$/i',
            $secret,
            'generated secret must contain only base32 characters (A-Z, 2-7)'
        );
    }

    /**
     * Two successive calls to generateSecret() must not produce the same string.
     *
     * This is a probabilistic test — collision is astronomically unlikely given
     * a 20-byte random input. If it fails spuriously, that indicates a broken
     * PRNG rather than a real bug.
     */
    public function testGenerateSecretProducesDifferentValuesEachCall(): void
    {
        // Act
        $s1 = TOTPHelper::generateSecret();
        $s2 = TOTPHelper::generateSecret();

        // Assert
        $this->assertNotSame($s1, $s2, 'successive calls must not return the same secret');
    }

    // -------------------------------------------------------------------------
    // generateCode()
    // -------------------------------------------------------------------------

    /**
     * generateCode() must return a string of exactly CODE_LENGTH digits.
     *
     * The code may start with '0' (e.g., '007891') — it must be zero-padded
     * to 6 characters and must consist only of digits.
     */
    public function testGenerateCodeProduces6DigitString(): void
    {
        // Arrange
        $secret = TOTPHelper::generateSecret();

        // Act
        $code = TOTPHelper::generateCode($secret, time());

        // Assert
        $this->assertSame(6, strlen($code), 'TOTP code must be exactly 6 characters');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'TOTP code must be all digits');
    }

    /**
     * generateCode() must produce the same code for the same (secret, time) pair.
     *
     * TOTP is deterministic: given the same inputs, the output must always be
     * identical. This is the property that allows the server to verify the code.
     */
    public function testGenerateCodeIsDeterministic(): void
    {
        // Arrange
        $secret = TOTPHelper::generateSecret();
        $time   = time();

        // Act
        $code1 = TOTPHelper::generateCode($secret, $time);
        $code2 = TOTPHelper::generateCode($secret, $time);

        // Assert
        $this->assertSame($code1, $code2, 'generateCode() must return the same code for the same inputs');
    }

    // -------------------------------------------------------------------------
    // verifyCode()
    // -------------------------------------------------------------------------

    /**
     * verifyCode() must accept the current code generated for the same secret.
     *
     * This is the core TOTP contract: a code generated now must be accepted
     * when verified immediately at the same reference time.
     */
    public function testVerifyCodeAcceptsCurrentCode(): void
    {
        // Arrange
        $secret = TOTPHelper::generateSecret();
        $time   = time();

        // Act
        $code   = TOTPHelper::generateCode($secret, $time);
        $result = TOTPHelper::verifyCode($secret, $code, $time);

        // Assert
        $this->assertTrue($result, 'verifyCode() must accept the code generated for the same (secret, time)');
    }

    /**
     * verifyCode() must accept codes from adjacent time windows (drift tolerance).
     *
     * Clients and servers may have up to ±30 seconds of clock skew. The default
     * drift window of ±1 window (±30 s) accommodates this without requiring
     * NTP synchronisation.
     */
    public function testVerifyCodeAcceptsPreviousWindow(): void
    {
        // Arrange
        $secret    = TOTPHelper::generateSecret();
        $time      = time();
        $prevTime  = $time - 30; // one window back

        // Act — generate for previous window, verify against current time
        $code   = TOTPHelper::generateCode($secret, $prevTime);
        $result = TOTPHelper::verifyCode($secret, $code, $time, 1);

        // Assert
        $this->assertTrue($result, 'verifyCode() must accept codes from adjacent windows within drift tolerance');
    }

    /**
     * verifyCode() must reject codes from windows beyond the drift tolerance.
     *
     * A code from 5 minutes ago must not be accepted — this prevents replay
     * attacks using codes captured from previous sessions.
     */
    public function testVerifyCodeRejectsOldCode(): void
    {
        // Arrange
        $secret  = TOTPHelper::generateSecret();
        $oldTime = time() - 300; // 10 windows back (well beyond drift=1)

        // Act
        $code   = TOTPHelper::generateCode($secret, $oldTime);
        $result = TOTPHelper::verifyCode($secret, $code, time(), 1);

        // Assert
        $this->assertFalse($result, 'verifyCode() must reject codes older than the drift window');
    }

    /**
     * verifyCode() must reject a code generated with a different secret.
     *
     * Each user has a unique secret. A code from user A must never validate
     * against user B's secret.
     */
    public function testVerifyCodeRejectsMismatchedSecret(): void
    {
        // Arrange
        $secretA = TOTPHelper::generateSecret();
        $secretB = TOTPHelper::generateSecret();
        $time    = time();

        // Act — generate for secret A, verify with secret B
        $code   = TOTPHelper::generateCode($secretA, $time);
        $result = TOTPHelper::verifyCode($secretB, $code, $time);

        // Assert
        $this->assertFalse($result, 'verifyCode() must reject codes generated with a different secret');
    }

    // -------------------------------------------------------------------------
    // Backup codes
    // -------------------------------------------------------------------------

    /**
     * generateBackupCodes() must return the requested count of 8-character codes.
     *
     * Each code must be exactly 8 characters from the unambiguous alphanumeric
     * alphabet (no 0, 1, O, I). The count must match the requested value.
     */
    public function testGenerateBackupCodesReturnsRequestedCount(): void
    {
        // Act
        $codes = TOTPHelper::generateBackupCodes(10);

        // Assert
        $this->assertCount(10, $codes, 'generateBackupCodes() must return exactly the requested count');
        foreach ($codes as $code) {
            $this->assertSame(8, strlen($code), "each backup code must be 8 characters, got: {$code}");
            $this->assertMatchesRegularExpression(
                '/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{8}$/',
                $code,
                'backup code must use only unambiguous characters'
            );
        }
    }

    /**
     * hashBackupCode() + verifyBackupCode() must produce a valid round-trip.
     *
     * The hash is stored in the database. When the user submits a backup code,
     * verifyBackupCode() must confirm the match using password_verify().
     */
    public function testBackupCodeHashAndVerifyRoundTrip(): void
    {
        // Arrange
        $code = 'ABCD2345'; // representative backup code

        // Act
        $hash   = TOTPHelper::hashBackupCode($code);
        $result = TOTPHelper::verifyBackupCode($code, $hash);

        // Assert
        $this->assertTrue($result, 'verifyBackupCode() must confirm a code against its own hash');
    }

    /**
     * verifyBackupCode() must be case-insensitive — 'abcd2345' must match 'ABCD2345'.
     *
     * Backup codes are often entered by hand and users may not respect case.
     * Both generate and verify normalise to uppercase internally.
     */
    public function testBackupCodeVerificationIsCaseInsensitive(): void
    {
        // Arrange
        $plainUpper = 'ABCD2345';
        $plainLower = 'abcd2345';
        $hash       = TOTPHelper::hashBackupCode($plainUpper);

        // Act
        $result = TOTPHelper::verifyBackupCode($plainLower, $hash);

        // Assert
        $this->assertTrue($result, 'verifyBackupCode() must accept lowercase input for an uppercase hash');
    }

    /**
     * verifyBackupCode() must reject a wrong code against a stored hash.
     *
     * A different code must not hash to the same value — this is guaranteed
     * by bcrypt but worth asserting explicitly.
     */
    public function testBackupCodeVerificationRejectsWrongCode(): void
    {
        // Arrange
        $correct = 'ABCD2345';
        $wrong   = 'WXYZ6789';
        $hash    = TOTPHelper::hashBackupCode($correct);

        // Act
        $result = TOTPHelper::verifyBackupCode($wrong, $hash);

        // Assert
        $this->assertFalse($result, 'verifyBackupCode() must reject a code that does not match the hash');
    }

    // -------------------------------------------------------------------------
    // isValidSecret()
    // -------------------------------------------------------------------------

    /**
     * isValidSecret() must accept valid base32 strings.
     *
     * A secret generated by generateSecret() must always be considered valid.
     */
    public function testIsValidSecretAcceptsBase32String(): void
    {
        // Arrange
        $secret = TOTPHelper::generateSecret();

        // Assert
        $this->assertTrue(TOTPHelper::isValidSecret($secret), 'generated secrets must be valid');
    }

    /**
     * isValidSecret() must reject strings containing non-base32 characters.
     *
     * Characters outside A-Z and 2-7 (such as 0, 1, 8, 9, or punctuation)
     * are invalid in a base32-encoded secret.
     */
    public function testIsValidSecretRejectsInvalidCharacters(): void
    {
        $this->assertFalse(TOTPHelper::isValidSecret(''),       'empty string must be invalid');
        $this->assertFalse(TOTPHelper::isValidSecret('ABCD01'), 'digits 0 and 1 are not valid base32');
        $this->assertFalse(TOTPHelper::isValidSecret('ABCD89'), 'digits 8 and 9 are not valid base32');
        $this->assertFalse(TOTPHelper::isValidSecret('ABC!'),   'punctuation must be invalid');
    }

    // -------------------------------------------------------------------------
    // getRemainingTime()
    // -------------------------------------------------------------------------

    /**
     * getRemainingTime() must return a value in [1, 30].
     *
     * The function returns seconds until the current 30-second window expires.
     * It must never be 0 (the window always has at least one second remaining
     * at the point of measurement).
     */
    public function testGetRemainingTimeIsWithinBounds(): void
    {
        // Act
        $remaining = TOTPHelper::getRemainingTime();

        // Assert
        $this->assertGreaterThanOrEqual(1, $remaining, 'remaining time must be at least 1 second');
        $this->assertLessThanOrEqual(30, $remaining, 'remaining time must not exceed one TOTP window');
    }
}
