<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Beacon;

/**
 * A device's key to the beacon, for when the primary is down: who it is, signed by the primary with
 * Ed25519. The primary alone holds the private key, so it alone mints them; the beacon holds the
 * public keys, so it verifies them and can make none. Thirty days, renewed every day the primary
 * answers; a person who is no longer active is refused by the beacon within a minute of the next
 * sync, whatever their key says.
 *
 * `base64url(json{u, n, d, jti, iat, exp, kid}) . '.' . base64url(signature)`
 */
final class EmergencyKey
{
    /** How long a key lasts. */
    public const LIFETIME = 30 * 86400;

    /**
     * A new key pair, for `app/beacon.php`: the secret to the primary's `signing_key`, the public to
     * every `public_keys`.
     *
     * @return array{signing_key: string, public_key: string}
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))];
    }

    /** A key for a person's device, signed with the primary's key. */
    public static function mint(int $userId, string $name, string $device, ?int $now = null): string
    {
        $secret = base64_decode((string) Config::value('signing_key', ''), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('The beacon has no signing key here: `signing_key` in app/beacon.php.');
        }
        $now     = $now ?? time();
        $payload = self::encode((string) json_encode(['u' => $userId, 'n' => $name, 'd' => mb_substr($device, 0, 64), 'jti' => bin2hex(random_bytes(8)),
            'iat' => $now, 'exp' => $now + self::LIFETIME, 'kid' => (string) Config::value('kid', '')], JSON_UNESCAPED_UNICODE));

        return $payload . '.' . self::encode(sodium_crypto_sign_detached($payload, $secret));
    }

    /**
     * What a key says, when one of the public keys signed it and it has not expired; else null.
     *
     * @return array{u: int, n: string, d: string, jti: string, iat: int, exp: int, kid: string}|null
     */
    public static function verify(string $key, ?int $now = null): ?array
    {
        $parts = explode('.', trim($key));
        if (count($parts) !== 2) {
            return null;
        }
        $claims = json_decode((string) self::decode($parts[0]), true);
        $public = base64_decode((string) (((array) Config::value('public_keys', []))[$claims['kid'] ?? ''] ?? ''), true);
        $sig    = self::decode($parts[1]);
        if (!is_array($claims) || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === null
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $parts[0], $public)) {
            return null;
        }

        return (int) ($claims['exp'] ?? 0) > ($now ?? time()) ? [
            'u' => (int) $claims['u'], 'n' => (string) $claims['n'], 'd' => (string) $claims['d'], 'jti' => (string) $claims['jti'],
            'iat' => (int) $claims['iat'], 'exp' => (int) $claims['exp'], 'kid' => (string) $claims['kid'],
        ] : null;
    }

    /** Base64url, without padding. */
    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Base64url back to bytes, or null. */
    private static function decode(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
