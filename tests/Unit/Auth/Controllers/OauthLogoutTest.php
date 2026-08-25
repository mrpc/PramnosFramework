<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;

/**
 * `POST /oauth/logout` — revoking the tokens of one session.
 *
 * WHAT: which tokens the endpoint revokes, and what it answers.
 * WHY:  it revoked nothing at all. The lookup selected `usertokens.sid`, a column
 *       that has never existed, so the query failed, the failure was swallowed by
 *       the builder, and the endpoint took its token-not-found branch for every
 *       token it was ever given. It answered `{"success": true}` and left every
 *       token valid.
 *
 *       That is the worst shape a security bug can take: an endpoint reporting
 *       success while doing nothing. An application that called it on sign-out
 *       believed it had signed the user out.
 *
 * The database is replaced with a recorder, so these tests assert on the revocation
 * that *would* be issued — which is the thing that was missing — without needing a
 * schema to issue it against.
 */
class OauthLogoutTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET    = [];
        $_POST   = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /** A controller with the bearer token, the lookup and the revocation replaced. */
    private function controller(?array $tokenRow, string $bearer = 'a-token'): LoggingOutOauth
    {
        $rc         = new \ReflectionClass(LoggingOutOauth::class);
        $controller = $rc->newInstanceWithoutConstructor();
        $controller->bearer   = $bearer;
        $controller->tokenRow = $tokenRow;

        return $controller;
    }

    /**
     * No bearer token is a 401, not a cheerful success.
     *
     * The one case where the endpoint must be unambiguous: a caller that forgot
     * the header has a bug, and telling it everything went fine hides that bug.
     */
    public function testAMissingBearerTokenIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(null, '');
        $controller->bearer = null;

        // Act
        $response = $controller->logout();

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $controller->revocations);
    }

    /**
     * An unknown token answers success and revokes nothing.
     *
     * In the spirit of RFC 7009: an endpoint that distinguished a real token from
     * an invented one would tell an attacker which of their guesses exist.
     */
    public function testAnUnknownTokenAnswersSuccessWithoutRevoking(): void
    {
        // Arrange — the lookup finds nothing
        $controller = $this->controller(null);

        // Act
        $response = $controller->logout();
        $body     = json_decode($response->getBody(), true);

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame([], $controller->revocations);
    }

    /**
     * A known access token revokes its own family.
     *
     * The assertion that the whole class exists for: before this, `revocations`
     * was empty here.
     */
    public function testAnAccessTokenRevokesItsFamily(): void
    {
        // Arrange — an access token with no parent: it is its own root
        $controller = $this->controller(['tokenid' => 42, 'userid' => 7, 'parentToken' => null]);

        // Act
        $response = $controller->logout();
        $body     = json_decode($response->getBody(), true);

        // Assert
        $this->assertSame([['userId' => 7, 'rootId' => 42]], $controller->revocations);
        $this->assertTrue($body['success']);
        $this->assertSame(7, $body['user_id']);
        $this->assertSame(2, $body['tokens_revoked']);
    }

    /**
     * A refresh token revokes the family it belongs to, not just itself.
     *
     * A refresh token carries `parentToken` — the access token it was issued
     * with. Revoking only the row presented would leave that access token valid,
     * so signing out with a refresh token would leave the user signed in.
     */
    public function testARefreshTokenRevokesFromItsParent(): void
    {
        // Arrange — a refresh token whose parent is access token 42
        $controller = $this->controller(['tokenid' => 43, 'userid' => 7, 'parentToken' => 42]);

        // Act
        $controller->logout();

        // Assert — the root is the parent, so the access token goes too
        $this->assertSame([['userId' => 7, 'rootId' => 42]], $controller->revocations);
    }

    /**
     * The browser session is left alone unless it was asked about.
     *
     * A backend dropping an API token has no business ending the user's browser
     * session, and doing it by default would sign somebody out of a page they were
     * still using.
     */
    public function testTheBrowserSessionSurvivesByDefault(): void
    {
        // Arrange
        $controller = $this->controller(['tokenid' => 42, 'userid' => 7, 'parentToken' => null]);

        // Act
        $controller->logout();

        // Assert
        $this->assertFalse($controller->webSessionEnded);
    }

    /**
     * `logoutwebsession=1` ends the browser session as well.
     *
     * @param string $value A value that should read as "yes"
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('truthyValues')]
    public function testTheBrowserSessionEndsWhenAsked(string $value): void
    {
        // Arrange
        $_POST['logoutwebsession'] = $value;
        $controller = $this->controller(['tokenid' => 42, 'userid' => 7, 'parentToken' => null]);

        // Act
        $controller->logout();

        // Assert
        $this->assertTrue($controller->webSessionEnded, $value . ' should read as yes');
    }

    /** @return array<string, array{0: string}> */
    public static function truthyValues(): array
    {
        return ['one' => ['1'], 'true' => ['true'], 'yes' => ['yes'], 'on' => ['On']];
    }

    /**
     * A value that does not mean yes does not end the session.
     *
     * `0` is the one that matters: a caller that sends the parameter explicitly
     * switched off must not get the opposite of what it asked for.
     *
     * @param string $value A value that should not read as "yes"
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('falsyValues')]
    public function testTheBrowserSessionSurvivesAFalsyValue(string $value): void
    {
        // Arrange
        $_POST['logoutwebsession'] = $value;
        $controller = $this->controller(['tokenid' => 42, 'userid' => 7, 'parentToken' => null]);

        // Act
        $controller->logout();

        // Assert
        $this->assertFalse($controller->webSessionEnded, $value . ' should not read as yes');
    }

    /** @return array<string, array{0: string}> */
    public static function falsyValues(): array
    {
        return ['zero' => ['0'], 'false' => ['false'], 'no' => ['no'], 'empty' => [''], 'other' => ['maybe']];
    }

    /**
     * The parameter is accepted on the query string as well as in the body.
     *
     * The endpoint answers GET and POST, and a GET has nowhere else to put it.
     */
    public function testTheParameterIsAcceptedOnTheQueryString(): void
    {
        // Arrange
        $_GET['logoutwebsession'] = '1';
        $controller = $this->controller(['tokenid' => 42, 'userid' => 7, 'parentToken' => null]);

        // Act
        $controller->logout();

        // Assert
        $this->assertTrue($controller->webSessionEnded);
    }
}

/**
 * Oauth with the bearer token, the token lookup, the revocation and the session
 * teardown replaced by recorders.
 *
 * `revokeTokenFamily()` is overridden rather than mocked at the database level so
 * the test asserts on the *decision* — which user, which family — which is what
 * was wrong. How that decision becomes SQL is the query builder's business and is
 * covered by its own tests.
 */
class LoggingOutOauth extends Oauth
{
    /** The bearer token the request carries, or null for none. */
    public ?string $bearer = 'a-token';

    /** What the lookup should find, or null for nothing. */
    public ?array $tokenRow = null;

    /** @var list<array{userId: int, rootId: int}> Revocations that were issued */
    public array $revocations = [];

    /** Whether the browser session was ended. */
    public bool $webSessionEnded = false;

    protected function extractBearerToken(): ?string
    {
        return $this->bearer;
    }

    protected function findTokenRow(string $token): ?array
    {
        return $this->tokenRow;
    }

    protected function revokeTokenFamily(mixed $db, int $userId, int $rootId): int
    {
        $this->revocations[] = ['userId' => $userId, 'rootId' => $rootId];
        return 2;
    }

    protected function endWebSession(): void
    {
        $this->webSessionEnded = true;
    }

    protected function recordLogout(int $userId): void
    {
        // The activity log needs a database; the decision under test does not.
    }
}
