<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\ApiAuthMiddleware;
use Pramnos\Http\Request;

/**
 * A token the installation's OAuth server issued stands for the API key: its client is the
 * calling application — a public client has no secret to send — and a client switched off is
 * refused. A token that does not verify, or one signed with the shared HS256 key, names no
 * client, and the key is asked for as before.
 */
#[CoversClass(ApiAuthMiddleware::class)]
class ApiAuthOauthClientTest extends TestCase
{
    /** The installation's key pair, in a scratch file. */
    private string $publicKey = '';

    /** Its private half. */
    private string $privateKey = '';

    /** Headers cleared; a key pair made. */
    protected function setUp(): void
    {
        foreach (['HTTP_APIKEY', 'HTTP_ACCESSTOKEN', 'HTTP_AUTHORIZATION', 'HTTP_USERAUTH', 'HTTP_X_CSRF_TOKEN'] as $header) {
            unset($_SERVER[$header]);
        }
        $_SESSION = [];
        openssl_pkey_export($key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $this->privateKey);
        $this->publicKey = (string) tempnam(sys_get_temp_dir(), 'pk');
        file_put_contents($this->publicKey, openssl_pkey_get_details($key)['key']);
    }

    /** The scratch file and the headers gone. */
    protected function tearDown(): void
    {
        @unlink($this->publicKey);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        \Pramnos\Http\RequestIdentity::reset();
    }

    /** The middleware, with the clients it knows: `desk` active, `off` switched off; the keys it was asked about. */
    private function middleware(array &$asked): ApiAuthMiddleware
    {
        return new ApiAuthMiddleware(
            apiKeyChecker: static function (string $key) use (&$asked): bool {
                $asked[] = $key;

                return $key === 'desk';
            },
            authKey: 'shared-hs256-secret-of-at-least-32-bytes',
            appNamespace: 'Pramnos\\Tests\\Unit\\Http\\Middleware\\OauthClientFixture',
            publicKeyPath: $this->publicKey,
        );
    }

    /** A request with this bearer token, through the middleware. */
    private function call(ApiAuthMiddleware $middleware, string $token): mixed
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        return $middleware->handle(new Request(), static fn (): string => 'reached');
    }

    /**
     * (1) an RS256 token of an active client and no key: let through, the client asked for as the
     * key; (2) of a client switched off: 401; (3) a forged one: the key is missing, 403; (4) an
     * HS256 token, though valid: names no client, 403.
     */
    public function testATokenOfTheOauthServerStandsForTheKey(): void
    {
        // Arrange
        $asked   = [];
        require_once __DIR__ . '/OauthClientFixture/User.php';
        $claims  = ['jti' => 'alice', 'sub' => '7', 'exp' => time() + 3600, 'scopes' => ['conversations']];
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $forger);

        // Act
        $active  = $this->call($this->middleware($asked), \Pramnos\Auth\JWT::encode($claims + ['aud' => 'desk'], $this->privateKey, 'RS256'));
        $who     = \Pramnos\Http\RequestIdentity::user();
        $off     = $this->call($this->middleware($asked), \Pramnos\Auth\JWT::encode($claims + ['aud' => ['off']], $this->privateKey, 'RS256'));
        $forged  = $this->call($this->middleware($asked), \Pramnos\Auth\JWT::encode($claims + ['aud' => 'desk'], $forger, 'RS256'));
        $hs256   = $this->call($this->middleware($asked), \Pramnos\Auth\JWT::encode($claims + ['aud' => 'desk'], 'shared-hs256-secret-of-at-least-32-bytes', 'HS256'));

        // Assert
        $this->assertSame('reached', $active);
        $this->assertSame(7, (int) ($who->userid ?? 0), 'the person the token is theirs');
        $this->assertSame(['desk', 'off'], $asked, 'the client, from its audience, asked about as a key — and only for tokens that verified');
        $this->assertStringContainsString('APIKeyInvalid', (string) $off);
        $this->assertStringContainsString('APIKeyMissing', (string) $forged);
        $this->assertStringContainsString('APIKeyMissing', (string) $hs256, 'the shared key names no client');
    }
}
