<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Auth\OAuth2\UserClaims;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The `email`, `phone` and `user` scopes, and the no-such-account answer —
 * {@see \Pramnos\Tests\Integration\User\ProfilePhotoTest} already covers `profile` in full,
 * as part of its own picture-claim assertions.
 */
#[CoversClass(UserClaims::class)]
class UserClaimsTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();

        $user = new User();
        $user->username  = 'claims_' . bin2hex(random_bytes(4));
        $user->email     = $user->username . '@example.com';
        $user->validated = 3;
        $user->save();
        $this->uid = (int) $user->userid;
    }

    protected function tearDown(): void
    {
        try {
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->uid)->delete();
        } catch (\Throwable $exception) {
            // Nothing to undo.
        }
        parent::tearDown();
    }

    /** No scope at all still names whose claims these would have been. */
    public function testWithNoScopesOnlySubIsPresent(): void
    {
        // Act
        $claims = UserClaims::for($this->uid, []);

        // Assert
        $this->assertSame(['sub' => (string) $this->uid], $claims);
    }

    /** An account that does not exist, or is not active, answers with only `sub`. */
    public function testANonExistentAccountAnswersWithOnlySub(): void
    {
        // Act
        $claims = UserClaims::for(999999999, ['email', 'profile', 'phone', 'user']);

        // Assert
        $this->assertSame(['sub' => '999999999'], $claims);
    }

    /** `email` gives the address and whether it is confirmed. */
    public function testEmailScopeGivesTheAddressAndVerifiedFlag(): void
    {
        // Act
        $claims = UserClaims::for($this->uid, ['email']);

        // Assert
        $this->assertStringContainsString('@example.com', $claims['email']);
        $this->assertTrue($claims['email_verified'], 'validated = 3 (both email and mobile) must read as verified');
    }

    /** An unconfirmed address reads as not verified. */
    public function testAnUnconfirmedEmailIsNotVerified(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['validated' => 0]);

        // Act
        $claims = UserClaims::for($this->uid, ['email']);

        // Assert
        $this->assertFalse($claims['email_verified']);
    }

    /** `phone` prefers the mobile number, and falls back to the landline when there is none. */
    public function testPhoneScopePrefersMobileOverLandline(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['mobile' => '+1 555 0100', 'phone' => '+1 555 0101']);

        // Act
        $claims = UserClaims::for($this->uid, ['phone']);

        // Assert
        $this->assertSame('+1 555 0100', $claims['phone_number']);
    }

    /** With no mobile, the landline is used instead. */
    public function testPhoneScopeFallsBackToLandlineWithNoMobile(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['mobile' => '', 'phone' => '+1 555 0101']);

        // Act
        $claims = UserClaims::for($this->uid, ['phone']);

        // Assert
        $this->assertSame('+1 555 0101', $claims['phone_number']);
    }

    /** With neither number, the claim is null — not an empty string. */
    public function testPhoneScopeIsNullWithNeitherNumber(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['mobile' => '', 'phone' => '']);

        // Act
        $claims = UserClaims::for($this->uid, ['phone']);

        // Assert
        $this->assertNull($claims['phone_number']);
    }

    /** `user` gives the framework's own group and registration date. */
    public function testUserScopeGivesMaingroupAndRegdate(): void
    {
        // Act
        $claims = UserClaims::for($this->uid, ['user']);

        // Assert
        $this->assertArrayHasKey('maingroup', $claims);
        $this->assertArrayHasKey('regdate', $claims);
    }
}
