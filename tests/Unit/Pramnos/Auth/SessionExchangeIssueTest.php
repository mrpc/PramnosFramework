<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\SessionExchange;
use Pramnos\Http\RequestIdentity;

/**
 * Who `SessionExchange::issue()` refuses, and why each refusal matters.
 *
 * The first version of this feature shipped with only `redirectUrl()` tested — four
 * tests about where a token may travel, and none about whether one should be issued at
 * all. That was noticed by being asked whether the change met the repository's
 * standards, which it did not: `issue()` is the half with the security decisions in it.
 *
 * The refusals are the contract. A method that mints bearer credentials is defined by
 * whom it declines, and each case here is a way the wrong caller could have got one.
 *
 * **Every case here is decided before a query.** That is not incidental tidiness: the
 * refusals that need the database — the role re-read from the account, the missing signing
 * key — live in `SessionExchangeMintTest`, because they are only meaningful against a real
 * row. Keeping them here also broke the suite: `new User($id)` built an unconnected
 * `Database` singleton with no settings loaded, and the integration class that ran
 * afterwards inherited it and failed to connect. A unit test that reaches for a database
 * is not merely slower, it is a hazard for whatever runs next.
 *
 * One branch is deliberately not asserted here: deriving the key from the site URL.
 * `tests/bootstrap.php` defines `sURL` as `''`, and a constant cannot be redefined — not
 * even in an isolated process, because the bootstrap runs there too. Asserting it would
 * mean either changing the bootstrap for every test in the suite or pretending; the
 * equality that matters (`SessionExchange` and the API verifier deriving the same value)
 * is a single call to `Api::deriveAuthenticationKey()` in both, which is the strongest
 * guarantee available without a second mechanism to keep in step.
 */
class SessionExchangeIssueTest extends TestCase
{
    /**
     * Nothing settled, so each test states its own starting identity.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RequestIdentity::reset();
    }

    /**
     * Leave nothing for the next test — the state is process-wide.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        RequestIdentity::reset();
    }

    /**
     * A request authenticated by a bearer token cannot exchange itself for another.
     *
     * **The case this class exists for, and a hole introduced by the first draft.**
     * `User::getCurrentUser()` prefers a *sealed* identity over the session, so an API
     * request carrying a token reached the minting path — and a token exchanging itself
     * for a fresh token is a **refresh**, with rotation and revocation questions this
     * method does not answer and does not claim to.
     *
     * Left in, it would have been an unbounded credential extension: every twelve-hour
     * token good for another twelve hours on request, forever, from a method documented
     * as exchanging a session.
     *
     * @return void
     */
    public function testATokenAuthenticatedRequestIsRefused(): void
    {
        // Arrange — what ApiAuthMiddleware leaves behind on a bearer-token call
        RequestIdentity::seal((object) ['userid' => 42, 'usertype' => 99], 'accessToken');

        // Act
        $token = SessionExchange::issue();

        // Assert
        $this->assertNull(
            $token,
            'A bearer token must not be exchangeable for another bearer token.'
        );
    }

    /**
     * Nor can any other non-session credential.
     *
     * Asserted across several `via` values because the check is written as *"only a
     * session"* rather than as a list of credentials to exclude. A blocklist would have
     * to enumerate every credential that is not a session, which is unbounded — the
     * shape that produced a separate defect in this framework the same week.
     *
     * @return void
     */
    public function testEveryNonSessionCredentialIsRefused(): void
    {
        foreach (['accessToken', 'userAuth', 'password', 'session-exchange', 'jwt'] as $via) {
            // Arrange
            RequestIdentity::reset();
            RequestIdentity::seal((object) ['userid' => 42, 'usertype' => 99], $via);

            // Act & Assert
            $this->assertNull(
                SessionExchange::issue(),
                "A request authenticated via '{$via}' must not be exchanged."
            );
        }
    }

    /**
     * A guest is refused, and refused *before* anything is minted.
     *
     * The third state added alongside this feature. A guest is somebody, which is the
     * point of `sealGuest()` — but not somebody with an account, and there is no
     * account for a bearer token to represent.
     *
     * @return void
     */
    public function testAGuestIsRefused(): void
    {
        // Arrange
        RequestIdentity::sealGuest('presence:abc123', 'presence');

        // Act & Assert
        $this->assertNull(SessionExchange::issue());
    }

    /**
     * A request sealed as nobody is refused.
     *
     * Sealed-and-anonymous is a real answer, and the answer is that there is nobody to
     * issue for. No implicit anonymous token, which is the third of the four documented
     * decisions.
     *
     * @return void
     */
    public function testAnAnonymousRequestIsRefused(): void
    {
        // Arrange
        RequestIdentity::seal(null);

        // Act & Assert
        $this->assertNull(SessionExchange::issue());
    }


    /**
     * A session that says the guest user is signed in is refused.
     *
     * User ids 0 and 1 are the guest by this framework's convention, and this is the one
     * refusal that a *session* can trigger: the seal is `via: 'session'`, so it passes the
     * credential check and is stopped by the identity behind it. Without the check a token
     * would be minted for user 1 and would authenticate as "somebody" everywhere it was
     * presented.
     *
     * @return void
     */
    public function testASessionForTheGuestUserIsRefused(): void
    {
        foreach ([0, 1] as $guestId) {
            // Arrange
            RequestIdentity::reset();
            RequestIdentity::seal((object) ['userid' => $guestId, 'usertype' => 0], 'session');

            // Act & Assert
            $this->assertNull(
                SessionExchange::issue(),
                "User id {$guestId} is the guest and has nothing to exchange."
            );
        }
    }

}
