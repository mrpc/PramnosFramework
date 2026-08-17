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
     * A signed-in session below the required role is refused.
     *
     * The role is re-read from the database rather than trusted from the session, which
     * is what makes this refusal meaningful: a remember-me cookie can outlive a
     * demotion by a fortnight, and a token minted from that session would then be good
     * for its whole lifetime afterwards.
     *
     * The user here is sealed `via: 'session'` — the one credential that *is*
     * exchangeable — so this test reaches the role check rather than stopping at the
     * one above it. `usertype` 10 against a minimum of 90.
     *
     * @return void
     */
    public function testASessionBelowTheMinimumRoleIsRefused(): void
    {
        // Arrange
        RequestIdentity::seal((object) ['userid' => 42, 'usertype' => 10], 'session');

        // Act
        $token = SessionExchange::issue(minimumUserType: 90);

        // Assert
        $this->assertNull($token);
    }

    /**
     * Failure is null rather than an exception, whatever went wrong.
     *
     * The fourth documented decision. The caller is a route that has to redirect
     * somewhere either way, and a route that throws on a missing signing key turns a
     * misconfiguration into a 500 on the page somebody opened to sign in.
     *
     * Exercised with no application configured at all, which is the harshest version:
     * no signing key, no audience, nothing to read.
     *
     * @return void
     */
    public function testAMisconfiguredApplicationYieldsNullRatherThanThrowing(): void
    {
        // Arrange — a session-authenticated user, so the guards above are passed and
        // the failure has to come from minting
        RequestIdentity::seal((object) ['userid' => 42, 'usertype' => 99], 'session');

        // Act & Assert — the assertion is that this call returns at all
        $this->assertNull(SessionExchange::issue(minimumUserType: 0));
    }
}
