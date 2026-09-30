<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\RequestIdentity;
use Pramnos\User\User;

/**
 * `User::current()` answers `null` for nobody, so `?->` is safe on it.
 *
 * `getCurrentUser()` answers `false`, which the nullsafe operator does not short-circuit:
 * `getCurrentUser()?->userid` throws from every cron job and CLI command. `current()` is the
 * same lookup with the answer `?->` expects, and `getCurrentUser()` keeps `false` because
 * callers compare with `=== false`.
 */
#[CoversMethod(User::class, 'current')]
class UserCurrentTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestIdentity::reset();
    }

    /**
     * Nobody signed in: `null`, and `?->` reads it without throwing.
     */
    public function testNobodyIsNull(): void
    {
        // Arrange
        RequestIdentity::seal(null, 'signed-out');

        // Act
        $user = User::current();

        // Assert
        $this->assertFalse(User::getCurrentUser(), 'getCurrentUser() keeps its false');
        $this->assertNull($user);
        $this->assertNull(User::current()?->userid, 'the nullsafe read is safe');
    }

    /**
     * Somebody signed in: the same object `getCurrentUser()` returns — including an
     * identity a middleware sealed as a plain object, which a narrower type would drop.
     */
    public function testSomebodyIsTheSameObject(): void
    {
        // Arrange
        $caller = (object) ['userid' => 42];
        RequestIdentity::seal($caller, 'bearer');

        // Act
        $user = User::current();

        // Assert
        $this->assertSame($caller, $user);
        $this->assertSame(42, User::current()?->userid);
    }
}
