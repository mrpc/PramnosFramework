<?php

namespace Pramnos\Application;
/**
 * Restful API Application
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Api extends Application
{
    /*
     * Each App has to have:
     *  - An App ID: (example: Android0001)
     *  - A Display Name: (Example Android Application)
     *  - An App Secret: 8a67fb96c7qwdq221r423c9ce62d79481
     *  - A Namespace: (AndroidApp)
     *  - A Contact Email: mrpc@pramnoshosting.gr
     */

    public $accept = 'json';
    private $_appName = 'edgeapi';
    /**
     * Authentication Key
     * @var string
     */
    public $authenticationKey='';
    /**
     * Current API key
     * @var Api\Apikey
     */
    public $apiKey = null;
    /**
     * Current controller name
     * @var string
     */
    public $controller = '';

    /**
     * Application class constructor
     * @param string $appName Application Name used for namespaces
     */
    public function __construct($appName = '')
    {
        parent::__construct($appName);
        if (!defined('APIVERSION')) {
            if (isset($this->applicationInfo['api_version'])) {
                define('APIVERSION', $this->applicationInfo['api_version']);
            } else {
                define('APIVERSION', 'edge');
            }
        }
        if (defined('sURL')) {
            $this->authenticationKey = md5(sURL . APIVERSION);
        } else {
            $this->authenticationKey = md5(APIVERSION);
        }

    }

    /**
     * Execute the API application for an HTTP request.
     *
     * The method runs a three-layer middleware pipeline before dispatching to
     * controllers:
     *   1. CorsMiddleware   — CORS headers + OPTIONS preflight (short-circuits)
     *   2. JsonResponseMiddleware — sets application/json Content-Type
     *   3. ApiAuthMiddleware — API key + Bearer token validation, session setup
     *
     * On authentication failure the pipeline short-circuits and returns a JSON
     * error envelope without reaching the controller.  All existing behaviour
     * (routes.php, controller dispatch, validation/exception handling, token
     * action tracking) is preserved — only the auth/CORS preamble is delegated
     * to middleware.
     *
     * BC note: existing code that calls `new Api(...)` and `->exec()` continues
     * to work without modification.
     *
     * @param string $coontrollerName Controller name (kept misspelled for BC).
     */
    public function exec($coontrollerName = '')
    {
        if ($this->checkversion() !== true) {
            $this->upgrade();
        }

        $controller = strtolower($coontrollerName);
        if ($controller === '' && $this->controller === '') {
            if ($this->defaultController !== '') {
                $this->controller = $this->defaultController;
            } else {
                $this->notFound();
            }
        } elseif ($controller !== '') {
            $this->controller = $controller;
        }

        $doc = &\Pramnos\Framework\Factory::getDocument('raw');

        // Build the middleware pipeline: CORS → JSON content-type → API auth
        //
        // CORS resolution priority:
        //  1. cors_from_db: true in applicationInfo → read from application_settings table (PF-43)
        //  2. cors_origins array in applicationInfo → use as-is
        //  3. Default: wildcard ['*']
        // The API path had no timers at all, which is why a SPA's Time tab showed
        // a single segment: everything it does happens here, and none of it was
        // measured. The MVC path has had `routing` and `controller` for a while.
        \Pramnos\Debug\DebugBar::startTimer('middleware');

        $pipeline = new \Pramnos\Http\MiddlewarePipeline();
        if (!empty($this->applicationInfo['cors_from_db'])) {
            $cors = \Pramnos\Http\Middleware\CorsMiddleware::fromApplicationSettings(
                $this->applicationInfo['name'] ?? ''
            );
        } else {
            $allowedOrigins = (array) ($this->applicationInfo['cors_origins'] ?? ['*']);
            $cors = new \Pramnos\Http\Middleware\CorsMiddleware($allowedOrigins);
        }
        $pipeline->pipe($cors);
        $pipeline->pipe(new \Pramnos\Http\Middleware\JsonResponseMiddleware());
        $pipeline->pipe(new \Pramnos\Http\Middleware\ApiAuthMiddleware(
            apiKeyChecker: [$this, 'checkApiKey'],
            authKey:       $this->authenticationKey,
            appNamespace:  $this->applicationInfo['namespace'] ?? null,
        ));

        $request   = \Pramnos\Framework\Factory::getRequest();
        $startTime = microtime(true);
        $self      = $this;

        $response = $pipeline->run(
            $request,
            function (\Pramnos\Http\Request $req) use ($self, $doc, $startTime): mixed {
                // The middleware stack is everything before this point — CORS,
                // content type, authentication — and everything after it on the
                // way out. Stopping here attributes the work in the middle to
                // the action rather than to the pipeline.
                \Pramnos\Debug\DebugBar::stopTimer('middleware');
                \Pramnos\Debug\DebugBar::startTimer('action');

                try {
                    return $self->_executeCore($startTime);
                } finally {
                    \Pramnos\Debug\DebugBar::stopTimer('action');
                }
            }
        );

        // A pipeline that short-circuited — an OPTIONS preflight, or auth that
        // refused — never reached the callback, so the timer it would have
        // stopped is still running. Left open it would read as an action that
        // took the whole request.
        \Pramnos\Debug\DebugBar::stopTimer('middleware');

        // Pipeline short-circuited (CORS OPTIONS or auth failure) — write & return.
        if ($response !== null && $response !== '') {
            // OPTIONS preflight returns '' — nothing to write
            if ($response !== '') {
                $doc->addContent($response);
            }
        }
    }

    /**
     * Core request dispatch: routes.php + controller execution + token tracking.
     *
     * Called by exec() after the auth middleware has already validated the
     * request.  Separated so that it can be tested independently of the
     * middleware pipeline.
     *
     * @param float $startTime microtime(true) captured at exec() entry
     */
    public function _executeCore(float $startTime): mixed
    {
        $doc = &\Pramnos\Framework\Factory::getDocument('raw');

        // Who this request is, from whatever authenticated it — a token for an
        // API call, a cookie for a page. Reading $_SESSION here would let a
        // browser's website login answer for an API call that presented no
        // credential of its own, in any application serving both from one
        // origin.
        $currentUser = \Pramnos\User\User::getCurrentUser();
        $currentUser = is_object($currentUser) ? $currentUser : null;

        $userdata = [];
        $userdata['username'] = $currentUser?->username ?? 'guest';
        $userdata['userid']   = $currentUser?->userid ?? null;

        try {
            $this->database->setTrackingInfo(
                $userdata['userid'],
                $this->applicationInfo['name'],
                $userdata
            );
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError(
                'Error setting tracking info: ' . $ex->getMessage(),
                $ex
            );
        }

        if (isset($_SESSION['usertoken']) && is_object($_SESSION['usertoken'])
            && \Pramnos\Application\VisitLogPolicy::shouldLog(
                \Pramnos\Application\VisitLogPolicy::CONTEXT_API
            )) {
            try {
                $_SESSION['usertoken']->addAction();
            } catch (\Exception $ex) {
                unset($_SESSION['usertoken']);
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }
        }

        // Routes.php dispatch path
        if (file_exists(ROOT . '/src/Api/routes.php')) {
            try {
                $response = include(ROOT . '/src/Api/routes.php');
            } catch (\Pramnos\Validation\ValidationException $ex) {
                return $this->_translateStatus([
                    'status'  => 422,
                    'message' => $ex->getMessage(),
                    'error'   => 'ValidationError',
                    'errors'  => $ex->errors(),
                ]);
            } catch (\Exception $ex) {
                return $this->_translateStatus(
                    $ex->getCode() === 403
                        ? ['status' => 403, 'message' => $ex->getMessage(), 'error' => 'InvalidPermissions']
                        : ['status' => 500, 'message' => 'Error loading routes.', 'error' => 'RoutesLoadError', 'details' => $ex->getMessage()]
                );
            }

            // Modern controllers/routes return a Response object — emit it as-is
            // (its own status code, headers and JSON body), bypassing the legacy
            // array/string envelope of _translateStatus(). Array/string returns
            // keep the classic envelope for backward compatibility.
            if ($response instanceof \Pramnos\Http\Response) {
                // Skip real header emission under CLI (PHPUnit) — matches the
                // guard used by _translateStatus() and avoids the harmless
                // "http_response_code() has no effect" warning during tests.
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code($response->getStatusCode());
                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ((array) $values as $value) {
                            header($name . ': ' . $value, false);
                        }
                    }
                    $this->_sendServerTiming();
                }
                $this->_recordTokenAction($startTime, ['status' => $response->getStatusCode()]);
                $doc->addContent($this->_attachDebugPayload($response->getBody()));
                return null;
            }

            if ($response) {
                $content = $this->_translateStatus($response);
                $this->_recordTokenAction($startTime, $response);
                $doc->addContent($content);
                return null;
            }
        }

        // Controller dispatch path
        $moduleObject         = $this->getController($this->controller);
        $this->activeController = $moduleObject;

        try {
            $response = $moduleObject->exec($this->action);
            $this->_recordTokenAction($startTime, $response);
            $doc->addContent($this->_translateStatus($response));
        } catch (\Pramnos\Validation\ValidationException $exception) {
            $errorResponse = [
                'status'  => 422,
                'message' => $exception->getMessage(),
                'error'   => 'ValidationError',
                'errors'  => $exception->errors(),
            ];
            $this->_recordTokenAction($startTime, $errorResponse);
            $doc->addContent($this->_translateStatus($errorResponse));
        } catch (\Exception $exception) {
            if ($exception->getCode() === 403) {
                $lang          = \Pramnos\Framework\Factory::getLanguage();
                $errorResponse = [
                    'status'  => 403,
                    'message' => $lang->_('You are not logged in or your session has expired.'),
                    'error'   => 'PermissionDenied',
                    'details' => $exception->getMessage(),
                ];
                $this->_recordTokenAction($startTime, $errorResponse);
                $doc->addContent($this->_translateStatus($errorResponse));
            } else {
                $message = $exception->getMessage();
                if (str_contains($message, 'SQL')) {
                    \Pramnos\Logs\Logger::log(
                        $message . "\nLine:\n" . $exception->getFile()
                        . ' -> ' . $exception->getLine()
                        . "\nTrace:\n" . $exception->getTraceAsString()
                    );
                }
                $this->_recordTokenAction($startTime, null);
                $doc->addContent($this->_translateStatus(['status' => 500]));
            }
        }

        return null;
    }

    /**
     * Record token action execution time and status code.
     *
     * @param float      $startTime microtime(true) at request start
     * @param mixed      $response  Controller/routes response (used to extract status code)
     */
    private function _recordTokenAction(float $startTime, mixed $response): void
    {
        if (!isset($_SESSION['usertoken']) || !is_object($_SESSION['usertoken'])) {
            return;
        }

        $status = 200;
        $record = [];
        if (is_array($response) && isset($response['status'])) {
            $status = (int) $response['status'];
            if ($status >= 300) {
                $record = $response;
            }
        }

        $_SESSION['usertoken']->updateAction(
            $_SESSION['usertoken']->lastActionId,
            $status,
            microtime(true) - $startTime,
            $record
        );
    }

    /**
     * Log not authenticated actions
     */
    protected function logAction()
    {
        if ($this->apiKey === null) {
            return;
        }

        $request = \Pramnos\Framework\Factory::getRequest();
        $url = $request->getURL(false);
        \Pramnos\Framework\Factory::getRequest();
        switch (\Pramnos\Http\Request::$requestMethod) {
            case "POST":
                $inputData = json_encode($_POST);
                break;
            case "DELETE":
                $inputData = json_encode(\Pramnos\Http\Request::$deleteData);
                break;
            case "PUT":
                $inputData = json_encode(\Pramnos\Http\Request::$putData);
                break;
            default:
                $inputData = file_get_contents("php://input");
                break;
        }
        $log = "URL: " . $url . "\nInput Data: "
            . $inputData . "\nIP: "
            . \Pramnos\Http\Request::clientIp('unknown') . "\n\n";
        \Pramnos\Logs\Logger::log($log, 'notAuthenticatedActions');
    }

    /**
     * Ελέγχει αν ένα API Key είναι έγκυρο
     * Trick: Θεωρούμε έγκυρο το md5 του url, ως κλειδί για το web
     * @param string $apiKey
     * @return boolean
     */
    public function checkApiKey($apiKey)
    {
        //localhost: 2814a61c720077ae1c0410c97d87bc06
        if ($apiKey == md5(str_replace('/api/', '/', sURL))) {
            return true;
        }
        $applicationObject = new \Pramnos\Application\Api\Apikey($apiKey);
        if ($applicationObject->appid != 0) {
            $this->apiKey = $applicationObject;
            if ($applicationObject->status == 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Emit a JSON HTTP 404 response and terminate.
     *
     * API override of {@see Application::notFound()}: instead of an HTML page it
     * returns the standard JSON error envelope with a 404 status, so API
     * consumers receive a machine-readable "not found" rather than the old
     * "There is no controller to run..." plain-text string. _translateStatus()
     * sets the 404 HTTP status code itself.
     *
     * @param string $message Optional error message; defaults to "Resource not found".
     */
    public function notFound($message = '')
    {
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            header('Content-Type: application/json; charset=utf-8');
        }
        $this->close(
            $this->_translateStatus([
                'status'  => 404,
                'message' => $message !== '' ? $message : 'Resource not found',
                'error'   => 'NotFound',
            ])
        );
    }

    /**
     * Translates return of a controller and adds all the required information
     * @param array $status
     */
    protected function _translateStatus($status)
    {
        $defaultArray = array(
            'status' => 200,
            'statusmessage' => 'OK',
            'message' => '',
            'error' => false
        );
        if (is_string($status)) {
            $return = $defaultArray;
            $return['message'] = $status;
        } elseif (is_array($status)) {
            $return = array_merge($defaultArray, $status);
        } else {
            $return = $defaultArray;
        }
        if ($return['status'] != 200) {
            if ($return['statusmessage'] == 'OK') {
                $return['statusmessage'] = $this->_httpStatusToText(
                    $return['status']
                );
            }
            if (function_exists('http_response_code') && PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code((int)$return['status']);
            }
        }

        // In development the toolbar's data rides along with the response it
        // describes: a JSON body has no </body> for the HTML toolbar to be
        // injected into, and a SPA's page never goes through that pipeline at
        // all. Never attached in production — see ApiDebugPayload::isEnabled().
        if (\Pramnos\Debug\ApiDebugPayload::isEnabled()) {
            $return['_debug'] = \Pramnos\Debug\ApiDebugPayload::build();
            $this->_sendServerTiming();
        }

        return json_encode($return);
    }

    /**
     * Merge the debug payload into an already-encoded JSON body.
     *
     * Anything that is not a JSON object is returned untouched: a plain string
     * response, an array at the top level, or a body that failed to decode has
     * nowhere to put a `_debug` key, and mangling it would be worse than having
     * no debug data.
     *
     * @param  string $body The response body as the controller produced it
     * @return string
     */
    protected function _attachDebugPayload($body)
    {
        // Reading this method is how an application concludes it cannot feed the toolbar
        // without reimplementing it — a consumer did, twice. It cannot use *this*, because it
        // is protected and their controllers never reach this class. It does not need to:
        // ApiDebugPayload::attachTo() is public, and \Pramnos\Debug\ApiDebugMiddleware puts
        // it in the pipeline in one line, for any routing style. See the Application Styles
        // guide, "Feeding the debug toolbar from a non-Api application".
        // Delegated rather than reimplemented. The rule about which bodies can
        // carry the key — not a top-level array, not a non-object, not one that
        // already has a `_debug` — belongs in one place, because an application
        // routing with #[Route] attributes reaches it through
        // {@see \Pramnos\Debug\ApiDebugMiddleware} instead of through this class,
        // and two copies of that rule would eventually disagree.
        return \Pramnos\Debug\ApiDebugPayload::attachTo((string) $body);
    }

    /**
     * Emit the Server-Timing header for this request.
     *
     * Browsers show it in the network panel with no front-end code at all, and
     * it is the only channel that also works for responses with no body.
     */
    protected function _sendServerTiming()
    {
        // Public equivalent: ApiDebugPayload::sendHeaders(), which this calls. See
        // _attachDebugPayload() above for why that note is here rather than only in a guide.
        // Both headers, once per response — the output-buffer callback offers
        // again for every response, and ApiDebugPayload keeps them from being
        // sent twice.
        \Pramnos\Debug\ApiDebugPayload::sendHeaders();
    }

    /**
     * Translates a http status code to the usual message
     * @param   int $status
     * @return  string
     */
    protected function _httpStatusToText($status)
    {
        switch ($status) {
            default:
                return 'OK';
            case "201":
                return 'Created';
            case "202":
                return 'Accepted (Request accepted, and queued for execution)';
            case "400":
                return 'Bad request';
            case "401":
                return 'Authentication failure';
            case "403":
                return 'Forbidden';
            case "404":
                return 'Resource not found';
            case "405":
                return 'Method Not Allowed';
            case "409":
                return 'Conflict';
            case "412":
                return 'Precondition Failed';
            case "413":
                return 'Request Entity Too Large';
            case "422":
                return 'Unprocessable Entity';
            case "500":
                return 'Internal Server Error';
            case "501":
                return 'Not Implemented';
            case "503":
                return 'Service Unavailable';
        }
    }

}
