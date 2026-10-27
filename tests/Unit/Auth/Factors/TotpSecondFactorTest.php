<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Factors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Factors\TotpSecondFactor;
use Pramnos\Auth\TwoFactorAuthService;

/**
 * The adaptor itself carries no logic — it is a thin, fixed delegation to
 * {@see TwoFactorAuthService}, which keeps the enrolment flow, the backup codes and the
 * replay guard. What is worth pinning here is exactly that: every call goes to the service
 * unchanged, the identity and strength the registry sorts factors by are fixed values, a
 * code is trimmed before verification, and the service is built lazily rather than at
 * construction.
 */
#[CoversClass(TotpSecondFactor::class)]
class TotpSecondFactorTest extends TestCase
{
    private function fake(): TwoFactorAuthService
    {
        return new class extends TwoFactorAuthService {
            public ?int $isEnabledCalledWith = null;

            /** @var array{0: int, 1: string}|null */
            public ?array $verifyCalledWith = null;

            public function __construct()
            {
            }

            public function isEnabled(int $userId): bool
            {
                $this->isEnabledCalledWith = $userId;

                return true;
            }

            public function verifyCode(int $userId, string $code): bool
            {
                $this->verifyCalledWith = [$userId, $code];

                return true;
            }
        };
    }

    public function testNameIsTotp(): void
    {
        $this->assertSame('totp', (new TotpSecondFactor($this->fake()))->name());
    }

    public function testLabelIsHumanReadable(): void
    {
        $this->assertSame('Authenticator app', (new TotpSecondFactor($this->fake()))->label());
    }

    /** Strongest of the factors — the secret never leaves the person's device. */
    public function testStrengthIsSixty(): void
    {
        $this->assertSame(60, (new TotpSecondFactor($this->fake()))->strength());
    }

    /** Unlike the email/push factors, nothing is sent: the code is already on the phone. */
    public function testNeedsSendingIsFalse(): void
    {
        $this->assertFalse((new TotpSecondFactor($this->fake()))->needsSending());
    }

    /** `send()` has nothing to do and answers false, not an error. */
    public function testSendAnswersFalse(): void
    {
        $this->assertFalse((new TotpSecondFactor($this->fake()))->send(42));
    }

    public function testIsEnrolledForDelegatesToIsEnabled(): void
    {
        // Arrange
        $service = $this->fake();

        // Act
        $result = (new TotpSecondFactor($service))->isEnrolledFor(42);

        // Assert
        $this->assertTrue($result);
        $this->assertSame(42, $service->isEnabledCalledWith);
    }

    /** A code with surrounding whitespace — pasted from an authenticator app — still verifies. */
    public function testVerifyTrimsTheCodeBeforeDelegating(): void
    {
        // Arrange
        $service = $this->fake();

        // Act
        $result = (new TotpSecondFactor($service))->verify(42, '  123456  ');

        // Assert
        $this->assertTrue($result);
        $this->assertSame([42, '123456'], $service->verifyCalledWith);
    }

    /**
     * With no service given, one is built the first time it is actually needed — not at
     * construction, which every factor the registry loads would otherwise pay for.
     */
    public function testWithNoServiceGivenOneIsBuiltLazily(): void
    {
        // Arrange
        $factor   = new TotpSecondFactor();
        $property = new \ReflectionProperty($factor, 'service');

        // Act / Assert — a value method never touches the service at all
        $this->assertSame(60, $factor->strength());
        $this->assertNull($property->getValue($factor));

        // The first delegating call builds it — whatever it answers, a real instance exists
        // afterwards, which is what every other test in this class relies on when a service
        // is given explicitly instead.
        try {
            $factor->isEnrolledFor(1);
        } catch (\Throwable) {
            // No table for this feature in this suite's database — irrelevant to this assertion.
        }
        $this->assertInstanceOf(TwoFactorAuthService::class, $property->getValue($factor));
    }
}
