<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api;
use Pramnos\Http\MiddlewarePipeline;

/**
 * The two ways an application gets a say in its own API's authentication.
 *
 * `_executeCore()` built the pipeline — CORS, JSON, `ApiAuthMiddleware` — with no hook, so
 * an application could neither add middleware nor declare a route public. Two consequences,
 * and they are the same gap seen twice:
 *
 * 1. **Every route needed an `apiKey` before routing happened.** An endpoint whose whole
 *    job is to be reached by a caller with no credential yet — a pairing handshake, an
 *    inbound webhook, a device-code poll — could not exist.
 * 2. **`Authorization: Bearer` was claimed.** The middleware reads it as an access-token
 *    JWT and answers `InvalidAccessToken` before the endpoint runs, so an application
 *    could not define a scheme of its own on the header every HTTP client already knows
 *    how to send.
 *
 * The seam left was `checkApiKey()`, which is handed the key and nothing else — so the
 * workaround was an application reading `$_SERVER['REQUEST_URI']` from inside a method
 * about a key, to work out which route it was on.
 */
#[CoversClass(Api::class)]
class ApiPipelineSeamsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTP_APIKEY'], $_SERVER['HTTP_ACCESSTOKEN']);
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\Request::resetInstance();
        unset($_SESSION['usertoken']);
    }

    /**
     * What the pipeline wrote to the raw document.
     *
     * `exec()` does not return the response — it hands it to the document, which is what
     * the front controller renders. Read from there, and cleared so one test's answer is
     * not the next one's.
     */
    private function documentContent(): string
    {
        $doc     = \Pramnos\Framework\Factory::getDocument('raw');
        $content = (string) $doc->getContent();

        $doc->setContent('');

        return $content;
    }

    /**
     * `public_api_paths` in `app.php` reaches the middleware.
     */
    public function testPublicPathsAreReadFromTheApplicationConfiguration(): void
    {
        // Arrange
        $api = new class extends Api {
            public $applicationInfo = [
                'name'             => 'test',
                'public_api_paths' => ['/1.0/wordpress/pair', '/1.0/hooks/*'],
            ];

            public function __construct()
            {
            }

            /** @return list<string> */
            public function exposePublicApiPaths(): array
            {
                return $this->publicApiPaths();
            }
        };

        // Act
        $paths = $api->exposePublicApiPaths();

        // Assert
        $this->assertSame(['/1.0/wordpress/pair', '/1.0/hooks/*'], $paths);
    }

    /**
     * An application that declares none gets none.
     *
     * The default decides what every existing installation does, and it must be "nothing
     * is open".
     */
    public function testAnApplicationThatDeclaresNoneGetsNone(): void
    {
        // Arrange
        $api = new class extends Api {
            public $applicationInfo = ['name' => 'test'];

            public function __construct()
            {
            }

            /** @return list<string> */
            public function exposePublicApiPaths(): array
            {
                return $this->publicApiPaths();
            }
        };

        // Act + Assert
        $this->assertSame([], $api->exposePublicApiPaths());
    }

    /**
     * Middleware piped by the application runs **before** authentication.
     *
     * The position is the whole point: it is what lets an application answer a request
     * itself, or take `Authorization` before `ApiAuthMiddleware` reads it as a JWT.
     *
     * Asserted by consequence rather than by inspecting the pipeline — there is no API
     * key on this request, so without the hook the answer would be `APIKeyMissing`.
     */
    public function testApplicationMiddlewareRunsBeforeAuthentication(): void
    {
        // Arrange
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';

            public function __construct()
            {
            }

            protected function configureApiPipeline(MiddlewarePipeline $pipeline): void
            {
                $pipeline->pipe(new class implements \Pramnos\Http\MiddlewareInterface {
                    public function handle(\Pramnos\Http\Request $request, callable $next): mixed
                    {
                        // Answers the request itself, which it can only do from in front
                        // of the authentication middleware.
                        return 'handled by the application';
                    }
                });
            }
        };

        $api->database = $this->createMock(\Pramnos\Database\Database::class);
        $_SESSION['usertoken'] = new class {
            public $tokentype = 'api';
            public $lastActionId = 1;
            public function addAction() {}
            public function updateAction($id, $status, $time, $record) {}
        };

        // Act — exec() is where the pipeline is built and run; _executeCore() is the
        // callback at the end of it, so calling that directly would skip the whole stack.
        $api->exec('test');
        $written = $this->documentContent();

        // Assert
        $this->assertStringContainsString('handled by the application', $written);
        $this->assertStringNotContainsString('APIKeyMissing', $written);
    }

    /**
     * Without an override, the pipeline is what it always was.
     *
     * The control: a default hook that piped anything, or that swallowed the request,
     * would change every existing API.
     */
    public function testWithoutAnOverrideAuthenticationStillRuns(): void
    {
        // Arrange — same shape, no override
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';

            public function __construct()
            {
            }
        };

        $api->database = $this->createMock(\Pramnos\Database\Database::class);
        $_SESSION['usertoken'] = new class {
            public $tokentype = 'api';
            public $lastActionId = 1;
            public function addAction() {}
            public function updateAction($id, $status, $time, $record) {}
        };

        // Act
        $api->exec('test');

        // Assert
        $this->assertStringContainsString('APIKeyMissing', $this->documentContent());
    }

    /**
     * A declared-public endpoint is reachable — through the request the pipeline uses.
     *
     * The symptom reported: every request to a path in `public_api_paths` answered
     * **403 APIKeyMissing under a test runner**, while the same endpoint answered
     * correctly in production.
     *
     * `Factory::getRequest()` cached its answer in a **function static**, which
     * `Request::resetInstance()` cannot reach — it clears `Request::$instance` and the
     * derived statics, and documents itself as doing exactly that. So after a reset there
     * were two request objects, and the stale one was what `Api::exec()` handed to the
     * pipeline. `ApiAuthMiddleware::isPublicPath()` reads the request's *own* URI and
     * treats an empty one as no match — deliberately — and under a test runner the
     * bootstrap's request had no `REQUEST_URI` at all, so its own URI was `''` for ever.
     *
     * The quiet part is what makes it worth a test: an application gets a 403 it cannot
     * explain on an endpoint it declared open, and the natural next move is to widen the
     * declaration — a security change made to fix a harness artefact.
     */
    public function testADeclaredPublicEndpointIsReachedThroughTheFactorysRequest(): void
    {
        // Arrange — the address, then the reset, which is the order a test writes
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/1.0/wordpress/pair';
        \Pramnos\Http\Request::resetInstance();

        $api = new class extends Api {
            public $database;
            public $applicationInfo = [
                'name'             => 'test',
                'public_api_paths' => ['/1.0/wordpress/pair'],
            ];
            public $controller = 'test';
            public $action = 'test';

            public function __construct()
            {
            }
        };

        $api->database = $this->createMock(\Pramnos\Database\Database::class);
        $_SESSION['usertoken'] = new class {
            public $tokentype = 'api';
            public $lastActionId = 1;
            public function addAction() {}
            public function updateAction($id, $status, $time, $record) {}
        };

        // Act — no API key anywhere
        unset($_SERVER['HTTP_APIKEY']);
        $api->exec('test');
        $written = $this->documentContent();

        // Assert
        $this->assertStringNotContainsString(
            'APIKeyMissing',
            $written,
            'the pipeline was handed a stale request, so the declared path matched nothing'
        );

        // Put the process back.
        if ($savedUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $savedUri;
        }
        \Pramnos\Http\Request::resetInstance();
    }

    /**
     * The factory hands back the request the reset produced, not the one before it.
     *
     * The invariant underneath the test above, asserted directly: **one cache, one
     * owner.** A second cache in another class is a second answer to "what is this
     * request", and the two disagree exactly when somebody resets — which is to say, in
     * every test that builds a request of its own.
     */
    public function testTheFactoryFollowsAResetRatherThanHoldingItsOwnCopy(): void
    {
        // Arrange
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/first/address';
        \Pramnos\Http\Request::resetInstance();
        $before = \Pramnos\Framework\Factory::getRequest();

        // Act — what any test does between two cases
        $_SERVER['REQUEST_URI'] = '/second/address';
        \Pramnos\Http\Request::resetInstance();
        $after = \Pramnos\Framework\Factory::getRequest();

        // Assert
        $this->assertSame('first/address', $before->ownRequestUri());
        $this->assertSame('second/address', $after->ownRequestUri());
        $this->assertNotSame($before, $after, 'the factory kept a copy the reset could not reach');

        // And within one request it is still a singleton — the cache moved, it did not go.
        $this->assertSame($after, \Pramnos\Framework\Factory::getRequest());

        if ($savedUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $savedUri;
        }
        \Pramnos\Http\Request::resetInstance();
    }
}
