<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Cache\Cache;
use Pramnos\Http\Middleware\RateLimitMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\TooManyRequestsException;

/**
 * `POST /oauth/register` — what it refuses before anything is written.
 *
 * Dynamic client registration is how a remote MCP client (Claude.ai, ChatGPT) connects without
 * anybody typing a client id, and it is also an endpoint that lets anybody on the internet write
 * a row. So the interesting half is the refusals: every one of them returns before the database
 * is touched, which is why they can be unit tests. The row that a valid registration writes is
 * asserted against real databases in `DynamicClientRegistrationTest`.
 */
#[CoversClass(Oauth::class)]
class OauthDynamicRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Request::setRawInput(null);
    }

    protected function tearDown(): void
    {
        Request::setRawInput(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * The controller without its constructor's side effects (RSA keys, CORS headers), with
     * registration switched on or off and a rate limiter over an in-memory cache.
     */
    private function controller(bool $open = true, int $limit = 100): Oauth
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->applicationInfo = ['oauth_dynamic_registration' => $open];

        $controller = new class ($limit) extends Oauth {
            public function __construct(private int $limit)
            {
            }

            protected function registrationLimiter(): RateLimitMiddleware
            {
                return new RateLimitMiddleware($this->limit, 3600, 'test-register:', new Cache(null, null, 'array'));
            }
        };
        $controller->application = $app;

        return $controller;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function register(Oauth $controller, mixed $body): array
    {
        Request::setRawInput(is_string($body) ? $body : (string) json_encode($body));
        $response = $controller->register();

        return [$response->getStatusCode(), (array) json_decode($response->getBody(), true)];
    }

    /**
     * Off unless the operator turns it on.
     *
     * The endpoint creates rows for strangers, and that decision belongs in `app.php`, not in
     * a `composer update`. An installation that never opted in answers 404, as though the
     * address did not exist.
     */
    public function testItIsClosedUnlessTheApplicationOptsIn(): void
    {
        // Arrange
        $controller = $this->controller(open: false);

        // Act
        [$status, $body] = $this->register($controller, ['redirect_uris' => ['https://claude.ai/cb']]);

        // Assert
        $this->assertSame(404, $status);
        $this->assertFalse(Oauth::registrationIsOpen(null), 'no application means closed');
    }

    /**
     * Registration is a POST; anything else is told so.
     */
    public function testAGetIsRefused(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Act
        [$status, $body] = $this->register($this->controller(), '');

        // Assert
        $this->assertSame(405, $status);
        $this->assertSame('invalid_request', $body['error']);
    }

    /**
     * A confidential client may not register itself.
     *
     * That is the line that keeps open registration from being open issuance of machine
     * tokens: a confidential client could use `client_credentials`, which needs no person. The
     * RFC's default auth method is `client_secret_basic`, so a body that says nothing is
     * refused too — the client has to ask to be public.
     */
    #[DataProvider('confidentialMethods')]
    public function testOnlyAPublicClientMayRegister(?string $method): void
    {
        // Arrange
        $metadata = ['redirect_uris' => ['https://claude.ai/cb']];
        if ($method !== null) {
            $metadata['token_endpoint_auth_method'] = $method;
        }

        // Act
        [$status, $body] = $this->register($this->controller(), $metadata);

        // Assert
        $this->assertSame(400, $status);
        $this->assertSame('invalid_client_metadata', $body['error']);
    }

    /** @return array<string, array{0:?string}> */
    public static function confidentialMethods(): array
    {
        return [
            'unstated (RFC default)' => [null],
            'client_secret_basic'    => ['client_secret_basic'],
            'client_secret_post'     => ['client_secret_post'],
            'private_key_jwt'        => ['private_key_jwt'],
        ];
    }

    /**
     * Metadata that is not what a public code-flow client needs is refused as a whole.
     *
     * RFC 7591 lets a server replace values it will not honour; refusing is the safer reading
     * for grants, because a client that believes it may use `client_credentials` is a client
     * built on a promise this server does not make.
     */
    #[DataProvider('badMetadata')]
    public function testMetadataOutsideThePublicCodeFlowIsRefused(mixed $body, string $error): void
    {
        // Act
        [$status, $answer] = $this->register($this->controller(), $body);

        // Assert
        $this->assertSame(400, $status);
        $this->assertSame($error, $answer['error']);
    }

    /** @return array<string, array{0:mixed,1:string}> */
    public static function badMetadata(): array
    {
        $ok = ['token_endpoint_auth_method' => 'none', 'redirect_uris' => ['https://claude.ai/cb']];

        return [
            'not JSON'                  => ['{nope', 'invalid_client_metadata'],
            'a JSON string'             => ['"hello"', 'invalid_client_metadata'],
            'client_credentials grant'  => [$ok + ['grant_types' => ['client_credentials']], 'invalid_client_metadata'],
            'password grant'            => [$ok + ['grant_types' => ['authorization_code', 'password']], 'invalid_client_metadata'],
            'grant_types not a list'    => [$ok + ['grant_types' => 'authorization_code'], 'invalid_client_metadata'],
            'token response type'       => [$ok + ['response_types' => ['token']], 'invalid_client_metadata'],
            'no redirect URIs'          => [['token_endpoint_auth_method' => 'none'], 'invalid_redirect_uri'],
            'empty redirect URIs'       => [['redirect_uris' => []] + $ok, 'invalid_redirect_uri'],
            'six redirect URIs'         => [['redirect_uris' => array_fill(0, 6, 'https://a.test/cb')] + $ok, 'invalid_redirect_uri'],
        ];
    }

    /**
     * Only callbacks that prove something about who registered them.
     *
     * `https` anywhere and `http` only on loopback, where a desktop client listens (RFC 8252).
     * A custom scheme is refused although an operator may register one, because any app on a
     * phone can claim any scheme. A comma or a space would split the stored list; a fragment
     * is forbidden by RFC 6749 §3.1.2.
     */
    #[DataProvider('badRedirects')]
    public function testARedirectUriThatProvesNothingIsRefused(mixed $uri): void
    {
        // Act
        [$status, $body] = $this->register(
            $this->controller(),
            ['token_endpoint_auth_method' => 'none', 'redirect_uris' => [$uri]]
        );

        // Assert
        $this->assertSame(400, $status);
        $this->assertSame('invalid_redirect_uri', $body['error']);
    }

    /** @return array<string, array{0:mixed}> */
    public static function badRedirects(): array
    {
        return [
            'plain http on a host'  => ['http://evil.test/cb'],
            'custom scheme'         => ['myapp://oauth/cb'],
            'javascript'            => ['javascript://x/%0aalert(1)'],
            'relative path'         => ['/callback'],
            'a comma'               => ['https://a.test/cb,https://b.test/cb'],
            'a space'               => ['https://a.test/c b'],
            'a fragment'            => ['https://a.test/cb#x'],
            'not a string'          => [42],
            'too long'              => ['https://a.test/' . str_repeat('a', 500)],
        ];
    }

    /**
     * The production limiter: twenty registrations an hour per address.
     *
     * Generous for a person connecting an assistant or two, and a wall for a script. Read
     * through reflection because it is a seam the tests above replace.
     */
    public function testTheDefaultLimiterIsTwentyAnHour(): void
    {
        // Arrange
        $limiter = (new \ReflectionMethod(Oauth::class, 'registrationLimiter'))
            ->invoke((new \ReflectionClass(Oauth::class))->newInstanceWithoutConstructor());

        // Assert
        $this->assertInstanceOf(RateLimitMiddleware::class, $limiter);
        $this->assertSame(20, (new \ReflectionProperty($limiter, 'maxRequests'))->getValue($limiter));
        $this->assertSame(3600, (new \ReflectionProperty($limiter, 'perSeconds'))->getValue($limiter));
    }

    /**
     * One address cannot fill the table.
     *
     * Each registration writes a row, so the endpoint is limited per address; past the limit
     * the limiter throws, and the application renders that as 429 with `Retry-After`. Checked
     * with a limit of zero so the refusal comes before any database work.
     */
    public function testRegistrationIsRateLimited(): void
    {
        // Arrange
        $controller = $this->controller(limit: 0);

        // Assert
        $this->expectException(TooManyRequestsException::class);

        // Act
        $this->register($controller, ['token_endpoint_auth_method' => 'none', 'redirect_uris' => ['https://a.test/cb']]);
    }
}
