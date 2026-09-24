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
}
