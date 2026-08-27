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
            // `currentInstance()` makes the branch below reachable. With `getInstance()` it
            // could not be: that never returns null, so the fallback was dead code carrying a
            // coverage-ignore to explain why.
            $appInstance = Application::currentInstance();
            if ($appInstance === null) {
                $this->app = new Application();
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
        /**
         * The previous request's routing state, which is static and would
         * otherwise answer for this one.
         *
         * `calcParams()` runs only when there is a path to route, so a request to
         * the site root left `$_controller` holding whatever the request before
         * it resolved — `/` served the previous URL's controller. `getInstance()`
         * has the same problem the other way round: it keeps returning the first
         * request's object no matter how many follow.
         */
        Request::resetInstance();

        /**
         * A fresh document, because a document belongs to one request.
         *
         * Everything on it appends: `addContent()`, and `header`/`head`/`foot`,
         * which `render()` adds the theme's to on every call. So with one client
         * making several requests, response 2 carried response 1's page in front
         * of its own and its `<head>` twice; by the fifth the theme had been
         * concatenated five times and a test died with a 34 MB output buffer and
         * an exhausted memory limit. `assertSee()` passing on a page the test had
         * already left is the quieter half of the same bug.
         */
        \Pramnos\Document\Document::reset();

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

        /**
         * The routing parameter, which is what actually decides the controller.
         *
         * `Request` splits the path into controller, action, `_option` and a
         * key/value tail — but only from `$_GET['r']`, because that is what the
         * scaffolded `.htaccess` rewrites every URL into. Setting `REQUEST_URI`
         * alone left it unset, so `calcParams()` never ran and
         * `$request->getController()` came back empty for **every** path. The
         * classic-MVC fallback below then ran the default controller, and the
         * test asserted against the site's home page while believing it had
         * asked for something else. A test written to prove that `/admin/users`
         * is refused to a guest passed on a home page that no guard applies to.
         *
         * Set the same way the rewrite sets it: the path, no leading slash, with
         * the query string left to `$_GET` (`calcParams()` re-merges it from
         * `REQUEST_URI` itself).
         */
        $path = ltrim((string) ($parsed['path'] ?? ''), '/');
        if ($path !== '') {
            $_GET['r'] = $path;
        }

        if (in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $_POST = $parameters;
            // Also update raw input for Request
            Request::setRawInput(http_build_query($parameters));
        } else {
            Request::setRawInput('');
        }

        /**
         * Per-request application state, re-derived for this URI.
         *
         * `Application::__construct()` does this once, and a `TestClient` reuses
         * one application across many requests — so without it the first URI's
         * decisions stood for every later one. Most visibly the administration
         * area: it is detected from `$_GET['r']`, so `/admin/users` was never
         * recognised as being inside it, and once it had been the admin theme
         * stayed selected for the public pages that followed.
         */
        $this->app->beginRequest();

        // 2. Initialize Request
        $request = new Request();

        // 3. Try Router first
        try {
            // `$app->router`, not a DI container: `Factory::getContainer()` does
            // not exist. The Error it raised was caught below, so this branch
            // never ran — every request through TestClient fell through to the
            // classic MVC path, including the ones written to exercise attribute
            // routing. The comment underneath claimed the opposite.
            $router = $this->resolveRouter();
            if ($router) {
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
            }
        } catch (\Throwable $e) {
            // Router might not be bound, not yet implemented, or might throw
        }

        // 4. Fallback to classic MVC
        $controllerName = $request->getController() ?: $this->app->defaultController;
        $action = $request->getAction() ?: 'display';

        /**
         * Whatever the request writes to the output stream belongs to the response.
         *
         * A real request's `echo` reaches the browser; here it reached the
         * terminal, straight through PHPUnit's own output. `Application::redirect()`
         * writes a `<script>window.location=…</script>` fallback before ending the
         * request, so a suite exercising an admin area printed a block of HTML per
         * redirect between the progress dots, and a failure had to be found among
         * them.
         *
         * Capturing it is not tidiness: it puts the bytes where a test can assert
         * on them. Output written before the document renders comes first in the
         * response, which is the order a browser receives it in.
         */
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            /**
             * The administration area's usertype floor, where the application
             * applies it — before a controller inside the area is constructed for
             * somebody who may not be there.
             *
             * `Application::exec()` does this, and TestClient resolves the
             * controller itself rather than going through `exec()`, so it did
             * not: every `/admin/...` request in a test was served with no floor
             * at all. Tests written to prove the floor works passed because the
             * screens have their own checks — so the suite would have kept
             * passing right up to the first screen that forgot one.
             *
             * A refusal redirects, which now arrives as a `RedirectException` and
             * is answered below with the destination the application chose.
             */
            if (!$this->app->allowAdminAreaRequest()) {
                // The refusal is a *pending* redirect: the guard records where the
                // visitor should go and `render()` performs it. Nothing here
                // renders, so the destination is read directly. A refusal that
                // named nowhere is a 403 rather than a silent empty 200.
                $destination = $this->app->getRedirect();

                return $this->respond(
                    $destination === null
                        ? Response::make('', 403)
                        : Response::redirect($destination, 302)
                );
            }

            /**
             * The theme, where `Application::exec()` loads it: before the
             * controller runs, because a controller is entitled to read
             * `$document->themeObject` while it does.
             *
             * TestClient never loaded one, so a response was the controller's
             * output with no layout around it — no header, no navigation, no
             * footer. A test could say nothing about a page as opposed to a
             * fragment, and a theme that fails to render was invisible to the
             * entire suite.
             */
            $this->app->loadConfiguredTheme(Factory::getDocument());

            try {
                $controller = $this->app->getController($controllerName);
            } catch (\Pramnos\Application\ApplicationClosedException $closed) {
                throw $closed;
            } catch (\Exception $missing) {
                /**
                 * No such controller. `Application::exec()` catches this exact
                 * exception and answers with its 404 page; TestClient resolved
                 * the controller itself and so never reached that, turning every
                 * unknown URL into a 500 — the one status a not-found test must
                 * not get. `notFound()` throws, and the handler below renders it
                 * with the status it carries.
                 */
                $this->app->notFound();
            }
            $content = $controller->exec($action);
            
            // If the controller returned a Response object, use it directly
            if ($content instanceof Response) {
                return $this->respond($content);
            }
            
            // Otherwise, we get string output. We need to render the document if the app expects it
            // but for tests, returning the content string is usually sufficient.
            $doc = Factory::getDocument();
            if (is_string($content)) {
                $doc->addContent($content);
            }
            return $this->respond(Response::make($doc->render()));

        } catch (\Pramnos\Http\RedirectException $exception) {
            return $this->respond(Response::redirect($exception->getUrl(), $exception->getStatusCode()));

        } catch (\Pramnos\Application\ApplicationClosedException $exception) {
            /**
             * The application ended the request itself — a 404, a maintenance
             * stop, an error page. It carries the status it had decided on, which
             * is the whole point of the typed exception: before it, all three
             * arrived as a bare `\Exception` and were rendered as a 500, so no
             * test could assert that a URL is not found.
             */
            /**
             * A redirect that ended the request is answered as a redirect, with
             * the destination the application chose — otherwise the response is
             * the `<script>window.location` fallback body with no status and no
             * Location, and a test cannot tell it from a page.
             */
            $status = $exception->getStatusCode();
            $destination = $this->app->getRedirect();
            if ($status >= 300 && $status < 400 && $destination !== null) {
                return $this->respond(Response::redirect($destination, $status));
            }

            return $this->respond(Response::make(
                $exception->getBody(),
                $status
            ));

        } catch (\Pramnos\Validation\ValidationException $exception) {
            $_SESSION['_validation_errors'] = $exception->errors();
            $_SESSION['_old_input'] = $request->allCurrent();

            $redirectTo = $_SERVER['HTTP_REFERER'] ?? '/';
            return $this->respond(Response::redirect($redirectTo, 302));

        } catch (\Exception $exception) {
            $format = 'html'; // default
            $debug = true; // show traces in tests
            $response = \Pramnos\Http\ExceptionHandler::render($exception, $format, $debug);
            return $this->respond($response);
        } finally {
            // Every return above closes the buffer itself. This is for the path
            // where something escapes the catches: leaving a buffer open would
            // swallow the rest of the suite's output.
            if (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    }

    /**
     * Close the capture buffer and put what the request echoed in front of the
     * body it built.
     *
     * That is the order a browser sees: anything written to the output stream
     * leaves before the document is rendered.
     */
    private function respond(Response $response): TestResponse
    {
        $echoed = ob_get_level() > 0 ? (string) ob_get_clean() : '';

        if ($echoed === '') {
            return new TestResponse($response);
        }

        // `withBody()`, not a fresh `Response::make()`: rebuilding drops the
        // headers, and the header is the whole of a redirect. Caught by a test
        // asserting on Location, which came back empty the moment a redirect also
        // echoed its `<script>` fallback.
        return new TestResponse($response->withBody($echoed . $response->getBody()));
    }
    /**
     * The Router this application published, if it has one.
     *
     * The same place `route:list` looks: applications publish their populated
     * Router as `$app->router`. An application that does not is not an error —
     * it simply has no attribute routes, and the classic MVC path below is the
     * whole of its dispatch.
     *
     * @return \Pramnos\Routing\Router|null
     */
    protected function resolveRouter(): ?\Pramnos\Routing\Router
    {
        if (isset($this->app->router)
            && $this->app->router instanceof \Pramnos\Routing\Router) {
            return $this->app->router;
        }

        return null;
    }

}
