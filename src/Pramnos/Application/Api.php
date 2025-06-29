<?php

namespace Pramnos\Application;
/**
 * Restful API Application
 * @package     PramnosFramework
 * @subpackage  Application
 * @copyright   2020 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
     * Executes a controller
     * @param string $coontrollerName
     */
    public function exec($coontrollerName = '')
    {
        /*
         * Run any needed updates
         */
        if ($this->checkversion() !== true) {
            $this->upgrade();
        }
        #if (isset($_SERVER['HTTP_ORIGIN'])) {
            #header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            #header("Access-Control-Allow-Origin: *");
            #header('Access-Control-Allow-Credentials: true');
            #header('Access-Control-Max-Age: 86400');    // cache for 1 day
        #}
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Credentials: true');
        // Access-Control headers are received during OPTIONS requests
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
                header(
                    "Access-Control-Allow-Methods: "
                    . "GET, POST, OPTIONS, PUT, DELETE"
                );

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
                header(
                    "Access-Control-Allow-Headers: "
                    . "{$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}"
                );
            exit(0);
        }
        if (isset($_SERVER['HTTP_ACCEPT']) 
            && ($_SERVER['HTTP_ACCEPT'] == 'application/xml' 
                || $_SERVER['HTTP_ACCEPT'] == 'xml')
            ) {
            $this->accept = 'xml';
            header('content-type: application/xml; charset=utf-8');
        } else {
            header('content-type: application/json; charset=utf-8');
        }

        $controller = strtolower($coontrollerName);
        if ($controller === '' && $this->controller === '') {
            if ($this->defaultController !== "") {
                $this->controller = $this->defaultController;
            } else {
                $this->close('There is no controller to run...');
            }
        } elseif ($controller != '') {
            $this->controller = $controller;
        }

        $doc = & \Pramnos\Framework\Factory::getDocument('raw');
        if (!isset($_SERVER['HTTP_APIKEY'])) {
            $doc->addContent(
                $this->_translateStatus(
                    array(
                        'status' => 403,
                        'message' => 'API key is missing.',
                        'error' => 'APIKeyMissing'
                    )
                )
            );
            return;
        } elseif (!$this->checkApiKey($_SERVER['HTTP_APIKEY'])) {
            $doc->addContent(
                $this->_translateStatus(
                    array(
                        'status' => 401,
                        'message' => 'Invalid API key.',
                        'error' => 'APIKeyInvalid'
                    )
                )
            );
            return;
        }

        //Authentication
        //$_SESSION['logged'] = false;
        if (isset($_SERVER['HTTP_ACCESSTOKEN'])
            && trim($_SERVER['HTTP_ACCESSTOKEN'] != '')) {

            // Try to find an override user class
            if (isset($this->applicationInfo['namespace'])
                && $this->applicationInfo['namespace'] != ''
                && class_exists(
                    '\\'
                    . $this->applicationInfo['namespace']
                    . '\\User'
                )) {
                $className = '\\'
                    . $this->applicationInfo['namespace']
                    . '\\User';
                $user = new $className();
            } else {
                $user = new \Pramnos\User\User();
            }

            \Pramnos\Auth\JWT::$leeway = 60; // $leeway in seconds
            $tkn = $_SERVER['HTTP_ACCESSTOKEN'];
            
            $tokenInfo = \Pramnos\Auth\JWT::getTokenInformation($tkn);
            if (!$tokenInfo) {
                $doc->addContent(
                    $this->_translateStatus(
                        array(
                            'status' => 403,
                            'message' => 'Invalid Access Token.',
                            'error' => 'InvalidAccessToken',
                            'data' => 'Token information could not be retrieved.'
                        )
                    )
                );
                return;
            }

            if (isset($tokenInfo->alg) && $tokenInfo->alg == 'RS256') {
                $decodeKey = $this->authenticationKey;
                $privateKeyPath = ROOT . '/app/keys/public.key';
                if (file_exists($privateKeyPath)) {
                    $decodeKey = file_get_contents($privateKeyPath);
                } elseif (file_exists(ROOT . '/keys/public.key')) {
                    $decodeKey = file_get_contents(ROOT . '/keys/public.key');
                }
                try {
                    $jwt = \Pramnos\Auth\JWT::decode(
                        $tkn, $decodeKey,
                        array('HS256', 'RS256')
                    );
                } catch (\Exception $ex) {
                    $doc->addContent(
                        $this->_translateStatus(
                            array(
                                'status' => 403,
                                'message' => 'Invalid Access Token.',
                                'error' => 'InvalidAccessToken',
                                'data' => $ex->getMessage()
                            )
                        )
                    );
                return;
                }
            } else {
                try {
                    $jwt = \Pramnos\Auth\JWT::decode(
                        $tkn, $this->authenticationKey,
                        array('HS256')
                    );
                } catch (\Exception $ex) {
                    $doc->addContent(
                        $this->_translateStatus(
                            array(
                                'status' => 403,
                                'message' => 'Invalid Access Token.',
                                'error' => 'InvalidAccessToken',
                                'data' => $ex->getMessage()
                            )
                        )
                    );
                return;
                }
            }

            
            $user->loadByToken($_SERVER['HTTP_ACCESSTOKEN']);
            if ($user->userid > 1) {
                $_SESSION['logged'] = true;
                $_SESSION['user'] = $user;
            } elseif ($_SERVER['HTTP_ACCESSTOKEN'] != '') {
                $_SESSION['user'] = null;
                $doc->addContent(
                    $this->_translateStatus(
                        array(
                            'status' => 403,
                            'message' => 'Invalid Access Token.',
                            'error' => 'InvalidAccessToken'
                        )
                    )
                );
                return;
            } else {
                $_SESSION['user'] = null;
            }
        } elseif (isset($_SERVER['HTTP_USERAUTH'])) {
            if (isset($_SESSION['logged'])
                && $_SESSION['logged'] == true
                && isset($_SESSION['auth'])
                && $_SESSION['auth'] == $_SERVER['HTTP_USERAUTH']
                && isset($_SESSION['uid'])) {
                $user = new \Pramnos\User\User($_SESSION['uid']);
                $_SESSION['user'] = $user;
            }
        }

        if (isset($_SESSION['usertoken'])
            && is_object($_SESSION['usertoken'])) {
            try {
                $_SESSION['usertoken']->addAction();
            } catch (\Exception $ex) {
                unset($_SESSION['usertoken']);
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }

        } else {
            //Uncomment this to log non authenticated actions
            //$this->logAction();
        }

        $startTime = microtime(true);
        if (file_exists(ROOT . '/src/Api/routes.php')) {
            /**
             * Ok, εδώ θα γίνει λίγο της πουτάνας - προσωρινά - αφού φορτώνουμε
             * κομμάτια του PramnosFramework2 για να έχουμε καλύτερο routing
             */
            $response = include(ROOT . '/src/Api/routes.php');
            if ($response) {
                $content = $this->_translateStatus(
                    $response
                );
                $record = array();
                // if return status is not 2xx, record the return data
                if (isset($content['status'])
                    && $content['status'] >= 300) {
                    $record = $content;
                }
                if (isset($_SESSION['usertoken'])
                    && is_object($_SESSION['usertoken'])) {
                    $_SESSION['usertoken']->updateAction(
                        $_SESSION['usertoken']->lastActionId,
                        $content['status'] ?? 200,
                        microtime(true) - $startTime,
                        $record
                    );
                }


                $doc->addContent(
                    $content
                );
                return;
            }
        }

        $moduleObject = $this->getController($this->controller);
        $this->activeController = $moduleObject;
        try {




            $content = $this->_translateStatus(
                $moduleObject->exec($this->action)
            );
            $record = array();
            // if return status is not 2xx, record the return data
            if (isset($content['status'])
                && $content['status'] >= 300) {
                $record = $content;
            }
            if (isset($_SESSION['usertoken'])
                && is_object($_SESSION['usertoken'])) {
                $_SESSION['usertoken']->updateAction(
                    $_SESSION['usertoken']->lastActionId,
                    $content['status'] ?? 200,
                    microtime(true) - $startTime,
                    $record
                );
            }


            $doc->addContent(
                $content
            );


          
        } catch (\Exception $exception) {
            if ($exception->getCode() == 403) {
                $lang = \Pramnos\Framework\Factory::getLanguage();
                if (isset($_SESSION['usertoken'])
                    && is_object($_SESSION['usertoken'])) {
                    $_SESSION['usertoken']->updateAction(
                        $_SESSION['usertoken']->lastActionId,
                        403,
                        microtime(true) - $startTime,
                        array(
                            'status' => 403,
                            'message' => $lang->_(
                                'You are not logged in '
                                . 'or your session has expired.'
                            ),
                            'error' => 'PermissionDenied',
                            'details' => $exception->getMessage()
                        )
                    );
                }
                $doc->addContent(
                    $this->_translateStatus(
                        array(
                            'status' => 403,
                            'message' => $lang->_(
                                'You are not logged in '
                                . 'or your session has expired.'
                            ),
                            'error' => 'PermissionDenied',
                            'details' => $exception->getMessage()
                        )
                    )
                );
            } else {



                $message = $exception->getMessage();
                if (strpbrk($message, 'SQL') !== false) {
                    \Pramnos\Logs\Logger::log(
                        $message
                        . "\nLine:\n"
                        . $exception->getFile()
                        . " -> "
                        . $exception->getLine()
                        . "\nTrace:\n"
                        . $exception->getTraceAsString()
                    );
                }

                if (isset($_SESSION['usertoken'])
                    && is_object($_SESSION['usertoken'])) {
                    $_SESSION['usertoken']->updateAction(
                        $_SESSION['usertoken']->lastActionId,
                        500,
                        microtime(true) - $startTime
                    );
                }


                $doc->addContent(
                    $this->_translateStatus(
                        array(
                            'status' => 500
                        )
                    )
                );
            }
        }





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
            if (function_exists('http_response_code')) {
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
            case "500":
                return 'Internal Server Error';
            case "501":
                return 'Not Implemented';
            case "503":
                return 'Service Unavailable';
        }
    }

}
