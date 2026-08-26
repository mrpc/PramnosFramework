<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Application as AuthApplication;

/**
 * The per-application system account.
 *
 * WHAT: when an application gets one, and that it only ever gets one.
 * WHY:  `usertokens.userid` is a foreign key to `users`, and a client-credentials
 *       token has no end user — the token represents the application. Something
 *       has to own the row, and 0 is not a row in `users`.
 *
 *       This existed only inside the JWT-client-assertion branch of the token
 *       endpoint, written inline. The consequence was that the ordinary,
 *       secret-authenticated `client_credentials` grant wrote `userid = 0`,
 *       violated the key, and answered `server_error`. That grant did not work at
 *       all; the one path that did was the one carrying its own copy of this.
 *
 *       Reuse is the other thing that matters. A client polling the token endpoint
 *       would otherwise create an account per request, and `users` is the table
 *       every permission check reads.
 */
class SystemUserTest extends TestCase
{
    /**
     * An application that already has one gets it back, with no write.
     *
     * The hot path: a busy client hits the token endpoint repeatedly, and every
     * one of those requests must reuse the account rather than make another.
     */
    public function testAnExistingSystemUserIsReused(): void
    {
        // Arrange
        $application = $this->application(appid: 5, systemuser: 42);

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(42, $userId);
        $this->assertSame(0, $application->creations, 'no account may be created when one exists');
    }

    /**
     * An application with no system user gets one, and it is recorded.
     *
     * Recording it is what makes the next call cheap; without the assignment this
     * would create an account per token request.
     */
    public function testAMissingSystemUserIsCreatedAndRecorded(): void
    {
        // Arrange
        $application = $this->application(appid: 5, systemuser: null);

        // Act
        $userId = $application->systemUserId();

        // Assert
        $this->assertSame(77, $userId);
        $this->assertSame(1, $application->creations);
        $this->assertSame([77], $application->assigned, 'the id must be written back');
    }

    /**
     * Once created, a second call reuses it.
     *
     * Asserted through two calls on the same object rather than on state, because
     * that is the sequence a repeated token request produces.
     */
    public function testASecondCallReusesWhatTheFirstCreated(): void
    {
        // Arrange
        $application = $this->application(appid: 5, systemuser: null);

        // Act
        $first  = $application->systemUserId();
        $second = $application->systemUserId();

        // Assert
        $this->assertSame($first, $second);
        $this->assertSame(1, $application->creations, 'exactly one account, however many calls');
    }

    /**
     * An unsaved application gets nothing.
     *
     * There is nowhere to record the id, so creating an account would leave an
     * orphan `sys_*` row that nothing points at and nothing cleans up.
     */
    public function testAnUnsavedApplicationGetsNoSystemUser(): void
    {
        // Arrange
        $application = $this->application(appid: 0, systemuser: null);

        // Act / Assert
        $this->assertSame(0, $application->systemUserId());
        $this->assertSame(0, $application->creations);
    }

    /**
     * A system user id of 0 or 1 is not accepted as one.
     *
     * Those are the framework's guest and system rows. Treating either as an
     * application's own account would attribute its tokens to a shared identity.
     *
     * @param int $stored A reserved id found in the column
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reservedIds')]
    public function testAReservedIdIsNotTreatedAsASystemUser(int $stored): void
    {
        // Arrange
        $application = $this->application(appid: 5, systemuser: $stored);

        // Act
        $userId = $application->systemUserId();

        // Assert — a real account was made instead
        $this->assertSame(77, $userId);
        $this->assertSame(1, $application->creations);
    }

    /** @return array<string, array{0: int}> */
    public static function reservedIds(): array
    {
        return ['guest' => [0], 'system' => [1]];
    }

    /**
     * A failure to create one returns 0 rather than throwing.
     *
     * The caller is the token endpoint. An exception here would turn a database
     * hiccup into an unhandled error on a public endpoint; 0 lets the insert fail
     * where it can be reported as the `server_error` it is.
     */
    public function testAFailureReturnsZeroRatherThanThrowing(): void
    {
        // Arrange
        $application = $this->application(appid: 5, systemuser: null);
        $application->failCreation = true;

        // Act / Assert
        $this->assertSame(0, $application->systemUserId());
    }

    /** An Auth Application with user creation and persistence replaced. */
    private function application(int $appid, ?int $systemuser): SystemUserApplication
    {
        $rc          = new \ReflectionClass(SystemUserApplication::class);
        $application = $rc->newInstanceWithoutConstructor();
        $application->appid      = $appid;
        $application->systemuser = $systemuser;

        return $application;
    }
}

/**
 * Auth Application whose account creation and persistence are recorded.
 *
 * `systemUserId()` itself is the production method; only the two boundaries it
 * crosses — making a user row and writing the column — are replaced, so the
 * branching under test is the real branching.
 */
class SystemUserApplication extends AuthApplication
{
    /** How many accounts were created. */
    public int $creations = 0;

    /** @var list<int> Ids written back to the application row */
    public array $assigned = [];

    /** Make creation fail, to exercise the failure branch. */
    public bool $failCreation = false;

    protected function createSystemUserRow(): int
    {
        if ($this->failCreation) {
            throw new \RuntimeException('users table unavailable');
        }

        $this->creations++;

        return 77;
    }

    public function assignSystemUser(int $userId): bool
    {
        $this->assigned[] = $userId;
        $this->systemuser = $userId;

        return true;
    }
}
