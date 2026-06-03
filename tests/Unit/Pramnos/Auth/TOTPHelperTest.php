<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\TOTPHelper;

#[CoversClass(TOTPHelper::class)]
class TOTPHelperTest extends TestCase
{
    public function testSecretGenerationAndValidation(): void
    {
        $secret = TOTPHelper::generateSecret();
        $this->assertNotEmpty($secret);
        $this->assertTrue(TOTPHelper::isValidSecret($secret));

        // Invalid cases
        $this->assertFalse(TOTPHelper::isValidSecret(''));
        $this->assertFalse(TOTPHelper::isValidSecret('INVALID!CHAR'));
    }

    public function testCodeGenerationAndVerification(): void
    {
        $secret = TOTPHelper::generateSecret();
        $time = time();

        $code = TOTPHelper::generateCode($secret, $time);
        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $code);

        // Verification
        $this->assertTrue(TOTPHelper::verifyCode($secret, $code, $time));
        $this->assertFalse(TOTPHelper::verifyCode($secret, '999999', $time));

        // Verification with clock skew
        $pastCode = TOTPHelper::generateCode($secret, $time - 30);
        $this->assertTrue(TOTPHelper::verifyCode($secret, $pastCode, $time));
    }

    public function testRemainingTime(): void
    {
        $remaining = TOTPHelper::getRemainingTime();
        $this->assertGreaterThanOrEqual(1, $remaining);
        $this->assertLessThanOrEqual(30, $remaining);
    }

    public function testBuildOtpAuthUri(): void
    {
        $secret = 'MYSUPERSECRET';
        $uri = TOTPHelper::buildOtpAuthUri($secret, 'user@example.com', 'MyIssuer');

        $this->assertStringContainsString('otpauth://totp/MyIssuer:user%40example.com', $uri);
        $this->assertStringContainsString('secret=MYSUPERSECRET', $uri);
        $this->assertStringContainsString('issuer=MyIssuer', $uri);
    }

    public function testGetQRCodeUrl(): void
    {
        $secret = 'SECRET123';
        $url = TOTPHelper::getQRCodeUrl($secret, 'user@example.com', 'App');

        $this->assertStringContainsString('https://api.qrserver.com/v1/create-qr-code/', $url);
        $this->assertStringContainsString(urlencode('otpauth://totp/App:user%40example.com?secret=SECRET123&issuer=App&algorithm=SHA1&digits=6&period=30'), $url);
    }

    public function testGetQRCodeDataUriReturnsDataUri(): void
    {
        $uri = TOTPHelper::getQRCodeDataUri('SECRET', 'user');
        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
    }

    public function testBackupCodes(): void
    {
        $codes = TOTPHelper::generateBackupCodes(5);
        $this->assertCount(5, $codes);

        foreach ($codes as $code) {
            $this->assertSame(8, strlen($code));
            
            $hash = TOTPHelper::hashBackupCode($code);
            $this->assertTrue(TOTPHelper::verifyBackupCode($code, $hash));
            $this->assertFalse(TOTPHelper::verifyBackupCode('WRONG', $hash));
        }
    }
}
