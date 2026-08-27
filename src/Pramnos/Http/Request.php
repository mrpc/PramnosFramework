<?php

namespace Pramnos\Http;

use Pramnos\Framework\Base;
use Pramnos\Validation\ValidationException;

/**
 * Get user request and translate it
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Request extends Base
{
    /**
     * Current controller
     * @var string
     */
    protected static $_controller = '';
    protected static $action = '';
    /**
     * Original $_GET request
     * @var string
     */
    public static $originalRequest='';

    /**
     * Raw input stream content
     * @var string|null
     */
    protected static $rawInput = null;

    /**
     * Original $_GET request that should never change
     * @var string
     */
    public static $originalRequestNoChange='';

    /**
     * The URI which was given in order to access this page;
     * for instance, '/index.html'.
     * @var string
     */
    public static $requestUri='';

    public static $requestMethod='GET';

    /**
     * The body of a PUT request, decoded.
     *
     * **PHP does not populate `$_POST` for anything but POST.** A handler that
     * reads `$_POST` under PUT finds nothing, with no signal that it will — so the
     * body is parsed here instead. Prefer {@see body()}, which picks the right
     * store for the method the request actually used.
     *
     * @var array
     */
    public static $putData = array();

    /**
     * The body of a DELETE request, decoded.
     *
     * Same reason as {@see $putData}, and this one has already cost three shipped
     * bugs in one application: banning worked and unbanning was impossible,
     * because the unban handler read `$_POST` under DELETE. All three passed their
     * unit tests, because a test that seeds `$_POST` for a DELETE constructs a
     * state no real request can produce.
     *
     * @var array
     */
    public static $deleteData = array();

    /**
     * The body of a PATCH request, or of any other method with one.
     *
     * @var array
     */
    public static $patchData = array();

    /**
     * Flashed validation errors available for the current request only
     * @var array
     */
    protected static $validationErrors = null;

    /**
     * Flashed old input available for the current request only
     * @var array
     */
    protected static $oldInput = null;

    /**
     * Flashed messages, captured once per request.
     *
     * The companion to {@see $validationErrors}, for the general-purpose flash that
     * `Base::addMessage()` writes. Null until first read.
     *
     * @var array|null
     */
    protected static $flashMessages = null;

    /**
     * Flashed errors, captured once per request.
     *
     * Distinct from {@see $validationErrors}: those are per-field and come from a validator,
     * these are whole sentences a controller wrote with `Base::addError()`.
     *
     * @var array|null
     */
    protected static $flashErrors = null;

    /**
     * The shared instance, for {@see getInstance()}.
     *
     * A property rather than a function-static so {@see resetInstance()} can
     * reach it. A static inside the method cannot be cleared from anywhere
     * else, which is why a test run could only ever have one request.
     *
     * @var Request|null
     */
    protected static $instance = null;

    /**
     * The shared Request for this process.
     *
     * @return Request
     */
    public static function &getInstance()
    {
        if (!is_object(self::$instance)) {
            self::$instance = new Request();
        }

        return self::$instance;
    }

    /**
     * Forget the shared Request and everything derived from it.
     *
     * A request is per request, and a test run is one process — so a suite that
     * exercises routing, controllers or input has to be able to start a second
     * one. It could not: the instance lived in a function-static, which nothing
     * outside the method can reach, and the URI, method and raw body are class
     * statics that outlive it. A test could construct `new Request()` to refresh
     * those, but every caller that goes through `getInstance()` — which is most
     * of the framework — kept the first one.
     *
     * A consuming application added this to its own copy in `vendor/` rather
     * than filing it, which is how it was found.
     *
     * Resets the derived state too, because leaving `$requestUri` behind would
     * hand the next request the previous one's address — the failure this is
     * meant to prevent, arriving one step later.
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
        self::$requestUri = '';
        self::$requestMethod = 'GET';
        self::$rawInput = null;
        self::$originalRequest = '';
        self::$originalRequestNoChange = '';
        self::$_controller = '';
        self::$action = '';
        self::$putData = array();
        self::$deleteData = array();
        self::$patchData = array();
    }

    /**
     * Create a request object
     *
     * **The URI is trimmed of its slashes, exactly as the constructor trims the
     * one it reads from `$_SERVER`.** It used to be stored verbatim, so
     * `getRequestUri()` answered `stations/7` for a real request and
     * `/stations/7` for a created one — two ways of building a Request
     * disagreeing about what the request was for, which every consumer of that
     * value then inherited.
     *
     * Routing is where it surfaced: `Route::matches()` prefixes a slash before
     * handing the URI to the compiled pattern, so a stored `/stations/7` became
     * `//stations/7` and **every route with a placeholder missed** — while
     * static routes kept working, because they are answered by a string
     * comparison before the pattern is reached. Reported by a consuming
     * application.
     *
     * Every existing caller passes a leading slash, because that is how a URL
     * is written; none of them wanted the leading slash preserved.
     *
     * @param string $uri
     * @param string $method
     * @return \Pramnos\Http\Request
     */
    public static function create($uri, $method="GET")
    {
        $request = new Request();
        self::$requestUri = trim((string) $uri, '/');
        self::$requestMethod = strtoupper($method);
        return $request;
    }

    /**
     * Set raw input content for testing
     * @param string|null $content
     */
    public static function setRawInput($content)
    {
        self::$rawInput = $content;
    }

    /**
     * The raw request body, read once per request.
     *
     * `php://input` is a stream, and reading it is not always repeatable: with
     * `enable_post_data_reading` off, behind some SAPIs, and for
     * `multipart/form-data` under every SAPI, the second read returns an empty
     * string. So a request whose body was already read — by
     * {@see decodeBody()}, by a middleware, by anything — reached a handler
     * reading `php://input` for itself as a request with *no body*, and the
     * handler answered "malformed or missing payload" for a payload that was
     * there. Reading once and keeping the result is what makes the body
     * available to everyone who needs it, in whatever order they ask.
     *
     * This is also the value {@see setRawInput()} sets, which is what makes a
     * body testable: a handler calling `file_get_contents('php://input')`
     * directly cannot see what a test supplied, so its body-reading path could
     * not be exercised at all.
     *
     * @return string
     */
    public static function rawBody(): string
    {
        if (self::$rawInput === null) {
            $raw = file_get_contents("php://input");
            self::$rawInput = $raw === false ? '' : $raw;
        }

        return (string) self::$rawInput;
    }

    /**
     * Get raw input content
     * @return string
     */
    protected function getRawInput()
    {
        return self::rawBody();
    }

    /**
     * The body of **this** request, whatever method it used.
     *
     * PHP populates `$_POST` for POST only. Every other method with a body — DELETE,
     * PUT, PATCH — leaves it empty, and a handler reading `$_POST` there finds
     * nothing with no signal that it will. That has already shipped three times in
     * one application: banning worked and unbanning was impossible; an endpoint
     * worked over POST and failed over DELETE on the same route; a third accepted
     * JSON and refused the form-encoded body every other endpoint used. All three
     * passed their unit tests, because a test that seeds `$_POST` for a DELETE
     * constructs a state no real request can produce.
     *
     * Two differences from {@see allCurrent()}, and both matter:
     *
     * 1. **The method is read live**, from `$_SERVER`, rather than taken from
     *    whatever it was when the singleton happened to be built. The captured one
     *    is correct in production and stale anywhere the method is set afterwards —
     *    including every test, which is how a fix can pass over HTTP and fail under
     *    PHPUnit.
     * 2. **A body is parsed on demand.** If the store for the live method is empty,
     *    the raw input is read and decoded now, so this works even when the
     *    constructor ran under a different method.
     *
     * On a GET the query string is returned: a caller asking what this request
     * carried means the query there.
     *
     * @return array Field name => value
     */
    public function body(): array
    {
        switch ($this->liveRequestMethod()) {
            case 'POST':
                return $_POST;
            case 'PUT':
                if (self::$putData === array()) {
                    self::$putData = $this->decodeBody();
                }
                return self::$putData;
            case 'DELETE':
                if (self::$deleteData === array()) {
                    self::$deleteData = $this->decodeBody();
                }
                return self::$deleteData;
            case 'PATCH':
                if (self::$patchData === array()) {
                    self::$patchData = $this->decodeBody();
                }
                return self::$patchData;
            case 'GET':
            case 'HEAD':
                return $_GET;
            default:
                // Any other method that carried something. Decoding it beats
                // answering from $_REQUEST, which for these is always empty.
                return $this->decodeBody();
        }
    }

    /**
     * One value from {@see body()}, or a default.
     *
     * @param  string $name
     * @param  mixed  $default Returned when the body has no such field.
     * @return mixed
     */
    public function bodyValue(string $name, $default = null)
    {
        $body = $this->body();

        return array_key_exists($name, $body) ? $body[$name] : $default;
    }

    /**
     * The request method as it is now, not as it was when this object was built.
     *
     * @return string Upper-case, e.g. `DELETE`
     */
    private function liveRequestMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? self::$requestMethod;

        return strtoupper((string) $method);
    }

    /**
     * Decode the raw request body into an array.
     *
     * JSON is detected and decoded **associatively**, which is the second half of
     * the same class of bug. `(array) json_decode($raw)` casts only the top level,
     * so every nested value stays an `stdClass` — and a handler iterating a nested
     * list and checking `is_array()` rejects the whole payload. One application's
     * import endpoint answered `200 {"success":true,"imported":0,"invalid":1}`
     * because of it: a success status, nothing imported, and the only evidence a
     * counter nobody reads. It had worked as a standalone script calling
     * `json_decode($raw, true)` itself; moving it onto the framework's parsing is
     * what broke it.
     *
     * A body that declares or looks like JSON and is not valid JSON yields an empty
     * array rather than being handed to `parse_str`, which is deliberate:
     * `parse_str('{"id":7}', $out)` produces `['{"id":7}' => '']` — **non-empty, so
     * an `empty()` fallback never fires, and nonsense, so nothing reads correctly
     * either**. That garbled-but-plausible array is the trap that broke every JSON
     * caller of an endpoint inside the hour its form-encoded case was fixed.
     *
     * @return array
     */
    protected function decodeBody(): array
    {
        $raw = (string) $this->getRawInput();
        if (trim($raw) === '') {
            return array();
        }

        if ($this->bodyIsJson($raw)) {
            $decoded = json_decode($raw, true);

            // A scalar is valid JSON and carries no named values; an array is what
            // every caller of this expects, so a scalar produces an empty one
            // rather than a surprise shape.
            return is_array($decoded) ? $decoded : array();
        }

        $parsed = array();
        parse_str($raw, $parsed);

        return $parsed;
    }

    /**
     * Is this body JSON?
     *
     * The declared content type first, because it is the sender's own statement.
     * The shape of the body second, because a hand-written `curl` call and more
     * than one HTTP client omit the header, and `{`/`[` is unambiguous — no
     * form-encoded body starts with either.
     *
     * @param  string $raw
     * @return bool
     */
    private function bodyIsJson(string $raw): bool
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'json')) {
            return true;
        }

        $first = substr(ltrim($raw), 0, 1);

        return $first === '{' || $first === '[';
    }

    /**
     * Calculate the parameters of request
     * @param $requestParam
     */
    public function calcParams($requestParam=null)
    {
        /**
         * What was in `$_GET` before this ran is kept.
         *
         * This method used to empty `$_GET` outright and then rebuild it from
         * the request — the path segments it decodes into named parameters, and
         * the query string. That is right for the keys it produces and wrong for
         * every other key: anything a front controller, a middleware, a rewrite
         * rule or a test had put there was discarded, silently, with no way for
         * the caller to know it had happened.
         *
         * `r` is dropped because it is the front controller's own routing
         * parameter and never belonged to the application — the constructor
         * unsets it for the same reason.
         */
        $preserved = is_array($_GET) ? $_GET : array();
        unset($preserved['r']);
        $_GET = $preserved;
        if ($requestParam == null){
            $requestParam=self::$originalRequest;
        }
        self::$_controller = '';
        $request = rtrim($requestParam, '/');
        $parsedUrl = parse_url($_SERVER['REQUEST_URI']);
        if (is_array($parsedUrl) && isset($parsedUrl['query'])) {
            /**
             * Merged into `$_GET`, not written over it.
             *
             * `parse_str($query, $_GET)` **replaces** the array. Usually that is
             * a no-op, because `$_GET` already holds the parsed query string —
             * but not always, and the exceptions are the ones that matter:
             * anything a front controller, a middleware or a rewrite put there
             * before this ran was silently discarded, and so was anything a test
             * had arranged.
             *
             * The query string wins on a key it defines, which is what the
             * original assignment did for every key it produced; keys it does
             * not mention are left alone, which is the part that was wrong.
             */
            $fromQuery = [];
            parse_str($parsedUrl['query'], $fromQuery);
            $_GET = array_merge($_GET, $fromQuery);
        }
        unset($parsedUrl);
        $slashes = substr_count($request, '/');
        if (isset($_SERVER['REQUEST_URI'])
            && strpos($_SERVER['REQUEST_URI'], '?') !== false) {
            $request = $request . substr(
                $_SERVER['REQUEST_URI'],
                strpos($_SERVER['REQUEST_URI'], '?')
            );
            $slashes = substr_count($request, '/');

            $request = str_replace('//', '/', $request);
        }
        $mainString = explode('?', $request);
        $parts = explode("/", $mainString[0]);
        if (isset($parts[0]) && $parts[0] !== '') {
            self::$_controller = $parts[0];
        }
        if ($slashes > 0 && isset($parts[1]) && $parts[1] !== '') {
            self::$action = $parts[1];
        }

        if (count($parts) > 2) {
            if ($slashes == 2) {
                $_GET['_option'] = $parts[2];
                unset($parts[2]);
            } elseif ($slashes > 0) {
                unset($parts[0], $parts[1]);
            } else {
                unset($parts[0]);
            }
            foreach ($parts as $part) {

                if (isset($varname) && !isset($_GET[$varname]) && trim($varname) != '') {
                    $_GET[$varname] = $part;
                    $_REQUEST[$varname] = $part;
                    unset($varname);
                } else {
                    $varname = $part;
                }
            }
            if (isset($varname)) {
                $_GET[$varname] = null;
                if (!isset($_GET['_option'])){
                    $_GET['_option'] = $varname;
                }
                unset($varname);
            }
            unset($part);
        }

    }

    /**
     * Class constructor
     */
    public function __construct()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            self::$requestUri = trim($_SERVER['REQUEST_URI'], '/');
            /**
             * Strip the front controller's directory, for an application that
             * runs under a subdirectory — but only when it really is one.
             *
             * This used to cut `strlen(dirname($_SERVER['PHP_SELF']))`
             * characters off the front of the URI unconditionally, on the
             * assumption that PHP_SELF is a web path. It is not always:
             *
             *   - Under the CLI — a console command, a daemon, a test runner —
             *     PHP_SELF is the script's filesystem path. Under PHPUnit that
             *     is `…/vendor/bin/phpunit`, whose dirname is 23 characters, so
             *     **every URI lost its first 23 characters**. Two projects have
             *     now written the same workaround for it: this repository's own
             *     routing tests pin `PHP_SELF` to `/index.php` before
             *     constructing a Request, with a comment explaining why, and a
             *     consuming application patched this method in its `vendor/`
             *     directory rather than filing it.
             *   - A relative PHP_SELF gives a dirname of `.`, one character,
             *     which silently eats the first character of the path.
             *
             * The rule is now the one the intent implies: strip the directory
             * only when the request actually starts with it. A prefix that is
             * not a prefix is not a subdirectory, whatever PHP_SELF says.
             */
            if (isset($_SERVER['PHP_SELF'])) {
                $directory = rtrim(
                    str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])),
                    '/'
                );
                if ($directory !== ''
                    && $directory !== '.'
                    && str_starts_with($_SERVER['REQUEST_URI'], $directory . '/')
                ) {
                    self::$requestUri = trim(
                        substr($_SERVER['REQUEST_URI'], strlen($directory)),
                        '/'
                    );
                }
            }
        }
        self::$requestUri = str_replace('?{}', '', self::$requestUri);
        if (isset($_GET['r'])) {
            self::$originalRequest=$_GET['r'];
            $this->calcParams();
        }
        unset($_GET['r']);
        if (isset($_SERVER['REQUEST_METHOD'])) {
            self::$requestMethod = $_SERVER['REQUEST_METHOD'];
        } else {
            if (isset($_POST) && count($_POST) != 0) {
                self::$requestMethod = 'POST';
            }
        }
        if (self::$requestMethod == 'PUT') {
            self::$putData = array_merge($this->decodeBody(), self::$putData);
        }

        if (self::$requestMethod == 'DELETE') {
            self::$deleteData = array_merge($this->decodeBody(), self::$deleteData);
        }

        if (self::$requestMethod == 'PATCH') {
            self::$patchData = array_merge($this->decodeBody(), self::$patchData);
        }

        if (self::$requestMethod == 'POST' && count($_POST) == 0) {
            // A form-encoded POST is already in $_POST; this is the JSON case,
            // which PHP does not parse for anybody.
            $_POST = array_merge($this->decodeBody(), $_POST);
        }


        if (self::$requestMethod == 'GET') {
            if (isset($_GET['{}'])) {
                unset($_GET['{}']);
            }

            foreach (array_keys($_GET) as $key) {
                if (\Pramnos\General\Helpers::checkJSON(str_replace("_", " ", $key))) {
                    $getArray = json_decode(
                        str_replace("_", " ", $key),
                        true
                    );
                    if (is_array($getArray)) {
                        $_GET = array_merge($getArray, $_GET);
                    }
                    unset($getArray);
                }
            }
        }

        $this->loadFlashedValidationState();

        parent::__construct();
    }

    /**
     * Return the last option of URL
     * @return mixed
     */
    public function getOption()
    {
        return Request::staticGetOption();

    }

    /**
     * Return the last option of URL
     * @return mixed
     */
    public static function staticGetOption()
    {
        if (isset($_GET['_option'])) {
            return $_GET['_option'];

        } else {
            return null;

        }
    }

    /**
     * The access token presented with the current request, if any.
     *
     * The framework's own header is `accessToken`, but every generic HTTP
     * client, API console and OpenAPI "Authorize" button sends
     * `Authorization: Bearer …` instead — and a request carrying only that was
     * treated as anonymous, which looks like a broken token rather than a
     * header-name mismatch. The standard header is now honoured as a fallback:
     *
     *   1. `accessToken` — unchanged, still wins when present.
     *   2. `Authorization: Bearer <token>`
     *   3. `REDIRECT_HTTP_AUTHORIZATION` — the same header, under the name
     *      Apache gives it when a rewrite swallowed the original.
     *
     * @return string|null The token, or null when the request carries none
     */
    public static function accessToken(): ?string
    {
        $direct = trim((string) ($_SERVER['HTTP_ACCESSTOKEN'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $header) {
            $value = trim((string) ($_SERVER[$header] ?? ''));
            // The scheme is case-insensitive per RFC 7235, and clients do send
            // a lowercase "bearer".
            if (stripos($value, 'bearer ') === 0) {
                $token = trim(substr($value, 7));
                if ($token !== '') {
                    return $token;
                }
            }
        }

        return null;
    }

    /**
     * The client's IP address, honouring forwarding headers only from proxies
     * the application has said it trusts.
     *
     * Every place in the framework that needs to know who is calling — rate
     * limiting, session tracking, audit logging — should ask here rather than
     * reading `$_SERVER['REMOTE_ADDR']`. That variable is the connecting peer,
     * which behind a proxy is the proxy: one address for the whole world.
     *
     * With no `trusted_proxies` configured the answer is `REMOTE_ADDR`, exactly
     * as before. Reading `X-Forwarded-For` without that list would be a genuine
     * regression, not an improvement: the header is client-supplied, so a fresh
     * random value per request would defeat any per-IP control outright.
     *
     * @param string $default Returned when there is no peer at all — CLI, or a
     *                        test that has not populated `$_SERVER`.
     * @return string
     * @see ClientIpResolver
     */
    public static function clientIp(string $default = ''): string
    {
        $resolved = ClientIpResolver::fromApplication()->resolve($_SERVER);

        return $resolved === '' ? $default : $resolved;
    }

    /**
     * Get a user request
     * @param  string $varname name of the request
     * @param  mixed  $default Default value, if variable is not set
     * @param  string $method  Request method. request, post,get,
     *                         files,cookie,env,session,server
     * @param  string $type    Variable type for casting. Example: int
     * @return string
     */
    public function get($varname, $default = null,
        $method = 'request', $type = '')
    {
        return Request::staticGet($varname, $default, $method, $type);

    }

    /**
     * Get a user request
     * @param  string $varname name of the request
     * @param  mixed  $default Default value, if variable is not set
     * @param  string $method  Request method. request, post,get,
     *                         files,cookie,env,session,server
     * @param  string $type    Variable type for casting. Example: int
     * @return string
     */
    public function getArray($varname, $default = null,
        $method = 'request', $type = '')
    {
        $var = Request::staticGet($varname, $default, $method, $type);
        if (is_array($var)) {
            return (object)$var;
        } else {
            return $var;
        }

    }

    /**
     * Get a user request
     * @param  string $varname name of the request
     * @param  mixed  $default Default value, if variable is not set
     * @param  string $method  Request method. request, post,get,files,
     *                         cookie,env,session,server
     * @param  string $type    Optional filter: int | float | bool | string |
     *                         alnum | email | array. Anything else returns the
     *                         raw value — this parameter filters when asked and
     *                         never sanitises by default.
     * @return mixed
     */
    public static function staticGet($varname, $default = null,
        $method = 'request', $type = '')
    {
        $method = strtoupper($method);
        switch ($method) {
            case 'REQUEST':
                $input = &$_REQUEST;
                break;
            case 'GET':
                $input = &$_GET;
                break;
            case 'POST':
                $input = &$_POST;
                break;
            case 'FILES':
                $input = &$_FILES;
                break;
            case 'COOKIE':
                $input = &$_COOKIE;
                break;
            case 'ENV':
                $input = &$_ENV;
                break;
            case 'SESSION':
                $input = &$_SESSION;
                break;
            case 'SERVER':
                $input = &$_SERVER;
                break;
            case 'DELETE':
                $input = &self::$deleteData;
                break;
            case 'PUT':
                $input = &self::$putData;
                break;
            case 'PATCH':
                $input = &self::$patchData;
                break;
            default:
                $input = &$_REQUEST;
                break;
        }
        if (isset($input[$varname])) {
            $return = $input[$varname];
        } else {
            $return = $default;
        }
        // The filters are opt-in and unchanged in effect: anything not named
        // here comes back exactly as it arrived, as it always has. `int` was
        // the only one for years, which quietly suggested the others existed.
        switch ($type) {
            case 'int':
                $return = (int) $return;
                break;
            case 'float':
                $return = (float) $return;
                break;
            case 'bool':
                $return = filter_var($return, FILTER_VALIDATE_BOOL);
                break;
            case 'string':
                $return = is_scalar($return) ? (string) $return : '';
                break;
            case 'alnum':
                $return = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $return);
                break;
            case 'email':
                $email  = filter_var((string) $return, FILTER_VALIDATE_EMAIL);
                $return = $email === false ? '' : $email;
                break;
            case 'array':
                $return = is_array($return) ? $return : [];
                break;
        }

        return $return;
    }



    /**
     * Get the requested controller
     * @return string
     */
    public function getController()
    {
        return self::$_controller;
    }



    /**
     * Set the controller to whatever you want
     * @param  string           $module
     * @return Request
     */
    public function setController($module)
    {
        self::$_controller = $module;

        return $this;
    }

    /**
     * Get the requested action
     * @return string
     */
    public function getAction()
    {
        return self::$action;

    }



    /**
     * Set request action
     * @param string $action
     */
    public function setAction($action = "display")
    {
        self::$action = $action;

        return $this;
    }

    /**
     * Check if the current request is over HTTPS
     * @return boolean
     */
    public function isHttps()
    {
        $https = $_SERVER['HTTPS'] ?? '';
        return $https === 'on' || $https === '1';
    }

    /**
     * Get request URL
     * @param  boolean $relative
     * @return string
     */
    public function getURL($relative = true)
    {
        if ($relative == false) {
            $pageURL = 'http';
            if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
                $pageURL .= "s";
            }
            $pageURL .= "://";
            if (isset($_SERVER["SERVER_PORT"])
                && $_SERVER["SERVER_PORT"] != "80") {
                $pageURL .= $_SERVER["SERVER_NAME"] . ":"
                    . $_SERVER["SERVER_PORT"] . ($_SERVER["REQUEST_URI"] ?? '');
            } elseif (isset($_SERVER['SERVER_NAME'])) {
                $pageURL .= $_SERVER["SERVER_NAME"] . ($_SERVER["REQUEST_URI"] ?? '');
            }
        } else {
            $pageURL = $_SERVER["REQUEST_URI"] ?? '';
        }

        return $pageURL;
    }


    /**
     * Sets a hashed cookie
     * @param string $cookiename
     * @param mixed $value
     * @param integer $time
     * @return boolean
     */
    public function cookieset($cookiename, $value, $time = 0)
    {
        $realCookiename = str_rot13($cookiename);

        $prefix = substr(md5('pcms'), 0, 10);
        $name = $prefix . '[' . $realCookiename . ']';
        if ($time == 0) {
            $time = time() + 3600 * 24 * 14; //2 weeks
        }

        if (!headers_sent()) {
            return setcookie($name, (string)$value, [
                'expires' => $time,
                'path' => '/',
                'domain' => '',
                'secure' => $this->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            return false;
        }
    }

    /**
     * Retreives a hashed cookie
     * @param  string $cookiename
     * @return string
     */
    public function cookieget($cookiename)
    {
        $realCookiename = str_rot13($cookiename);
        $prefix = substr(md5('pcms'), 0, 10);
        #$realCookiename = $prefix . '[' . $realCookiename . ']'; //WTF?
        if (isset($_COOKIE[$prefix])
            && isset($_COOKIE[$prefix][$realCookiename])) {
            return $_COOKIE[$prefix][$realCookiename];
        } else {
            return null;
        }
    }

    /**
     * Get request controller
     * @deprecated since version 1.0
     * @return string
     */
    public function getModule()
    {
        return $this->getController();
    }

    /**
     * Set request controller
     * @deprecated since version 1.0
     * @param string $module
     * @return Request
     */
    public function setModule($module)
    {
        return $this->setController($module);
    }

    /**
     * Get the request method
     * @return string
     */
    public function getRequestMethod()
    {
        return self::$requestMethod;
    }

    /**
     * Get ther request URI
     * @return string
     */
    public function getRequestUri()
    {
        return self::$requestUri;
    }

    /**
     * Get all input data from a specific method source
     * @param string|null $method
     * @return array
     */
    public function all($method = null): array
    {
        if ($method === null) {
            return $this->allCurrent();
        }

        $method = strtoupper($method);

        switch ($method) {
            case 'REQUEST':
                return $_REQUEST;
            case 'GET':
                return $_GET;
            case 'POST':
                return $_POST;
            case 'PUT':
                return self::$putData;
            case 'DELETE':
                return self::$deleteData;
            case 'PATCH':
                return self::$patchData;
            case 'FILES':
                return $_FILES;
            case 'COOKIE':
                return $_COOKIE;
            case 'ENV':
                return $_ENV;
            case 'SESSION':
                return $_SESSION;
            case 'SERVER':
                return $_SERVER;
            default:
                return $_REQUEST;
        }
    }

    /**
     * Get all input data for the current request method
     * @return array
     */
    public function allCurrent(): array
    {
        switch (strtoupper($this->getRequestMethod())) {
            case 'POST':
                return $_POST;
            case 'PUT':
                return self::$putData;
            case 'DELETE':
                return self::$deleteData;
            case 'PATCH':
                return self::$patchData;
            case 'GET':
            default:
                return $_GET;
        }
    }

    /**
     * Get only the specified keys from the input
     * @param array $keys
     * @param string|null $method
     * @return array
     */
    public function only(array $keys, $method = null): array
    {
        $data = $this->all($method);
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    /**
     * Validate request input data
     *
     * @param array $rules
     * @param array $messages
     * @param array $attributes
     * @param string|null $method
     * @return array
     *
     * @throws ValidationException
     */
    public function validate(
        array $rules,
        array $messages = [],
        array $attributes = [],
              $method = null
    ): array {
        return \Pramnos\Validation\Validator::validate(
            $this->all($method),
            $rules,
            $messages,
            $attributes
        );
    }

    /**
     * Get flashed validation errors from session and optionally clear them
     *
     * @return array
     */
    public function errors(): array
    {
        if (self::$validationErrors === null) {
            $this->loadFlashedValidationState();
        }

        return is_array(self::$validationErrors) ? self::$validationErrors : array();
    }

    /**
     * Flashed messages written by `Base::addMessage()` on the previous request.
     *
     * ## Why this exists
     *
     * `addMessage()` and `addError()` have written `$_SESSION['_messages']` and
     * `$_SESSION['_errors']` for as long as the framework has had them, and **nothing ever
     * read them back**: `Base::_getMessages()` and `_getErrors()` are `protected` and were
     * called from nowhere in the framework. So the flash mechanism the guides recommend had
     * no display side at all, and sixty-seven controller redirects carried `?error=…` query
     * parameters instead — which nothing read either.
     *
     * Captured and cleared exactly like validation errors, by the same method, so a message
     * survives one redirect and is not shown again on a reload. That is the whole point of a
     * flash, and it is what a query parameter cannot do.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        if (self::$flashMessages === null) {
            $this->loadFlashedValidationState();
        }

        return is_array(self::$flashMessages) ? self::$flashMessages : array();
    }

    /**
     * Flashed errors written by `Base::addError()` on the previous request.
     *
     * Not {@see errors()}, which is the per-field output of a validator. These are sentences.
     *
     * @return array<int, string>
     */
    public function flashErrors(): array
    {
        if (self::$flashErrors === null) {
            $this->loadFlashedValidationState();
        }

        return is_array(self::$flashErrors) ? self::$flashErrors : array();
    }

    /**
     * Flashed messages, consumed: the captured bag is emptied.
     *
     * The destructive counterpart to {@see messages()}. `Base::_getMessages()` uses this
     * because its documented behaviour is one-shot — the second call in a request answers
     * `false` — and a non-destructive fallback would have quietly removed that guarantee.
     *
     * A `View` that has already copied the bag into `$messages` keeps its copy: the snapshot is
     * taken when the view is constructed, which is before a theme's header renders. That is
     * deliberate, because both readers must be able to see the flash — an application whose
     * header prints it and whose template also prints it will print it twice, and choosing
     * which one prints is the application's call, not the framework's.
     *
     * @return array<int, string>
     */
    public function takeMessages(): array
    {
        $messages = $this->messages();
        self::$flashMessages = array();

        return $messages;
    }

    /**
     * Flashed errors, consumed.
     *
     * @return array<int, string>
     */
    public function takeFlashErrors(): array
    {
        $errors = $this->flashErrors();
        self::$flashErrors = array();

        return $errors;
    }

    /**
     * Get old input from session and optionally clear it
     *
     * @param string|null $key
     * @param mixed|null $default
     * @return mixed
     */
    public function old($key = null, $default = null)
    {
        if (self::$oldInput === null) {
            $this->loadFlashedValidationState();
        }

        if ($key === null) {
            return self::$oldInput;
        }

        return self::$oldInput[$key] ?? $default;
    }

    /**
     * @return void
     */
    public function clearValidationState(): void
    {
        self::$validationErrors = array();
        self::$oldInput = array();

        self::$flashMessages = array();
        self::$flashErrors = array();
        unset(
            $_SESSION['_validation_errors'],
            $_SESSION['_old_input'],
            $_SESSION['_messages'],
            $_SESSION['_errors']
        );
    }

    /**
     * Load flashed validation data from session for the current request.
     * Data is removed from session after loading, but remains available
     * in this request through static properties.
     *
     * @return void
     */
    protected function loadFlashedValidationState()
    {
        if (self::$validationErrors !== null && self::$oldInput !== null
            && self::$flashMessages !== null && self::$flashErrors !== null
        ) {
            return;
        }

        self::$validationErrors = isset($_SESSION['_validation_errors'])
        && is_array($_SESSION['_validation_errors'])
            ? $_SESSION['_validation_errors']
            : array();

        self::$oldInput = isset($_SESSION['_old_input'])
        && is_array($_SESSION['_old_input'])
            ? $_SESSION['_old_input']
            : array();

        self::$flashMessages = isset($_SESSION['_messages'])
        && is_array($_SESSION['_messages'])
            ? array_values($_SESSION['_messages'])
            : array();

        self::$flashErrors = isset($_SESSION['_errors'])
        && is_array($_SESSION['_errors'])
            ? array_values($_SESSION['_errors'])
            : array();

        unset(
            $_SESSION['_validation_errors'],
            $_SESSION['_old_input'],
            $_SESSION['_messages'],
            $_SESSION['_errors']
        );
    }
}
