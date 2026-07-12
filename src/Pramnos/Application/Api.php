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
                $this->close('There is no controller to run...');
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
                return $self->_executeCore($startTime);
            }
        );

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

        $userdata = [];
        $userdata['username'] = ($_SESSION['user'] ?? null)?->username ?? 'guest';
        $userdata['userid']   = ($_SESSION['user'] ?? null)?->userid ?? null;

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

        if (isset($_SESSION['usertoken']) && is_object($_SESSION['usertoken'])) {
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
            . $_SERVER['REMOTE_ADDR'] . "\n\n";
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

        return json_encode($return);
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
