<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\TestCase;
use Pramnos\User\User;

/**
 * A subclass, because applications always extend this class.
 *
 * The bug being guarded lives entirely in the subclass case: `__sleep()` was
 * fine for a bare `User` and warned for every private property when the object
 * was an instance of something extending it.
 */
class ApplicationUser extends User
{
    /** @var string Something of the subclass's own to survive the round trip. */
    public string $applicationField = 'set-by-the-subclass';
}

/**
 * Serializing a user.
 *
 * WHAT: does a user survive serialize/unserialize without warnings, and without
 *       carrying a plaintext password with it?
 * WHY:  user objects go into `$_SESSION`, and sessions are written to disk,
 *       Redis or a database. Between `setPassword()` and the rehash in
 *       `_save()` the plain password sits on the object, so it must not be part
 *       of what gets written.
 *
 *       The first attempt used `__sleep()`, which returns property *names* that
 *       PHP then looks up on the object. Private properties are stored under a
 *       mangled name, so serializing a subclass instance produced
 *       "_userstable returned as member variable from __sleep() but does not
 *       exist" for every private property, on every serialize. A reference
 *       application's test suite turned that warning into an error; this
 *       framework's own tests never serialized a subclass and saw nothing.
 */
class UserSerializationTest extends TestCase
{
    /**
     * A subclass instance serializes without warnings.
     *
     * The assertion is the absence of a warning, so the test converts PHP
     * notices into exceptions for the duration — otherwise this passes whether
     * or not the bug is present.
     */
    public function testSerializingASubclassEmitsNoWarnings(): void
    {
        // Arrange
        $user     = new ApplicationUser();
        $captured = [];

        set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
            $captured[] = $message;

            return true;
        });

        // Act
        try {
            $serialized = serialize($user);
        } finally {
            restore_error_handler();
        }

        // Assert
        $this->assertSame([], $captured, 'serializing a user must be silent');
        $this->assertIsString($serialized);
    }

    /**
     * State survives the round trip, including the subclass's own properties.
     *
     * A serializer that dropped the plain password by dropping everything would
     * pass the test above and be useless.
     */
    public function testStateSurvivesTheRoundTrip(): void
    {
        // Arrange
        $user                   = new ApplicationUser();
        $user->username         = 'round-trip';
        $user->applicationField = 'changed';

        // Act
        /** @var ApplicationUser $restored */
        $restored = unserialize(serialize($user));

        // Assert
        $this->assertSame('round-trip', $restored->username);
        $this->assertSame('changed', $restored->applicationField, "the subclass's own state too");
    }

    /**
     * The pending plaintext password is not in the serialized output.
     *
     * The reason any of this exists. The password is set but the user has no id
     * yet, so it has not been hashed — exactly the window in which a session
     * write would put it on disk in clear text.
     */
    public function testThePendingPlainPasswordIsNotSerialized(): void
    {
        // Arrange
        $user = new ApplicationUser();
        $user->setPassword('a-very-secret-password');

        $pending = new \ReflectionProperty(User::class, '_pendingPlainPassword');
        $this->assertSame(
            'a-very-secret-password',
            $pending->getValue($user),
            'precondition: the plain password is on the object'
        );

        // Act
        $serialized = serialize($user);

        // Assert
        $this->assertStringNotContainsString('a-very-secret-password', $serialized);
        $this->assertNull(
            $pending->getValue(unserialize($serialized)),
            'and it does not come back'
        );
    }
}
