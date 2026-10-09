<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

use Pramnos\Auth\Application;
use Pramnos\Auth\JWT;
use Pramnos\Database\Database;

/**
 * A JWT an application signed with its own key, presented at the token endpoint (RFC 7523).
 *
 * Two uses, one check. As client authentication (`private_key_jwt`) the subject is the client
 * itself; as the jwt-bearer grant the subject is the user the client acts for. Everything else is
 * the same, and is checked here:
 *
 * - the signature, RS256/384/512 or ES256/384/512, against the application's `public_key`, or
 *   the keys its `jwks_uri` publishes;
 * - `iss` is the client id, and `aud` this server's token endpoint or its issuer — so an
 *   assertion made for another server that trusts the same key is refused here;
 * - `exp` and `iat` are present, and the assertion lives at most {@see MAX_LIFETIME} seconds;
 * - `jti` is present and has not been presented before by this client. Remembered until the
 *   assertion's own `exp`, so an assertion caught in transit cannot be replayed.
 *
 * The subject is the caller's to check, since what it must be differs between the two uses.
 */
class JwtAssertion
{
    /** The longest an assertion may live, `exp - iat`, in seconds. */
    public const MAX_LIFETIME = 300;

    /** Asymmetric only: the key is the application's public one, so HMAC would sign with it. */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'];

    /** The largest JWKS document read. */
    private const JWKS_MAX_BYTES = 262144;

    /**
     * @param Database      $database Where the presented `jti`s are remembered
     * @param \Closure|null $fetch    fn(string $url): string|false — reads a `jwks_uri`. Defaults
     *                                to {@see \Pramnos\Security\OutboundUrl::fetch()}, which refuses
     *                                private addresses.
     */
    public function __construct(
        private readonly Database $database,
        private readonly ?\Closure $fetch = null
    ) {
    }

    /**
     * Verify an assertion from this client.
     *
     * @param string      $assertion The compact JWT
     * @param Application $client    The application that claims to have signed it
     * @return object The verified claims
     * @throws \UnexpectedValueException with a reason a client developer can act on
     */
    public function verify(string $assertion, Application $client): object
    {
        $clientId = (string) $client->getClientIdentifier();
        $keys     = $this->keysOf($client);
        if ($keys === null) {
            throw new \UnexpectedValueException('The client has no public key or JWKS to verify the assertion with.');
        }

        try {
            $claims = JWT::decode($assertion, $keys, self::ALGORITHMS);
        } catch (\Throwable $e) {
            throw new \UnexpectedValueException('The assertion is not validly signed by the client, or has expired: ' . $e->getMessage());
        }

        if (($claims->iss ?? null) !== $clientId) {
            throw new \UnexpectedValueException('The assertion\'s iss must be the client_id.');
        }
        $audience = $claims->aud ?? null;
        if (array_intersect(is_array($audience) ? $audience : [$audience], self::audiences()) === []) {
            throw new \UnexpectedValueException('The assertion\'s aud must be ' . self::audiences()[0] . '.');
        }
        if (!is_int($claims->exp ?? null) || !is_int($claims->iat ?? null)) {
            throw new \UnexpectedValueException('The assertion needs exp and iat.');
        }
        if ($claims->exp - $claims->iat > self::MAX_LIFETIME) {
            throw new \UnexpectedValueException('The assertion may live at most ' . self::MAX_LIFETIME . ' seconds (exp - iat).');
        }
        if (!is_string($claims->jti ?? null) || $claims->jti === '') {
            throw new \UnexpectedValueException('The assertion needs a jti.');
        }
        if (!$this->firstUse($clientId, $claims->jti, $claims->exp)) {
            throw new \UnexpectedValueException('This assertion\'s jti has been used before.');
        }

        return $claims;
    }

    /**
     * The audiences an assertion for this server may name: its token endpoint, or its issuer.
     *
     * @return list<string> The token endpoint first — what an error message asks for
     */
    public static function audiences(): array
    {
        $issuer = rtrim(defined('sURL') ? (string) sURL : '', '/');

        return [$issuer . '/oauth/token', $issuer, $issuer . '/'];
    }

    /**
     * The keys to verify with: the registered public key, or the JWKS keyed by `kid`.
     *
     * A JWKS with one key and no `kid` is used as that key, since the header then names none.
     *
     * @return string|array<string, string>|null
     */
    protected function keysOf(Application $client): string|array|null
    {
        if (trim((string) ($client->public_key ?? '')) !== '') {
            return (string) $client->public_key;
        }
        $uri = trim((string) ($client->jwks_uri ?? ''));
        if ($uri === '') {
            return null;
        }

        $body = $this->fetch !== null
            ? ($this->fetch)($uri)
            : \Pramnos\Security\OutboundUrl::fetch($uri, self::JWKS_MAX_BYTES, $reason, 5);
        $set  = is_string($body) ? json_decode($body, true) : null;

        $keys = [];
        $anonymous = [];
        foreach (is_array($set['keys'] ?? null) ? $set['keys'] : [] as $jwk) {
            $pem = is_array($jwk) ? self::pemOf($jwk) : null;
            if ($pem === null) {
                continue;
            }
            if (is_string($jwk['kid'] ?? null) && $jwk['kid'] !== '') {
                $keys[$jwk['kid']] = $pem;
            } else {
                $anonymous[] = $pem;
            }
        }

        if ($keys === [] && count($anonymous) === 1) {
            return $anonymous[0];
        }

        return $keys === [] ? null : $keys;
    }

    /**
     * A public JWK as PEM, or null for anything that is not an RSA or EC signing key.
     *
     * @param array<string, mixed> $jwk
     */
    private static function pemOf(array $jwk): ?string
    {
        if (($jwk['use'] ?? 'sig') !== 'sig') {
            return null;
        }

        try {
            $key = new \Jose\Component\Core\JWK(array_intersect_key($jwk, array_flip(['kty', 'n', 'e', 'crv', 'x', 'y'])));

            return match ($jwk['kty'] ?? '') {
                'RSA'   => \Jose\Component\Core\Util\RSAKey::createFromJWK($key)->toPEM(),
                'EC'    => \Jose\Component\Core\Util\ECKey::convertPublicKeyToPEM($key),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Remember a `jti` until the assertion expires; false when this client presented it already.
     *
     * The insert is the check: two requests racing with one assertion cannot both succeed, since
     * the key is unique. A store that cannot be written refuses the assertion rather than letting
     * every replay through.
     */
    protected function firstUse(string $clientId, string $jti, int $expires): bool
    {
        $table = 'authserver.jwt_replay_prevention';
        $key   = hash('sha256', $clientId . "\n" . $jti);

        try {
            $this->database->queryBuilder()->table($table)
                ->where('expires_at', '<', date('Y-m-d H:i:s'))
                ->delete();

            $this->database->queryBuilder()->table($table)->insert([
                'jti'        => $key,
                'expires_at' => date('Y-m-d H:i:s', $expires),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
