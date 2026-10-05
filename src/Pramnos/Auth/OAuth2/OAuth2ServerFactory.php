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
 */
class OAuth2ServerFactory
{
    private string $privateKeyPath;
    private string $publicKeyPath;
    private string $encryptionKey;
    private \Pramnos\Application\Controller $controller;
    private ?string $resource = null;

    /** @var array{nonce?: string, auth_time?: int} What an authorization request said, for its code. */
    private array $authorizationContext = [];

    /** @var array{nonce?: string, auth_time?: int} What the code being redeemed carried. */
    private array $redeemedContext = [];

    /**
     * Where the signing key pair lives when nothing says otherwise.
     *
     * Exposed as methods rather than left as expressions inside the constructor
     * because more than one thing needs to know: this factory signs with the
     * keys, and the health check reports on them. A second copy of the same path
     * expression is a copy that can drift, and the drift would show up as a
     * health check reporting on a file the factory does not use.
     *
     * `ROOT` is a runtime constant, so these cannot be class constants.
     */
    public static function defaultPrivateKeyPath(): string
    {
        return ROOT . '/app/keys/private.key';
    }

    /** @see self::defaultPrivateKeyPath() */
    public static function defaultPublicKeyPath(): string
    {
        return ROOT . '/app/keys/public.key';
    }

    public function __construct(
        \Pramnos\Application\Controller $controller,
        ?string $privateKeyPath  = null,
        ?string $publicKeyPath   = null,
        ?string $encryptionKey   = null
    ) {
        $this->controller     = $controller;
        $this->privateKeyPath = $privateKeyPath ?? self::defaultPrivateKeyPath();
        $this->publicKeyPath  = $publicKeyPath  ?? self::defaultPublicKeyPath();
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
        $accessTokenRepo  = new AccessTokenRepository($this->controller, $this->resource);
        $authCodeRepo     = new AuthCodeRepository($this->controller);
        $authCodeRepo->setContext($this->authorizationContext);
        $refreshTokenRepo = new RefreshTokenRepository($this->controller);
        $userRepo         = new UserRepository();

        $server = new AuthorizationServer(
            $clientRepo,
            $accessTokenRepo,
            $scopeRepo,
            new CryptKey($this->privateKeyPath, null, false),
            $this->encryptionKey,
            $this->makeResponseType()
        );

        // The server's lifetimes (`oauth.*_ttl` in app.php); a client with its own has them
        // applied when its tokens are persisted — see TokenLifetimes.
        $access  = TokenLifetimes::interval(TokenLifetimes::access());
        $refresh = TokenLifetimes::interval(TokenLifetimes::refresh());

        $server->enableGrantType(new ClientCredentialsGrant(), $access);

        $passwordGrant = new PasswordGrant($userRepo, $refreshTokenRepo);
        $passwordGrant->setRefreshTokenTTL($refresh);
        $server->enableGrantType($passwordGrant, $access);

        $authCodeGrant = new AuthCodeGrant(
            $authCodeRepo,
            $refreshTokenRepo,
            TokenLifetimes::interval(TokenLifetimes::authCode())
        );
        $authCodeGrant->setRefreshTokenTTL($refresh);
        $server->enableGrantType($authCodeGrant, $access);

        $refreshTokenGrant = new RefreshTokenGrant($refreshTokenRepo);
        $refreshTokenGrant->setRefreshTokenTTL($refresh);
        $server->enableGrantType($refreshTokenGrant, $access);

        return $server;
    }

    /**
     * Issue the tokens of the next authorization server for one resource (RFC 8707).
     *
     * The resource is added to each access token's `aud`, beside the client id. A method
     * rather than a parameter of {@see createAuthorizationServer()}, so an application that
     * overrides that method keeps loading.
     *
     * @param string|null $resource An absolute URI the caller has already validated, or null.
     */
    public function forResource(?string $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Remember what an authorization request said — its `nonce`, when the user authenticated —
     * so the code issued next carries it to the ID token.
     *
     * @param array{nonce?: string, auth_time?: int} $context
     */
    public function withAuthorizationContext(array $context): static
    {
        $this->authorizationContext = array_filter($context, static fn ($v): bool => $v !== '' && $v !== null && $v !== 0);

        return $this;
    }

    /**
     * The code about to be redeemed: read what its authorization request said, for the ID token.
     *
     * The code is League's encrypted payload; decrypted here with the same key only to find the
     * code's id, and its row's `oidc_context`. A code that does not decrypt carries nothing, and
     * League refuses it a moment later.
     */
    public function redeemingCode(string $code): static
    {
        $this->redeemedContext = [];
        try {
            $payload = json_decode(\Defuse\Crypto\Crypto::decryptWithPassword($code, $this->encryptionKey), true);
            $codeId  = is_array($payload) ? (string) ($payload['auth_code_id'] ?? '') : '';
            if ($codeId !== '') {
                $this->redeemedContext = AuthCodeRepository::contextOf($codeId);
            }
        } catch (\Throwable) {
            // Not ours to refuse: the grant does that, with the right error.
        }

        return $this;
    }

    /**
     * The token response: the bearer response with an ID token for `openid`.
     *
     * Protected, so an application that shapes its responses differently can override it.
     */
    protected function makeResponseType(): \League\OAuth2\Server\ResponseTypes\ResponseTypeInterface
    {
        $response = new \Pramnos\Auth\OAuth2\ResponseTypes\IdTokenResponse(
            $this->privateKeyPath,
            defined('sURL') ? (string) \sURL : ''
        );
        $response->setAuthorizationContext($this->redeemedContext);

        return $response;
    }

    /**
     * How far a token's `iat`/`nbf` may be ahead of this server's clock.
     *
     * The same 60 seconds the framework's own JWT middleware allow (`JWT::$leeway`). Without
     * it, a token is refused when the server checking it is a second behind the one that
     * issued it: a resource server on another host, or this one after the clock is corrected
     * backwards — which a virtual machine's clock is, routinely.
     */
    public const CLOCK_LEEWAY_SECONDS = 60;

    /**
     * Build and return the Resource Server for validating access tokens.
     */
    public function createResourceServer(): ResourceServer
    {
        $tokens = new AccessTokenRepository($this->controller);

        return new ResourceServer(
            $tokens,
            new CryptKey($this->publicKeyPath, null, false),
            new \League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator(
                $tokens,
                new \DateInterval('PT' . self::CLOCK_LEEWAY_SECONDS . 'S')
            )
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

        if (@file_put_contents($this->privateKeyPath, $privateKeyPem) === false) {
            \Pramnos\Logs\Logger::log(
                'OAuth2: cannot write private key to ' . $this->privateKeyPath
                . '. Check directory permissions.'
            );
            return;
        }
        @chmod($this->privateKeyPath, 0600);

        $details = openssl_pkey_get_details($privateKey);
        if (@file_put_contents($this->publicKeyPath, $details['key']) === false) {
            \Pramnos\Logs\Logger::log(
                'OAuth2: cannot write public key to ' . $this->publicKeyPath
                . '. Check directory permissions.'
            );
            return;
        }
        @chmod($this->publicKeyPath, 0600);
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
            @mkdir($dir, 0750, true);
        }
        if (@file_put_contents($keyFile, $key) === false) {
            \Pramnos\Logs\Logger::log(
                'OAuth2: cannot write encryption key to ' . $keyFile
                . '. Check directory permissions.'
            );
            return $key;
        }
        @chmod($keyFile, 0600);

        return $key;
    }
}
