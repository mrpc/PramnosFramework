<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\FactorEnrolment;
use Pramnos\Auth\SecondFactorInterface;
use Pramnos\Auth\SecondFactorRegistry;
use Pramnos\Tests\Support\FakePasskeyService;

/**
 * Which privileged accounts still have to enrol a real second factor.
 *
 * The distinction this class exists for: `require_second_factor_from_usertype` is satisfied
 * by a mailed code, because enrolment happens after signing in and refusing the mail to an
 * account with nothing would be a lockout by design. So an installation can have that switch
 * on and every administrator still holding nothing but a mailbox — which is one mailbox
 * compromise from holding nothing at all, on the accounts worth the most.
 *
 * Hence the assertion that matters most below: an account whose only factor is the mailed
 * code is **still required to enrol**. Everything else here is the boundary around that.
 */
#[CoversClass(FactorEnrolment::class)]
class FactorEnrolmentTest extends TestCase
{
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        SecondFactorRegistry::reset();

        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    /**
     * An application whose `auth.security` says this.
     */
    private function withFloor(mixed $floor): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        // `twofactor_methods` as well, and not as decoration: `SecondFactorRegistry::all()`
        // filters by it whenever an application exists, and the default is `['totp']`. A
        // stub that declared only the floor made the registry answer "nothing is allowed
        // here" — which every "must enrol" assertion below would have passed for the wrong
        // reason, since an empty registry and an unenrolled account look identical.
        $stub->applicationInfo = [
            'auth' => [
                'security'          => ['require_factor_enrolment_from_usertype' => $floor],
                'twofactor_methods' => ['totp', 'email', 'twofactor', 'sms'],
            ],
        ];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * A factor double of a given strength, enrolled or not.
     */
    private function factor(string $name, int $strength, bool $enrolled): SecondFactorInterface
    {
        return new class ($name, $strength, $enrolled) implements SecondFactorInterface {
            public function __construct(
                private string $factorName,
                private int $factorStrength,
                private bool $enrolled
            ) {
            }

            public function name(): string
            {
                return $this->factorName;
            }

            public function label(): string
            {
                return $this->factorName;
            }

            public function strength(): int
            {
                return $this->factorStrength;
            }

            public function isEnrolledFor(int $userId): bool
            {
                return $this->enrolled;
            }

            public function needsSending(): bool
            {
                return false;
            }

            public function send(int $userId): bool
            {
                return true;
            }

            public function verify(int $userId, string $code): bool
            {
                return false;
            }
        };
    }

    /**
     * A passkey service that answers a fixed yes or no.
     */
    private function passkeys(bool $has): FakePasskeyService
    {
        return new FakePasskeyService($has);
    }

    /**
     * With no floor configured, nobody is asked for anything.
     *
     * The default on every installation that has not opted in, and the one that must be
     * true for the middleware to be safe to register unconditionally.
     */
    public function testWithNoFloorNobodyHasToEnrol(): void
    {
        // Arrange
        $this->withFloor(0);
        SecondFactorRegistry::reset();

        // Act & Assert
        $this->assertFalse(
            (new FactorEnrolment($this->passkeys(false)))->isRequiredFor(7, 99)
        );
    }

    /**
     * An account below the floor is not asked either.
     */
    public function testAnAccountBelowTheFloorIsNotAsked(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();

        // Act & Assert
        $this->assertFalse(
            (new FactorEnrolment($this->passkeys(false)))->isRequiredFor(7, 50)
        );
    }

    /**
     * At or above the floor with nothing enrolled, it is required.
     */
    public function testAtTheFloorWithNothingEnrolledItIsRequired(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();

        // Act & Assert — the floor is a threshold, so 80 and 90 both qualify
        $enrolment = new FactorEnrolment($this->passkeys(false));
        $this->assertTrue($enrolment->isRequiredFor(7, 80));
        $this->assertTrue($enrolment->isRequiredFor(7, 90));
    }

    /**
     * A mailed code does not satisfy it. This is the whole point of the class.
     *
     * The sign-in requirement accepts one — it has to, or an account with nothing set up
     * could never sign in to set anything up. This requirement does not, because a code
     * sent to a mailbox is not a second factor against anybody who has the mailbox, and
     * the password reset arrives at the same address.
     */
    public function testAMailedCodeDoesNotSatisfyIt(): void
    {
        // Arrange — enrolled, and deliberately weak
        $this->withFloor(80);
        SecondFactorRegistry::reset();
        SecondFactorRegistry::register($this->factor('email', 20, true));

        // Act & Assert
        $this->assertTrue(
            (new FactorEnrolment($this->passkeys(false)))->isRequiredFor(7, 90),
            'a mailed code is the on-ramp, not the destination'
        );
    }

    /**
     * An authenticator does.
     */
    public function testAnAuthenticatorSatisfiesIt(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();
        SecondFactorRegistry::register($this->factor('twofactor', 60, true));

        // Act & Assert
        $this->assertFalse(
            (new FactorEnrolment($this->passkeys(false)))->isRequiredFor(7, 90)
        );
    }

    /**
     * And so does an adaptor at the threshold — an SMS gateway, say.
     *
     * `MIN_STRENGTH` is the contract an application's own adaptor is measured against, so
     * the boundary is asserted rather than left to be discovered.
     */
    public function testAnAdaptorAtTheThresholdSatisfiesIt(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();
        SecondFactorRegistry::register($this->factor('sms', FactorEnrolment::MIN_STRENGTH, true));

        // Act & Assert
        $this->assertFalse(
            (new FactorEnrolment($this->passkeys(false)))->isRequiredFor(7, 90)
        );
    }

    /**
     * A factor that is registered but not enrolled counts for nothing.
     *
     * The distinction the registry draws and this must not lose: the installation offering
     * authenticators says nothing about whether *this* account set one up.
     */
    public function testAFactorTheAccountHasNotEnrolledCountsForNothing(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();
        SecondFactorRegistry::register($this->factor('twofactor', 60, false));

        // Act & Assert
        $this->assertTrue(
            (new FactorEnrolment($this->passkeys(false)))->isRequiredFor(7, 90)
        );
    }

    /**
     * A passkey satisfies it, though it is not a registered second factor at all.
     *
     * It replaces the password rather than following it, so the registry does not know
     * about it — and an account holding one is exactly as protected as one holding an
     * authenticator, which is why it has to be asked about separately.
     */
    public function testAPasskeySatisfiesIt(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();

        // Act & Assert
        $this->assertFalse(
            (new FactorEnrolment($this->passkeys(true)))->isRequiredFor(7, 90)
        );
    }

    /**
     * A store that will not answer means "no requirement".
     *
     * The direction matters. Guessing "not enrolled" on a failed read would redirect every
     * administrator to the setup screen — and the screen that would fix it is one of the
     * ones being redirected.
     */
    public function testAStoreThatCannotAnswerLetsThemThrough(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();

        $exploding = new FakePasskeyService(false, true);

        // Act & Assert
        $this->assertFalse((new FactorEnrolment($exploding))->isRequiredFor(7, 90));
    }

    /**
     * What an account holds, named, for a support command.
     */
    public function testItNamesWhatTheAccountHolds(): void
    {
        // Arrange
        $this->withFloor(80);
        SecondFactorRegistry::reset();
        SecondFactorRegistry::register($this->factor('twofactor', 60, true));

        // Act
        $held = (new FactorEnrolment($this->passkeys(true)))->factorsFor(7);

        // Assert
        $this->assertContains('twofactor (60)', $held);
        $this->assertContains('passkey', $held);
    }
}
