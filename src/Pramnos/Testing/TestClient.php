<?php

namespace Pramnos\Testing;

use Pramnos\Application\Application;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * An in-memory HTTP client for testing.
 * Bypasses the web server and executes the framework directly.
 */
class TestClient
{
    private Application $app;

    public function __construct(?Application $app = null)
    {
        if ($app === null) {
            $appInstance = Application::getInstance();
            if ($appInstance === null) {
                $this->app = new Application(); // @codeCoverageIgnore — Application is always pre-initialised in tests
            } else {
                $this->app = $appInstance;
            }
            if (!$this->app->initialized) {
                $this->app->init(); // @codeCoverageIgnore — stub apps always have initialized=true
            }
        } else {
            $this->app = $app;
        }
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    /**
     * Submit a form by parsing the DOM for CSRF tokens and action URLs.
     * (Basic implementation — can be expanded)
     */
    public function submitForm(string $buttonText, array $data = []): TestResponse
    {
        // A complete implementation would require the previous Response's HTML
        // For now, this is a placeholder for the API.
        throw new \RuntimeException('submitForm is not yet fully implemented.');
    }

    /**
     * Execute a request and return a TestResponse.
     */
    public function call(string $method, string $uri, array $parameters = [], array $headers = []): TestResponse
    {
        // 1. Setup Superglobals
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $uri;
        
        foreach ($headers as $key => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $_SERVER[$serverKey] = $value;
        }

        $_GET = [];
        $_POST = [];
        $_FILES = [];
        
        $parsed = parse_url($uri);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $_GET);
        }

        if (in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $_POST = $parameters;
            // Also update raw input for Request
            Request::setRawInput(http_build_query($parameters));
        } else {
            Request::setRawInput('');
        }

        // 2. Initialize Request
        $request = new Request();

        // 3. Try Router first
        try {
            $router = Factory::getContainer()->get(\Pramnos\Routing\Router::class);
            if ($router) {
                // @codeCoverageIgnoreStart
                // Router dispatch is only reachable when a Router is bound in the
                // DI container.  Unit tests use stub apps that skip container setup;
                // this block is exercised by Feature/Integration tests.
                $routeResult = $router->dispatchSafe($request);
                if (!isset($routeResult['error']) || $routeResult['error'] !== 'RouteNotFound') {
                    if (isset($routeResult['error']) && $routeResult['error'] === 'InsufficientPermissions') {
                        return new TestResponse(Response::make($routeResult['message'], 403));
                    }
                    if ($routeResult['data'] instanceof Response) {
                        return new TestResponse($routeResult['data']);
                    }
                    return new TestResponse(Response::make((string)$routeResult['data']));
                }
                // @codeCoverageIgnoreEnd
            }
        } catch (\Throwable $e) {
            // Router might not be bound, not yet implemented, or might throw
        }

        // 4. Fallback to classic MVC
        $controllerName = $request->getController() ?: $this->app->defaultController;
        $action = $request->getAction() ?: 'display';

        try {
            $controller = $this->app->getController($controllerName);
            $content = $controller->exec($action);
            
            // If the controller returned a Response object, use it directly
            if ($content instanceof Response) {
                return new TestResponse($content);
            }
            
            // Otherwise, we get string output. We need to render the document if the app expects it
            // but for tests, returning the content string is usually sufficient.
            $doc = Factory::getDocument();
            if (is_string($content)) {
                $doc->addContent($content);
            }
            return new TestResponse(Response::make($doc->render()));

        } catch (\Pramnos\Http\RedirectException $exception) {
            return new TestResponse(Response::redirect($exception->getUrl(), $exception->getStatusCode()));

        } catch (\Pramnos\Validation\ValidationException $exception) {
            $_SESSION['_validation_errors'] = $exception->errors();
            $_SESSION['_old_input'] = $request->allCurrent();

            $redirectTo = $_SERVER['HTTP_REFERER'] ?? '/';
            return new TestResponse(Response::redirect($redirectTo, 302));

        } catch (\Exception $exception) {
            $format = 'html'; // default
            $debug = true; // show traces in tests
            $response = \Pramnos\Http\ExceptionHandler::render($exception, $format, $debug);
            return new TestResponse($response);
        }
    }
}
