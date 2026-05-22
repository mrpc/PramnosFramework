<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

use Pramnos\Auth\OAuth2\Repositories\AccessTokenRepository;
use Pramnos\Auth\OAuth2\Repositories\AuthCodeRepository;
use Pramnos\Auth\OAuth2\Repositories\ClientRepository;
use Pramnos\Auth\OAuth2\Repositories\RefreshTokenRepository;
use Pramnos\Auth\OAuth2\Repositories\ScopeRepository;
use Pramnos\Auth\OAuth2\Repositories\UserRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;

/**
 * OAuth2 Server Factory
 *
 * Central factory for the league/oauth2-server Authorization and Resource
 * servers. Wires together the six repositories and enables the four grant
 * types required by PramnosFramework:
 *
 * - ClientCredentials: machine-to-machine (access token 1h)
 * - Password:          legacy ROPC flow   (access 1h, refresh 1 month)
 * - AuthorizationCode: web/mobile apps    (code 10min, access 1h, refresh 1 month)
 * - RefreshToken:      token refresh      (new refresh 1 month)
 *
 * RSA keys are expected at ROOT/app/keys/private.key and public.key.
 * Call generateKeyPair() during first-time setup (or pramnos init).
 *
 * @package PramnosFramework
 */
class OAuth2ServerFactory
{
    private string $privateKeyPath;
    private string $publicKeyPath;
    private string $encryptionKey;
    private \Pramnos\Application\Controller $controller;

    public function __construct(
        \Pramnos\Application\Controller $controller,
        ?string $privateKeyPath  = null,
        ?string $publicKeyPath   = null,
        ?string $encryptionKey   = null
    ) {
        $this->controller     = $controller;
        $this->privateKeyPath = $privateKeyPath ?? ROOT . '/app/keys/private.key';
        $this->publicKeyPath  = $publicKeyPath  ?? ROOT . '/app/keys/public.key';
        // In production this must be a fixed key from secure config, not randomly generated.
        $this->encryptionKey  = $encryptionKey  ?? $this->loadOrGenerateEncryptionKey();
    }

    /**
     * Build and return the Authorization Server with all four grant types.
     */
    public function createAuthorizationServer(): AuthorizationServer
    {
        $clientRepo       = new ClientRepository($this->controller);
        $scopeRepo        = new ScopeRepository();
        $accessTokenRepo  = new AccessTokenRepository($this->controller);
        $authCodeRepo     = new AuthCodeRepository($this->controller);
        $refreshTokenRepo = new RefreshTokenRepository($this->controller);
        $userRepo         = new UserRepository();

        $server = new AuthorizationServer(
            $clientRepo,
            $accessTokenRepo,
            $scopeRepo,
            new CryptKey($this->privateKeyPath),
            $this->encryptionKey
        );

        $server->enableGrantType(
            new ClientCredentialsGrant(),
            new \DateInterval('PT1H')
        );

        $passwordGrant = new PasswordGrant($userRepo, $refreshTokenRepo);
        $passwordGrant->setRefreshTokenTTL(new \DateInterval('P1M'));
        $server->enableGrantType($passwordGrant, new \DateInterval('PT1H'));

        $authCodeGrant = new AuthCodeGrant(
            $authCodeRepo,
            $refreshTokenRepo,
            new \DateInterval('PT10M')
        );
        $authCodeGrant->setRefreshTokenTTL(new \DateInterval('P1M'));
        $server->enableGrantType($authCodeGrant, new \DateInterval('PT1H'));

        $refreshTokenGrant = new RefreshTokenGrant($refreshTokenRepo);
        $refreshTokenGrant->setRefreshTokenTTL(new \DateInterval('P1M'));
        $server->enableGrantType($refreshTokenGrant, new \DateInterval('PT1H'));

        return $server;
    }

    /**
     * Build and return the Resource Server for validating access tokens.
     */
    public function createResourceServer(): ResourceServer
    {
        return new ResourceServer(
            new AccessTokenRepository($this->controller),
            new CryptKey($this->publicKeyPath)
        );
    }

    /**
     * Generate an RSA 2048-bit key pair at the configured paths.
     *
     * Creates the keys directory (chmod 0700) if it does not exist.
     * Skips generation when both files already exist to avoid destroying
     * tokens signed with the previous private key.
     */
    public function generateKeyPair(): void
    {
        $keysDir = dirname($this->privateKeyPath);

        if (!is_dir($keysDir)) {
            if (!@mkdir($keysDir, 0750, true)) {
                \Pramnos\Logs\Logger::log(
                    'OAuth2: cannot create keys directory: ' . $keysDir
                    . '. Run `pramnos init` or create it manually with correct permissions.'
                );
                return;
            }
        }

        if (file_exists($this->privateKeyPath) && file_exists($this->publicKeyPath)) {
            return;
        }

        $privateKey = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            \Pramnos\Logs\Logger::log('OAuth2: RSA key generation failed: ' . openssl_error_string());
            return;
        }

        openssl_pkey_export($privateKey, $privateKeyPem);

        if (file_put_contents($this->privateKeyPath, $privateKeyPem) === false) {
            \Pramnos\Logs\Logger::log(
                'OAuth2: cannot write private key to ' . $this->privateKeyPath
                . '. Check directory permissions.'
            );
            return;
        }
        @chmod($this->privateKeyPath, 0600);

        $details = openssl_pkey_get_details($privateKey);
        if (file_put_contents($this->publicKeyPath, $details['key']) === false) {
            \Pramnos\Logs\Logger::log(
                'OAuth2: cannot write public key to ' . $this->publicKeyPath
                . '. Check directory permissions.'
            );
            return;
        }
        @chmod($this->publicKeyPath, 0644);
    }

    /**
     * Return the configured private key path.
     */
    public function getPrivateKeyPath(): string
    {
        return $this->privateKeyPath;
    }

    /**
     * Return the configured public key path.
     */
    public function getPublicKeyPath(): string
    {
        return $this->publicKeyPath;
    }

    /**
     * Return the encryption key used for encrypting auth codes and refresh tokens.
     */
    public function getEncryptionKey(): string
    {
        return $this->encryptionKey;
    }

    /**
     * Return the ScopeRepository so applications can add custom scopes.
     */
    public function makeScopeRepository(): ScopeRepository
    {
        return new ScopeRepository();
    }

    /**
     * Load a persistent encryption key from the app settings or generate one.
     *
     * In production this should return a fixed base64-encoded 32-byte key
     * stored in secure configuration (not regenerated on each request).
     */
    private function loadOrGenerateEncryptionKey(): string
    {
        $keyFile = dirname($this->privateKeyPath) . '/encryption.key';

        if (file_exists($keyFile)) {
            return trim((string)file_get_contents($keyFile));
        }

        // First-time setup: generate and persist so all requests share the same key.
        $key = base64_encode(random_bytes(32));
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600);

        return $key;
    }
}
