<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Application as AuthApplication;

/**
 * Which account a client-credentials token is stored under.
 *
 * A client-credentials grant has no user — a machine is calling on its own behalf — and the token
 * table still needs a `userid`. So each application gets a machine account of its own, created on
 * first use and reused afterwards, and `systemUserId()` is what decides.
 *
 * None of it had ever executed, and the invariant it protects is a one-character difference:
 *
 * ```php
 * if ($this->systemuser !== null && (int) $this->systemuser > 1) {
 * ```
 *
 * **`> 1`, not `> 0`.** Zero and one are the framework's guest and system rows. An application whose
 * `systemuser` column holds either does not have an account of its own — it has a gap — and a token
 * stored under one of those ids sits beneath an identity shared with every other application that
 * has the same gap. Anything that later reasons about "what has this account been doing" would then
 * be reading several applications' activity as one.
 *
 * Every refusal here returns `0` rather than raising, and the reason is written on the repository
 * that calls it: the insert then fails as it did before, which is the honest outcome — a token for
 * an application that cannot be resolved should not be stored under a user invented for it.
 */
#[CoversClass(AuthApplication::class)]
class SystemUserIdentityTest extends TestCase
{
    /**
     * An application whose row-creation and assignment are the test's to control.
     *
     * `createSystemUserRow()` is a documented seam — making a user row is the one thing here that
     * needs a database — and `assignSystemUser()` writes the column, so both are replaced. What
     * runs is the decision between them, which is the part with the invariant in it.
     *
     * @param int|\Throwable $created What creating a row yields, or throws
     */
    private function application(
        int $appid,
        mixed $systemuser,
        int|\Throwable $created = 42
    ): object {
        $application = new class (new \Pramnos\Application\Controller(), $created) extends AuthApplication {
            /** @var list<int> Ids the column was asked to hold */
            public array $assigned = [];

            public int $creations = 0;

            public function __construct(
                \Pramnos\Application\Controller $controller,
                private readonly int|\Throwable $created
            ) {
                parent::__construct($controller);
            }

            protected function createSystemUserRow(): int
            {
                $this->creations++;

                if ($this->created instanceof \Throwable) {
                    throw $this->created;
                }

                return $this->created;
            }

            public function assignSystemUser(int $userId): bool
            {
                $this->assigned[] = $userId;

                return true;
            }
        };

        $application->appid      = $appid;
        $application->systemuser = $systemuser;

        return $application;
    }

    /**
     * An application that already has its own account reuses it.
     *
     * The reason the column exists: a client hammering the token endpoint must not accumulate an
     * account per request.
     */
    public function testAnApplicationWithItsOwnAccountReusesIt(): void
    {
        // Arrange
        $application = $this->application(9, 55);

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(55, $userId);
        $this->assertSame(0, $application->creations, 'a second account was created for the same client');
    }

    /**
     * A column holding `0` or `1` is not an account, and is replaced.
     *
     * The invariant. `0` and `1` are the guest and system rows, so an application holding either
     * has a gap rather than an identity — and a `> 0` test here would hand back the shared row and
     * store the token under it.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function sharedIdentities(): array
    {
        return [
            // No string cases: the property is typed `?int`, so `'0'` cannot be assigned at all.
            // The `(int)` cast in the guard is belt-and-braces against a value that PHP now
            // refuses earlier — worth leaving, and not something a test can reach.
            'guest row'  => [0],
            'system row' => [1],
            'never set'  => [null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sharedIdentities')]
    public function testAColumnHoldingASharedIdentityIsReplaced(mixed $stored): void
    {
        // Arrange
        $application = $this->application(9, $stored);

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(42, $userId, 'the shared row was handed back instead of a real account');
        $this->assertSame(1, $application->creations);
        $this->assertSame([42], $application->assigned, 'the new account was not written to the column');
    }

    /**
     * A creation that yields `0` or `1` is refused, not assigned.
     *
     * The same invariant on the other side of the seam: whatever the row creation says, an id at or
     * below `1` is not an account this application may own, and returning it would put the token
     * under the shared identity by a different route.
     *
     * @return array<string, array{0: int}>
     */
    public static function unusableCreations(): array
    {
        return ['nothing created' => [0], 'the system row' => [1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableCreations')]
    public function testACreationThatYieldsASharedIdentityIsRefused(int $created): void
    {
        // Arrange
        $application = $this->application(9, null, $created);

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(0, $userId);
        $this->assertSame([], $application->assigned, 'a shared id was written to the column');
    }

    /**
     * An application with no id gets no account.
     *
     * There is nothing to attach one to: `assignSystemUser()` refuses an `appid` of `0` as well, so
     * a created row would be orphaned — a user account belonging to no application, left behind on
     * every call.
     */
    public function testAnApplicationWithNoIdGetsNoAccount(): void
    {
        // Arrange
        $application = $this->application(0, null);

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(0, $userId);
        $this->assertSame(0, $application->creations, 'a row was created for no application');
    }

    /**
     * A creation that raises answers `0`, and the caller's insert fails honestly.
     *
     * Rather than propagating: the token endpoint's job is to answer the request, and a database
     * that cannot make a machine account is not a reason to return a 500 with a stack trace to a
     * client. The log line is where it is visible.
     */
    public function testACreationThatRaisesAnswersZero(): void
    {
        // Arrange
        $application = $this->application(9, null, new \RuntimeException('users table is gone'));

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(0, $userId);
        $this->assertSame([], $application->assigned);
    }

    /**
     * The repository refuses an empty client identifier without touching anything.
     *
     * The first line of `resolveSystemUserId()`, and the cheapest of its three refusals: a token
     * request that named no client cannot have an application, so there is nothing to look up and
     * nothing to create.
     */
    public function testAnEmptyClientIdentifierResolvesToZero(): void
    {
        // Arrange
        $repository = new \Pramnos\Auth\OAuth2\Repositories\AccessTokenRepository(
            new \Pramnos\Application\Controller()
        );

        // Act
        $userId = (new \ReflectionMethod($repository, 'resolveSystemUserId'))->invoke($repository, '');

        // Assert
        $this->assertSame(0, $userId);
    }
}
