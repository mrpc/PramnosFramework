<?php

declare(strict_types=1);

namespace Pramnos\Auth\Health;

use Pramnos\Auth\OAuth2\OAuth2ServerFactory;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Can this authorization server still sign and verify a token?
 *
 * The built-in checks cover the database, Redis, disk and memory — what any
 * application needs. None of them notices a missing or unusable signing key,
 * which is the one failure that leaves an authorization server answering every
 * page green and refusing every token request. `/health/check` said `ok` while
 * `/oauth/token` returned 500, and nothing in the report pointed at the key.
 *
 * Registered automatically by `AuthServerServiceProvider` when the `authserver`
 * feature is enabled, so an application does not have to know it exists.
 *
 * ## Why it does more than `file_exists()`
 *
 * Each step below is a state in which the key file is present and the server
 * still cannot issue a token:
 *
 * 1. **openssl missing** — nothing can sign, whatever is on disk.
 * 2. **The library missing** — nothing assembles a grant.
 * 3. **Unreadable** — a key written as root, or `chmod 0600` to another owner.
 *    This is the common one after a deploy that ran as the wrong user.
 * 4. **Unparseable** — a truncated write, or a PEM that lost its trailing
 *    newline. `file_exists()` is true for all of these.
 * 5. **Mismatched pair** — a private key regenerated without its public half.
 *    Every token signs cleanly and no relying party can verify one, so the
 *    failure surfaces in somebody else's application.
 * 6. **Too short** — a 1024-bit key signs RS256 and is below what the algorithm
 *    should be used with. Reported as degraded rather than down: the server
 *    works, and saying so is more useful than refusing to distinguish.
 *
 * The sign-and-verify round trip in step 5 is the reason this check is worth
 * more than the sum of its file tests. It is one 5-byte signature over a
 * constant, so the cost is negligible next to what it rules out.
 */
class SigningKeysCheck implements HealthCheck
{
    private string $privateKeyPath;
    private string $publicKeyPath;

    /**
     * @param string|null $privateKeyPath Defaults to the path the OAuth2 factory signs with
     * @param string|null $publicKeyPath  Defaults to the path the JWKS endpoint publishes
     */
    public function __construct(
        ?string $privateKeyPath = null,
        ?string $publicKeyPath = null,
    ) {
        $this->privateKeyPath = $privateKeyPath ?? OAuth2ServerFactory::defaultPrivateKeyPath();
        $this->publicKeyPath  = $publicKeyPath ?? OAuth2ServerFactory::defaultPublicKeyPath();
    }

    public function getName(): string
    {
        return 'signing_keys';
    }

    public function run(): HealthCheckResult
    {
        $name = $this->getName();

        // @codeCoverageIgnoreStart
        // Neither branch is reachable from a suite that runs in an environment
        // with openssl and the library present — which is every environment the
        // suite runs in. They exist for the deployment that is missing one.
        if (!extension_loaded('openssl')) {
            return HealthCheckResult::down($name, 'The openssl extension is not loaded, so no token can be signed');
        }

        if (!class_exists(\League\OAuth2\Server\AuthorizationServer::class)) {
            return HealthCheckResult::down($name, 'The OAuth2 server library is not installed');
        }
        // @codeCoverageIgnoreEnd

        foreach (['private' => $this->privateKeyPath, 'public' => $this->publicKeyPath] as $which => $path) {
            if (!is_file($path) || !is_readable($path)) {
                return HealthCheckResult::down(
                    $name,
                    'The ' . $which . ' signing key is missing or unreadable'
                );
            }
        }

        // Parsed, not stat()'d: a truncated key is a file that exists.
        $private = @openssl_pkey_get_private((string) file_get_contents($this->privateKeyPath));
        if ($private === false) {
            return HealthCheckResult::down($name, 'The private signing key is present but cannot be parsed');
        }

        $public = @openssl_pkey_get_public((string) file_get_contents($this->publicKeyPath));
        if ($public === false) {
            return HealthCheckResult::down($name, 'The public signing key is present but cannot be parsed');
        }

        // A pair that does not match verifies nothing it signed — and the
        // failure lands in the relying party, not here.
        $signature = '';
        if (
            !@openssl_sign('pramnos-health', $signature, $private, OPENSSL_ALGO_SHA256)
            || @openssl_verify('pramnos-health', $signature, $public, OPENSSL_ALGO_SHA256) !== 1
        ) {
            return HealthCheckResult::down($name, 'The private and public signing keys do not match');
        }

        $bits = (int) ((openssl_pkey_get_details($private) ?: [])['bits'] ?? 0);

        if ($bits > 0 && $bits < 2048) {
            return HealthCheckResult::degraded(
                $name,
                'The signing key is ' . $bits . ' bits; RS256 should not be used below 2048',
                ['bits' => $bits]
            );
        }

        return HealthCheckResult::ok($name, 'Signing key pair present and usable', ['bits' => $bits]);
    }
}
