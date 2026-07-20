<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Me;
use Pramnos\User\User;

/**
 * Unit tests for the framework Me API controller.
 *
 * WHAT: the JSON contract of /me, /me/tokens and DELETE /me/tokens/{id} — the
 *       generic "current authenticated user" endpoints apps thin-wrap.
 * WHY:  this is an authenticated edge. Every action must return 401 when there is
 *       no user, expose only the safe profile subset, and revoke exactly the
 *       requested token. The current-user lookup and the User token API are
 *       stubbed via seams so no session/DB is needed.
 */
#[CoversClass(Me::class)]
class MeControllerTest extends TestCase
{
    private TestableMe $c;

    protected function setUp(): void
    {
        $this->c = new TestableMe(null);
    }

    // ── display() ───────────────────────────────────────────────────────────────

    /** An authenticated request returns the safe public profile with HTTP 200. */
    public function testDisplayReturnsProfileWhenAuthenticated(): void
    {
        // Arrange
        $this->c->stub = $this->user(5, 'alice', 'alice@example.com');

        // Act
        $res  = $this->c->display();
        $body = json_decode($res->getBody(), true);

        // Assert
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(5, $body['data']['id']);
        $this->assertSame('alice', $body['data']['username']);
        $this->assertSame('alice@example.com', $body['data']['email']);
        // The profile is a curated subset — no password/hash leaks through.
        $this->assertArrayNotHasKey('password', $body['data']);
    }

    /** With no authenticated user, display() returns 401 not_authenticated. */
    public function testDisplayReturns401WhenAnonymous(): void
    {
        $this->c->stub = false;

        $res = $this->c->display();

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('not_authenticated', $res->getBody());
    }

    /** The guest account (userid < 1) is treated as unauthenticated. */
    public function testDisplayReturns401ForGuestUser(): void
    {
        $this->c->stub = $this->user(0, 'Anonymous', '');

        $res = $this->c->display();

        $this->assertSame(401, $res->getStatusCode());
    }

    // ── tokens() ────────────────────────────────────────────────────────────────

    /** tokens() returns the current user's active tokens. */
    public function testTokensReturnsList(): void
    {
        $user = $this->user(5, 'alice', 'alice@example.com');
        $user->tokensReturned = [['tokenid' => 1, 'token' => 'abc', 'tokentype' => 'auth']];
        $this->c->stub = $user;

        $res  = $this->c->tokens();
        $body = json_decode($res->getBody(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, $body['data'][0]['tokenid']);
    }

    /** tokens() requires authentication. */
    public function testTokensReturns401WhenAnonymous(): void
    {
        $this->c->stub = false;

        $res = $this->c->tokens();

        $this->assertSame(401, $res->getStatusCode());
    }

    // ── deleteTokens() ──────────────────────────────────────────────────────────

    /** A valid id revokes exactly that token and returns 200. */
    public function testDeleteTokensRevokesTheGivenToken(): void
    {
        $user = $this->user(5, 'alice', 'alice@example.com');
        $this->c->stub = $user;

        $res = $this->c->deleteTokens('7');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([7], $user->deleted, 'exactly the requested token id is revoked');
    }

    /** A missing token id is a 400 (never a blanket revoke). */
    public function testDeleteTokensWithoutIdReturns400(): void
    {
        $user = $this->user(5, 'alice', 'alice@example.com');
        $this->c->stub = $user;

        $res = $this->c->deleteTokens(null);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame([], $user->deleted, 'no token is revoked when the id is missing');
    }

    /** deleteTokens() requires authentication. */
    public function testDeleteTokensReturns401WhenAnonymous(): void
    {
        $this->c->stub = false;

        $res = $this->c->deleteTokens('7');

        $this->assertSame(401, $res->getStatusCode());
    }

    /** Build a stub user with the given identity. */
    private function user(int $id, string $username, string $email): StubMeUser
    {
        $user = new StubMeUser();
        $user->userid   = $id;
        $user->username = $username;
        $user->email    = $email;
        return $user;
    }
}

/**
 * Me with only the static user lookup replaced by a settable stub, so the real
 * currentUser() guard (anonymous + guest handling) still runs under test.
 */
class TestableMe extends Me
{
    /** @var User|false */
    public $stub = false;

    protected function resolveUser()
    {
        return $this->stub;
    }
}

/** User with the token API stubbed so no database is touched. */
class StubMeUser extends User
{
    /** @var array<int, array<string, mixed>> */
    public array $tokensReturned = [];
    /** @var list<int> */
    public array $deleted = [];

    public function __construct()
    {
        // Skip the parent constructor: no DB load in a unit test.
    }

    public function getAllTokens()
    {
        return $this->tokensReturned;
    }

    public function deleteToken($tokenid)
    {
        $this->deleted[] = (int) $tokenid;
        return $this;
    }
}
