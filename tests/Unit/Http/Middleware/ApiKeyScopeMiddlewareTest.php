<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\ApiKeyScopeMiddleware;
use Pramnos\Http\Request;
use Pramnos\Routing\RouteDiscovery;
use Pramnos\Routing\Router;

/**
 * Refusing an API key its application's scopes do not cover.
 *
 * `applications.scope` was saved and never read, so a key issued read-only could write. The
 * matching decides whether a route's declaration means anything; the fail-closed rule decides
 * whether protecting a route leaves it open to every key issued before it was protected.
 */
#[CoversClass(ApiKeyScopeMiddleware::class)]
#[CoversClass(RouteDiscovery::class)]
class ApiKeyScopeMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['HTTP_APIKEY'] = 'the-key';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_APIKEY']);
    }

    /** A check whose key is granted exactly these scopes. */
    private function granting(array $required, ?array $granted): ApiKeyScopeMiddleware
    {
        return new ApiKeyScopeMiddleware($required, static fn (string $key): ?array => $granted);
    }

    /** What the check answers: `'passed'` when it called through, the decoded refusal otherwise. */
    private function answer(ApiKeyScopeMiddleware $check): mixed
    {
        $answer = $check->handle($this->createMock(Request::class), static fn (): string => 'passed');

        return $answer === 'passed' ? $answer : json_decode((string) $answer, true);
    }

    /**
     * A granted scope passes; a missing one is refused with `insufficient_scope`.
     *
     * The case the filing names: a read-only key calling a write route.
     */
    public function testAReadOnlyKeyCannotWrite(): void
    {
        // Act
        $read  = $this->answer($this->granting(['stations:read'], ['stations:read']));
        $write = $this->answer($this->granting(['stations:write'], ['stations:read']));

        // Assert
        $this->assertSame('passed', $read);
        $this->assertSame(403, $write['status']);
        $this->assertSame('insufficient_scope', $write['error']);
        $this->assertStringContainsString('stations:write', $write['message'], 'the refusal names what is missing');
    }

    /**
     * Every required scope must be granted, not any one of them.
     */
    public function testEveryRequiredScopeIsNeeded(): void
    {
        // Act
        $answer = $this->answer($this->granting(['stations:read', 'stats:read'], ['stations:read']));

        // Assert — one of two is not enough
        $this->assertSame('insufficient_scope', $answer['error'] ?? null);
        $this->assertStringNotContainsString('stations:read.', $answer['message'], 'a granted scope was reported missing');
    }

    /**
     * `*` and trailing wildcards cover what they match, and nothing else.
     *
     * The router's own scope matching, so a scope means the same on a key and on a route.
     */
    public function testWildcardsMatchLikeTheRouter(): void
    {
        // Act & Assert
        $this->assertSame('passed', $this->answer($this->granting(['stations:write'], ['*'])));
        $this->assertSame('passed', $this->answer($this->granting(['stations:write'], ['stations:*'])));
        $this->assertSame(
            'insufficient_scope',
            $this->answer($this->granting(['stats:read'], ['stations:*']))['error'] ?? null,
            'a namespace wildcard covered another namespace'
        );
    }

    /**
     * A key with no scopes is refused on a route that declares any.
     *
     * Fail closed: protecting a route must not leave it open to the keys issued before.
     */
    public function testAKeyWithNoScopesIsRefused(): void
    {
        // Act
        $answer = $this->answer($this->granting(['stations:read'], []));

        // Assert
        $this->assertSame('insufficient_scope', $answer['error'] ?? null);
    }

    /**
     * No declared scope, no key, or the application's own key: nothing is checked.
     *
     * A route that declares nothing is unaffected; a request with no key is the application's
     * own page (already admitted on its session); `null` from the resolver is the site key.
     */
    public function testNothingIsCheckedWhereThereIsNothingToCheck(): void
    {
        // Act
        $undeclared = $this->answer($this->granting(['', '  '], []));
        $site       = $this->answer($this->granting(['stations:write'], null));
        unset($_SERVER['HTTP_APIKEY']);
        $noKey      = $this->answer($this->granting(['stations:write'], []));

        // Assert
        $this->assertSame(['passed', 'passed', 'passed'], [$undeclared, $site, $noKey]);
    }

    /**
     * The default resolver treats the site's own key as the application itself.
     *
     * That key is the hash of the site's address, which `Api::checkApiKey()` accepts without an
     * application row; it is not a third party to limit.
     */
    public function testTheSiteKeyAnswersToNoScopeList(): void
    {
        // Arrange
        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }
        $siteKey = md5(str_replace('/api/', '/', (string) sURL));

        // Act
        $granted = ApiKeyScopeMiddleware::grantedScopes($siteKey);

        // Assert
        $this->assertNull($granted);
    }

    /**
     * A discovered route with `apiKeyScopes` gets the check; one without gets nothing.
     */
    public function testDiscoveryAttachesTheCheckToADeclaringRoute(): void
    {
        // Arrange
        $router = new Router(null);

        // Act
        (new RouteDiscovery($router))->discover(
            dirname(__DIR__, 3) . '/fixtures/scoped-routes',
            'PramnosTest\\ScopedRoutes'
        );
        $store = $router->getByName('scoped.store')->getMiddleware();
        $index = $router->getByName('scoped.index')->getMiddleware();

        // Assert
        $this->assertCount(1, $store);
        $this->assertInstanceOf(ApiKeyScopeMiddleware::class, $store[0]);
        $this->assertSame([], $index, 'a route declaring no scope was given a check');
    }
}
