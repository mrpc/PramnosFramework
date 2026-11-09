<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastingApi;
use Pramnos\Routing\Router;

/**
 * The channels' authorization under the API (`BroadcastingApi`): mounted at the path chosen,
 * described for the API's document, and answering through the page's own `postAuth` — the
 * protocol's answer as it is, here a malformed request's 400 rather than the API's envelope.
 */
#[CoversClass(BroadcastingApi::class)]
class BroadcastingApiTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        \Pramnos\Http\Request::resetInstance();
    }

    /** The route mounted, described, and answering with the protocol's own response. */
    public function testTheAuthorizationIsUnderTheApi(): void
    {
        // Arrange
        $router = new Router(\Pramnos\Application\Application::getInstance());
        $_POST  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Pramnos\Http\Request::resetInstance();

        // Act
        BroadcastingApi::routes($router, '/realtime/auth');
        $route  = $router->getRoutesWithPermissions()['POST']['/realtime/auth']['route'];
        $answer = $route->execute(null);

        // Assert
        $this->assertSame('Authorize a private or presence channel', $route->getDoc()['summary']);
        $this->assertArrayHasKey('requestBody', $route->getDoc());
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $answer, 'the protocol\'s answer, not the API\'s envelope');
        $this->assertSame(400, $answer->getStatusCode(), 'socket_id and channel_name are required');
    }
}
