<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Factors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Auth\Factors\EmailCodeSecondFactor;

/**
 * The adaptor itself carries no logic — it is a thin, fixed delegation to
 * {@see EmailSecondFactor}, which keeps the actual code store and limits. What is worth
 * pinning here is exactly that: every call goes to the service unchanged, the identity and
 * strength the registry sorts factors by are fixed values, and the service is built lazily
 * rather than at construction (every other factor the registry loads would otherwise pay for
 * one it never calls).
 */
#[CoversClass(EmailCodeSecondFactor::class)]
class EmailCodeSecondFactorTest extends TestCase
{
    private function fake(): EmailSecondFactor
    {
        return new class extends EmailSecondFactor {
            public ?int $isEnabledForCalledWith = null;

            public ?int $sendCalledWith = null;

            /** @var array{0: int, 1: string}|null */
            public ?array $verifyCalledWith = null;

            public ?int $hasLiveCodeCalledWith = null;

            public function __construct()
            {
            }

            public function isEnabledFor(int $userId): bool
            {
                $this->isEnabledForCalledWith = $userId;

                return true;
            }

            public function send(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
            {
                $this->sendCalledWith = $userId;

                return true;
            }

            public function verify(int $userId, string $code, string $purpose = self::PURPOSE_LOGIN): bool
            {
                $this->verifyCalledWith = [$userId, $code];

                return true;
            }

            public function hasLiveCode(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
            {
                $this->hasLiveCodeCalledWith = $userId;

                return true;
            }
        };
    }

    public function testNameIsTheEmailMethodConstant(): void
    {
        $this->assertSame(EmailSecondFactor::METHOD, (new EmailCodeSecondFactor($this->fake()))->name());
    }

    public function testLabelIsHumanReadable(): void
    {
        $this->assertSame('Code by email', (new EmailCodeSecondFactor($this->fake()))->label());
    }

    /** Weakest of the factors — a mailbox is the most reachable channel of the three. */
    public function testStrengthIsTwenty(): void
    {
        $this->assertSame(20, (new EmailCodeSecondFactor($this->fake()))->strength());
    }

    /** Unlike the authenticator/passkey factors, this one requires the account to send a code first. */
    public function testNeedsSendingIsTrue(): void
    {
        $this->assertTrue((new EmailCodeSecondFactor($this->fake()))->needsSending());
    }

    public function testIsEnrolledForDelegatesToIsEnabledFor(): void
    {
        // Arrange
        $service = $this->fake();

        // Act
        $result = (new EmailCodeSecondFactor($service))->isEnrolledFor(42);

        // Assert
        $this->assertTrue($result);
        $this->assertSame(42, $service->isEnabledForCalledWith);
    }

    public function testSendDelegatesToTheService(): void
    {
        // Arrange
        $service = $this->fake();

        // Act
        $result = (new EmailCodeSecondFactor($service))->send(42);

        // Assert
        $this->assertTrue($result);
        $this->assertSame(42, $service->sendCalledWith);
    }

    public function testVerifyDelegatesTheUserIdAndCode(): void
    {
        // Arrange
        $service = $this->fake();

        // Act
        $result = (new EmailCodeSecondFactor($service))->verify(42, '123456');

        // Assert
        $this->assertTrue($result);
        $this->assertSame([42, '123456'], $service->verifyCalledWith);
    }

    public function testHasLiveCodeDelegatesToTheService(): void
    {
        // Arrange
        $service = $this->fake();

        // Act
        $result = (new EmailCodeSecondFactor($service))->hasLiveCode(42);

        // Assert
        $this->assertTrue($result);
        $this->assertSame(42, $service->hasLiveCodeCalledWith);
    }

    /**
     * With no service given, one is built the first time it is actually needed — not at
     * construction, which every factor the registry loads would otherwise pay for.
     */
    public function testWithNoServiceGivenOneIsBuiltLazily(): void
    {
        // Arrange
        $factor   = new EmailCodeSecondFactor();
        $property = new \ReflectionProperty($factor, 'service');

        // Act / Assert — a value method never touches the service at all
        $this->assertSame(20, $factor->strength());
        $this->assertNull($property->getValue($factor));

        // The first delegating call builds it — whatever it answers, a real instance exists
        // afterwards, which is what every other test in this class relies on when a service
        // is given explicitly instead.
        try {
            $factor->isEnrolledFor(1);
        } catch (\Throwable) {
            // No code-store table in this suite's database — irrelevant to this assertion.
        }
        $this->assertInstanceOf(EmailSecondFactor::class, $property->getValue($factor));
    }
}
