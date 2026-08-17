<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\RequestIdentity;

/**
 * A request has three possible identities, not two.
 *
 * `seal(?object $user)` models *an account* or *nobody*, which is right for an API
 * where anonymous means no identity at all. It is not enough for an application whose
 * unauthenticated callers are people: a chat participant with a nickname and a session,
 * present in a room, mutable, bannable, addressable, and the same person across
 * requests for as long as they stay.
 *
 * Without a third state such an application keeps a **second, parallel notion of who
 * the caller is** — and then every consumer asking *"who is this"* has to know which of
 * two mechanisms to ask, with a convention between them instead of a type. Reported by
 * exactly such an application, which had built that parallel notion by hand.
 *
 * The tests that matter here are the ones about **promotion**, because the failure they
 * guard is silent: an accidental `sealGuest()` late in a request would demote an
 * authenticated caller, and every permission check after it would answer for the wrong
 * person while looking entirely healthy.
 */
class RequestIdentityGuestTest extends TestCase
{
    /**
     * Each test starts with nothing settled.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RequestIdentity::reset();
    }

    /**
     * And leaves nothing behind — the state is process-wide by design.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        RequestIdentity::reset();
    }

    /**
     * A stand-in for a user row.
     *
     * @param  int $id The user id
     * @return object
     */
    private function user(int $id = 42): object
    {
        return (object) ['userid' => $id, 'username' => 'someone'];
    }

    /**
     * A guest is sealed, identified, and still not a user.
     *
     * The third rule stated in the docblock: code asking for a user must not be handed
     * something that merely resembles one, which is why `isGuest()` is a separate
     * question rather than `user()` returning a shape.
     *
     * @return void
     */
    public function testAGuestIsSealedAndIsNotAUser(): void
    {
        // Act
        $sealed = RequestIdentity::sealGuest('presence:abc123', 'presence');

        // Assert
        $this->assertTrue($sealed);
        $this->assertTrue(RequestIdentity::isSealed());
        $this->assertTrue(RequestIdentity::isGuest());
        $this->assertNull(RequestIdentity::user(), 'A guest is not a users row.');
        $this->assertSame('presence:abc123', RequestIdentity::guestId());
        $this->assertSame('presence', RequestIdentity::via());
    }

    /**
     * `subject()` answers for all three states with one call.
     *
     * The point of the whole change: one question, one answer, with a type — rather
     * than two mechanisms and a convention about which to consult.
     *
     * @return void
     */
    public function testSubjectDistinguishesAllThreeStates(): void
    {
        // Assert — nobody has asked yet
        $this->assertNull(RequestIdentity::subject());

        // Act & Assert — a guest
        RequestIdentity::sealGuest('presence:abc123');
        $this->assertSame('presence:abc123', RequestIdentity::subject());

        // Act & Assert — an account
        RequestIdentity::reset();
        RequestIdentity::seal($this->user(42), 'accessToken');
        $this->assertSame(42, RequestIdentity::subject());

        // Act & Assert — sealed as genuinely nobody
        RequestIdentity::reset();
        RequestIdentity::seal(null);
        $this->assertNull(RequestIdentity::subject());
        $this->assertFalse(RequestIdentity::isGuest());
        $this->assertTrue(RequestIdentity::isSealed(), 'Anonymous is still an answer.');
    }

    /**
     * A guest cannot replace an account.
     *
     * The asymmetry is deliberate and it is the security-relevant half. This direction
     * is a demotion, and a demotion arriving by accident — a middleware that seals a
     * guest unconditionally, ordered after the one that authenticates — would leave
     * every later permission check answering for the wrong person.
     *
     * @return void
     */
    public function testAGuestCannotReplaceAnAccount(): void
    {
        // Arrange
        RequestIdentity::seal($this->user(7), 'accessToken');

        // Act
        $sealed = RequestIdentity::sealGuest('presence:abc123');

        // Assert — refused, and nothing moved
        $this->assertFalse($sealed);
        $this->assertFalse(RequestIdentity::isGuest());
        $this->assertNotNull(RequestIdentity::user());
        $this->assertSame(7, RequestIdentity::subject());
        $this->assertSame('accessToken', RequestIdentity::via());
    }

    /**
     * An account replaces a guest, because that is a real login.
     *
     * The other direction must work: a visitor who was present as a guest and then
     * signs in during the same request is an account from that point. Leaving the guest
     * id set alongside would be a request with two identities, which is the state this
     * whole change exists to remove.
     *
     * @return void
     */
    public function testAnAccountReplacesAGuest(): void
    {
        // Arrange
        RequestIdentity::sealGuest('presence:abc123', 'presence');

        // Act
        RequestIdentity::seal($this->user(9), 'password');

        // Assert
        $this->assertFalse(
            RequestIdentity::isGuest(),
            'A signed-in request must not also be a guest.'
        );
        $this->assertNull(RequestIdentity::guestId());
        $this->assertSame(9, RequestIdentity::subject());
        $this->assertSame('password', RequestIdentity::via());
    }

    /**
     * An empty identifier is refused rather than sealing an unidentifiable guest.
     *
     * A guest whose id is `''` is indistinguishable from every other such guest, so
     * anything keyed on it — a mute, a ban, a rate limit — would apply to all of them
     * at once. Silently accepting it is how that ships.
     *
     * @return void
     */
    public function testAnEmptyIdentifierIsRefused(): void
    {
        // Act
        $sealed = RequestIdentity::sealGuest('');

        // Assert
        $this->assertFalse($sealed);
        $this->assertFalse(RequestIdentity::isGuest());
        $this->assertFalse(RequestIdentity::isSealed());
    }

    /**
     * A guest identity carries no issued token.
     *
     * `issuedToken()` describes a credential this request handed out, and a guest is
     * given nothing. Leaving a previous value visible would let the toolbar attribute
     * a token to a caller that never received one.
     *
     * @return void
     */
    public function testAGuestHasNoIssuedToken(): void
    {
        // Arrange — a login earlier in the process issued one
        RequestIdentity::seal($this->user(3), 'password', 'a.jwt.value');
        $this->assertSame('a.jwt.value', RequestIdentity::issuedToken());

        // Act — reset, as a new request would, then seal a guest
        RequestIdentity::reset();
        RequestIdentity::sealGuest('presence:xyz');

        // Assert
        $this->assertNull(RequestIdentity::issuedToken());
    }

    /**
     * `reset()` clears the guest too.
     *
     * The state is process-wide, and a worker serving a second request must not
     * inherit the first one's visitor. Adding a field without adding it here is how
     * that leak arrives.
     *
     * @return void
     */
    public function testResetClearsTheGuest(): void
    {
        // Arrange
        RequestIdentity::sealGuest('presence:abc123');

        // Act
        RequestIdentity::reset();

        // Assert
        $this->assertFalse(RequestIdentity::isGuest());
        $this->assertNull(RequestIdentity::guestId());
        $this->assertNull(RequestIdentity::subject());
        $this->assertFalse(RequestIdentity::isSealed());
    }
}
