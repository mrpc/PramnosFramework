<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;

/**
 * Resolving a presented token to the row it is stored as.
 *
 * WHAT: how `introspect` and `revoke` find a token in `usertokens`.
 * WHY:  they could not find one. A token issued through the League server is a
 *       **JWT**; what `persistNewAccessToken()` stores is its `jti`, the opaque
 *       identifier League generates. Both endpoints matched the presented value
 *       literally, so neither ever found an access token this server had issued.
 *
 *       Introspection answered `{"active": false}` for every live token — for a
 *       resource server that trusts introspection, every request refused. Revocation
 *       was worse: RFC 7009 makes it answer 200 whether or not anything matched, so
 *       an application revoking on sign-out was told it had worked, every time,
 *       while the token stayed valid until it expired on its own.
 *
 * The lookup order is the part worth pinning. The literal value is tried first, so
 * the tokens this framework stores verbatim — web sessions, API tokens — keep
 * behaving exactly as they did.
 */
class OauthTokenIdentityTest extends TestCase
{
    /** A controller with the database lookup replaced by a fixed set of rows. */
    private function controller(array $rowsByToken): TokenResolvingOauth
    {
        $rc         = new \ReflectionClass(TokenResolvingOauth::class);
        $controller = $rc->newInstanceWithoutConstructor();
        $controller->rows = $rowsByToken;

        return $controller;
    }

    /** Build a JWT-shaped string carrying the given claims. */
    private function jwt(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(
            strtr(base64_encode(json_encode($data)), '+/', '-_'),
            '='
        );

        return $encode(['alg' => 'RS256', 'typ' => 'JWT'])
            . '.' . $encode($claims)
            . '.' . rtrim(strtr(base64_encode('not-a-real-signature'), '+/', '-_'), '=');
    }

    /**
     * A token stored verbatim is found by its own value, with one lookup.
     *
     * Tried first on purpose: this is how every web-session and API token in the
     * framework is stored, and their behaviour must not change.
     */
    public function testAVerbatimTokenIsFoundDirectly(): void
    {
        // Arrange
        $controller = $this->controller(['plain-api-token' => ['status' => 1]]);

        // Act
        $row = $controller->find('plain-api-token');

        // Assert
        $this->assertNotNull($row);
        $this->assertSame(['plain-api-token'], $controller->lookups, 'one lookup, no JWT parsing');
    }

    /**
     * A JWT is found by the `jti` inside it.
     *
     * The assertion this whole class exists for: before the fallback, this returned
     * null and both endpoints reported a live token as absent.
     */
    public function testAJwtIsFoundByItsJti(): void
    {
        // Arrange — only the jti is stored, which is what League writes
        $jwt = $this->jwt(['jti' => 'the-opaque-identifier', 'exp' => time() + 3600]);
        $controller = $this->controller(['the-opaque-identifier' => ['status' => 1]]);

        // Act
        $row = $controller->find($jwt);

        // Assert
        $this->assertNotNull($row);
        $this->assertSame([$jwt, 'the-opaque-identifier'], $controller->lookups,
            'the literal value is tried first, then the jti');
    }

    /**
     * A token that matches nothing returns null after both attempts.
     */
    public function testAnUnknownTokenIsNotFound(): void
    {
        // Arrange
        $jwt = $this->jwt(['jti' => 'not-stored']);
        $controller = $this->controller([]);

        // Act
        $row = $controller->find($jwt);

        // Assert
        $this->assertNull($row);
    }

    /**
     * `revoke` targets the stored value, not the presented one.
     *
     * The silent half of the bug: an `UPDATE … WHERE token = <the JWT>` matches no
     * rows and RFC 7009 makes the endpoint answer 200 regardless, so nothing
     * anywhere reports that the revocation did not happen.
     */
    public function testRevocationTargetsTheStoredValue(): void
    {
        // Arrange
        $jwt = $this->jwt(['jti' => 'the-opaque-identifier']);
        $controller = $this->controller(['the-opaque-identifier' => ['status' => 1]]);

        // Act
        $stored = $controller->stored($jwt);

        // Assert
        $this->assertSame('the-opaque-identifier', $stored);
    }

    /**
     * An unmatched token is targeted as presented.
     *
     * The query then matches nothing, exactly as before — a token nobody knows
     * about must not be turned into a guess at some other row.
     */
    public function testAnUnmatchedTokenIsTargetedAsPresented(): void
    {
        // Arrange
        $controller = $this->controller([]);

        // Act / Assert
        $this->assertSame('who-knows', $controller->stored('who-knows'));
    }

    /**
     * A value that is not a JWT yields no `jti`, and is not parsed as one.
     *
     * @param string $value Something that is not a JWT
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonJwtValues')]
    public function testANonJwtYieldsNoJti(string $value): void
    {
        // Arrange
        $controller = $this->controller([]);

        // Act / Assert
        $this->assertNull($controller->jti($value));
    }

    /** @return array<string, array{0: string}> */
    public static function nonJwtValues(): array
    {
        return [
            'opaque'        => ['a1cad19e6d19f4769f4a3a0dc'],
            'empty'         => [''],
            'two segments'  => ['header.payload'],
            'four segments' => ['a.b.c.d'],
            'not base64'    => ['!!!.???.***'],
        ];
    }

    /**
     * A JWT whose payload is not an object, or carries no `jti`, yields null.
     *
     * A malformed token must not become a null key that matches a row with a null
     * token column.
     */
    public function testAJwtWithoutAUsableJtiYieldsNull(): void
    {
        // Arrange
        $controller = $this->controller([]);

        // Act / Assert
        $this->assertNull($controller->jti($this->jwt(['sub' => 'no-jti-here'])));
        $this->assertNull($controller->jti($this->jwt(['jti' => ''])));
        $this->assertNull($controller->jti($this->jwt(['jti' => 12345])));
    }
}

/** Oauth with the token lookup replaced, and the resolution helpers exposed. */
class TokenResolvingOauth extends Oauth
{
    /** @var array<string, array<string, mixed>> Stored value => row */
    public array $rows = [];

    /** @var list<string> Values the lookup was asked about, in order */
    public array $lookups = [];

    public function find(string $token): ?array
    {
        return $this->findIntrospectableToken($token);
    }

    public function stored(string $token): string
    {
        return $this->resolveStoredTokenValue($token);
    }

    public function jti(string $token): ?string
    {
        return $this->extractJwtId($token);
    }

    protected function selectTokenRow(string $stored): ?array
    {
        $this->lookups[] = $stored;

        return $this->rows[$stored] ?? null;
    }
}
