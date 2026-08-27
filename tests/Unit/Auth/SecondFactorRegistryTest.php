<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\SecondFactorInterface;
use Pramnos\Auth\SecondFactorRegistry;

/**
 * Adding a second factor the framework has never heard of.
 *
 * The point of the registry, so the test is written as the thing it exists for: a fake SMS
 * adaptor, registered by an application, taking part in everything — ordering, the
 * enrolled list, lookup by name — without a line of the framework knowing it exists.
 *
 * The other half is the promise that registering is not the same as enabling.
 * `auth.twofactor_methods` still decides, so a shared codebase can register several
 * adaptors and a given deployment offer one.
 */
#[CoversClass(SecondFactorRegistry::class)]
class SecondFactorRegistryTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        SecondFactorRegistry::reset();
    }

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

    /** @param list<string> $methods */
    private function withMethods(array $methods): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => ['twofactor_methods' => $methods]];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * An application's own factor is offered beside the built-ins, in strength order.
     *
     * The ordering is the interesting half: the adaptor declares 40 and lands between the
     * authenticator app (60) and the mailed code (20) without either of them being edited,
     * which is what lets a framework ship two factors and an application insert a third.
     */
    public function testAnApplicationsOwnFactorTakesItsPlaceByStrength(): void
    {
        // Arrange
        $this->withMethods(['totp', 'email', 'sms']);
        SecondFactorRegistry::register(new FakeSmsFactor());

        // Act
        $names = array_map(
            static fn (SecondFactorInterface $f): string => $f->name(),
            SecondFactorRegistry::all()
        );

        // Assert
        $this->assertSame(['totp', 'sms', 'email'], $names);
    }

    /**
     * Registering a factor does not enable it — the application's list does.
     *
     * A shared codebase may register an SMS adaptor everywhere and a given deployment not
     * pay for the gateway. Withdrawing it must not need a code change, and must not leave
     * accounts enrolled in something the login will not offer.
     */
    public function testRegisteringIsNotEnabling(): void
    {
        // Arrange — registered, but not in the application's list
        $this->withMethods(['totp']);
        SecondFactorRegistry::register(new FakeSmsFactor());

        // Act & Assert
        $this->assertNull(SecondFactorRegistry::get('sms'));
        $this->assertSame(
            ['totp'],
            array_map(static fn (SecondFactorInterface $f): string => $f->name(),
                SecondFactorRegistry::all())
        );
    }

    /**
     * A factor registered under an existing name replaces it.
     *
     * An application that wants the mailed code sent through its own transactional
     * provider registers its own `email` and gets it — rather than two factors answering
     * to one name and the flow taking whichever was registered first.
     */
    public function testRegisteringOverAnExistingNameReplacesIt(): void
    {
        // Arrange
        $this->withMethods(['totp', 'email']);
        SecondFactorRegistry::register(new FakeEmailReplacement());

        // Act
        $factor = SecondFactorRegistry::get('email');

        // Assert
        $this->assertInstanceOf(FakeEmailReplacement::class, $factor);
        $this->assertSame('Our own mail', $factor->label());
    }

    /**
     * Only the factors an account can actually complete are offered.
     *
     * `isEnrolledFor()` returning true is a promise that verification can succeed, so this
     * list is what a step-up may demand. Anything else would be a step-up nobody can
     * finish.
     */
    public function testOnlyEnrolledFactorsAreOffered(): void
    {
        // Arrange
        $this->withMethods(['totp', 'sms']);
        $sms = new FakeSmsFactor();
        $sms->enrolled = false;
        SecondFactorRegistry::register($sms);

        // Act
        $enrolled = SecondFactorRegistry::enrolledFor(7);

        // Assert — the fake SMS says no; the built-ins want a database and say no too
        $this->assertSame([], array_map(
            static fn (SecondFactorInterface $f): string => $f->name(),
            $enrolled
        ));

        // …and it is offered once it says yes
        $sms->enrolled = true;
        $this->assertSame(
            ['sms'],
            array_map(static fn (SecondFactorInterface $f): string => $f->name(),
                SecondFactorRegistry::enrolledFor(7))
        );
    }

    /**
     * A factor that throws is skipped, not fatal.
     *
     * One adaptor with an unreachable gateway must not make every account unable to sign
     * in — which is what an exception escaping the enrolment question would do.
     */
    public function testAThrowingFactorIsSkipped(): void
    {
        // Arrange
        $this->withMethods(['totp', 'sms']);
        $sms = new FakeSmsFactor();
        $sms->throws = true;
        SecondFactorRegistry::register($sms);

        // Act & Assert
        $this->assertSame([], SecondFactorRegistry::enrolledFor(7));
    }

    /**
     * With no application at all, everything registered is allowed.
     *
     * A console command verifying a code, a worker, a test: there is no configuration to
     * honour, and a factor is there because code put it there. Filtering it out would give
     * a registry that silently answers "nothing" outside a web request.
     */
    public function testWithNoApplicationEverythingRegisteredIsAllowed(): void
    {
        // Arrange
        (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, []);
        SecondFactorRegistry::register(new FakeSmsFactor());

        // Act & Assert
        $this->assertNotNull(SecondFactorRegistry::get('sms'));
    }
}

/**
 * The adaptor an application would write — an SMS, stubbed.
 *
 * Deliberately complete rather than minimal: it is the worked example the guide points at,
 * so it shows the two things an implementation owes the flow — an honest
 * `isEnrolledFor()`, and `needsSending()` telling the screen a challenge has to be
 * delivered.
 */
class FakeSmsFactor implements SecondFactorInterface
{
    public bool $enrolled = true;
    public bool $throws = false;
    public int $sent = 0;

    public function name(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'Text message';
    }

    /** Between the app (60) and the mailed code (20). */
    public function strength(): int
    {
        return 40;
    }

    public function isEnrolledFor(int $userId): bool
    {
        if ($this->throws) {
            throw new \RuntimeException('gateway unreachable');
        }

        return $this->enrolled;
    }

    public function needsSending(): bool
    {
        return true;
    }

    public function send(int $userId): bool
    {
        $this->sent++;

        return true;
    }

    public function verify(int $userId, string $code): bool
    {
        return $code === '123456';
    }
}

/** An application replacing a built-in factor with its own implementation. */
class FakeEmailReplacement implements SecondFactorInterface
{
    public function name(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Our own mail';
    }

    public function strength(): int
    {
        return 20;
    }

    public function isEnrolledFor(int $userId): bool
    {
        return true;
    }

    public function needsSending(): bool
    {
        return true;
    }

    public function send(int $userId): bool
    {
        return true;
    }

    public function verify(int $userId, string $code): bool
    {
        return false;
    }
}
