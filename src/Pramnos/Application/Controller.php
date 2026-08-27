<?php
namespace Pramnos\Application;
/**
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Controller extends \Pramnos\Framework\Base
{
    /**
     * Actions allowed to be executed
     * @var array
     */
    public $actions = array();
    /**
     * Actions allowed to be executed if user has permission
     * @var array
     */
    public $actions_auth = array();

    /**
     * Permissions required for an action
     * @var array
     */
    protected $action_permissions = array();

    /**
     * User permissions
     * @var array
     */
    protected $user_permissions = array();

    /**
     * Whether the current user may do something.
     *
     * A short form of `\Pramnos\Auth\Gate::allows()` for the place it is asked most —
     * inside an action, about the thing the action is holding.
     *
     * ```php
     * if (!$this->can('update-post', $post)) {
     *     return $this->redirect('/posts');
     * }
     * ```
     *
     * This is the *rule* layer. `auth()` on this class is the older, data-driven check
     * against `$actions_auth` and `$action_permissions`, and the two are complementary
     * rather than alternatives — see the Authorization guide.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return bool True when allowed
     */
    public function can(string $ability, mixed ...$arguments): bool
    {
        return \Pramnos\Auth\Gate::allows($ability, ...$arguments);
    }

    /**
     * Whether the current user may **not** do something.
     *
     * The inverse of {@see can()}, for the guard-clause shape that reads better negated.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return bool True when refused
     */
    public function cannot(string $ability, mixed ...$arguments): bool
    {
        return !$this->can($ability, ...$arguments);
    }

    /**
     * Controller Title
     * @var string
     */
    public $title = '';
    /**
     * Controller Name
     * @var string
     */
    public $controllerName = '';
    /**
     * Array of breadcrumbs used in the controller
     * @var array
     */
    public $breadcrumbs = array();
    /**
     * Application
     * @var \Pramnos\Application\Application
     */
    public $application = null;

    /**
     * Extra paths to check for views
     * BEFORE looking on normal path
     * @var array
     */
    protected $_priorityPaths = array();


    /**
     * Extra paths to check for views
     * if main paths are not found
     * @var array
     */
    protected $_extraPaths = array();

    /**
     * Extra paths to check for views
     * if main paths after application
     * @var array
     */
    protected $_lastPaths = array();

    /**
     * When a controller extends another controller
     * @var string
     */
    protected $_extends=NULL;

    /**
     * Per-action middleware stack.
     * Key '*' applies to every action.
     * @var array<string, array<\Pramnos\Http\MiddlewareInterface|class-string>>
     */
    private array $_middlewares = [];

    /**
     * Attach middleware to one or more controller actions.
     *
     * Use '*' to apply the middleware to every action in this controller.
     * The middleware runs AFTER the existing auth() permission check and
     * BEFORE the action method is called — so it never bypasses existing auth.
     *
     * Usage in __construct() (or init()):
     *   $this->addMiddleware('*',                  new ThrottleMiddleware(60, 60));
     *   $this->addMiddleware(['edit', 'delete'],    new AuthMiddleware());
     *   $this->addMiddleware('export',             ThrottleMiddleware::class);
     *
     * @param  string|array<string>                          $actions  Action name(s) or '*'.
     * @param  \Pramnos\Http\MiddlewareInterface|class-string $middleware
     * @return static
     */
    public function addMiddleware(string|array $actions, \Pramnos\Http\MiddlewareInterface|string $middleware): static
    {
        foreach ((array) $actions as $action) {
            $this->_middlewares[$action][] = $middleware;
        }
        return $this;
    }

    /**
     * Run the middleware stack (global '*' + action-specific) around $callback.
     * When no middleware is registered the callback is called directly — identical
     * to the pre-middleware code path.
     */
    private function _runThroughMiddleware(string $action, callable $callback): mixed
    {
        $mws = array_merge(
            $this->_middlewares['*'] ?? [],
            $this->_middlewares[$action] ?? []
        );

        if (empty($mws)) {
            return $callback();
        }

        $request  = new \Pramnos\Http\Request();
        $pipeline = new \Pramnos\Http\MiddlewarePipeline();
        foreach ($mws as $mw) {
            $pipeline->pipe($mw);
        }

        return $pipeline->run($request, fn(\Pramnos\Http\Request $r) => $callback());
    }

    /**
     * Adds a public action to the controller
     * @param string $action It should be a public method of the object
     */
    public function addaction($action)
    {
        if (is_array($action)) {
            foreach ($action as $act) {
                $this->actions[] = $act;
            }
        } else {
            $this->actions[] = $action;
        }
    }

    /**
     * Adds an action to the controller for logged in users
     * @param string $action It should be a public method of the object
     */
    public function addAuthAction($action){
        if (is_array($action)) {
            foreach ($action as $act) {
                $this->actions_auth[] = $act;
            }
        } else {
            $this->actions_auth[] = $action;
        }
    }

    /**
     * Adds a required permission to an action
     * @param string|array $action
     * @param string|array $permissions
     */
    public function addActionPermission($action, $permissions)
    {
        if (is_array($action)) {
            foreach ($action as $act) {
                $this->addActionPermission($act, $permissions);
            }
            return;
        }

        if (!isset($this->action_permissions[$action])) {
            $this->action_permissions[$action] = [];
        }

        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        $this->action_permissions[$action] = array_merge($this->action_permissions[$action], $permissions);
    }

    public function getBreadcrumbs()
    {
        return $this->breadcrumbs;
    }

    public function addBreadcrumb($item, $url = NULL)
    {
        $this->breadcrumbs[] = array('item' => $item, 'url' => $url);
        return $this;
    }

    /**
     * Force redirect of the page to another url
     * @param string  $url Url to redirect to
     * @param boolean $quit If you want to quit after redirecting.
     * @param string  $code Forces HTTP response code to the specified value.
     */
    public function redirect($url = null, $quit = true, $code='302')
    {
        $this->application->redirect($url, $quit, $code);
    }

    /**
     * Controller constructor
     * @param \Pramnos\Application\Application $application
     * @param array|string $userPermissions
     */
    public function __construct(
        ?\Pramnos\Application\Application $application = null,
        $userPermissions = []
    )
    {
        $this->application = $application;
        if ($application == null) {
            $this->application
                = \Pramnos\Application\Application::getInstance();
        }
        $this->user_permissions = $this->_auth_normalizePermissions($userPermissions);
        $this->controllerName = (new \ReflectionClass($this))->getShortName();
        $this->actions[] = 'display';
        parent::__construct();
    }

    /**
     * Execute a controller action if user is authorized.
     * Default action is display.
     * @param string $action
     * @param array $args
     */
    public function exec($action = '', $args = array())
    {
        if ($action === '') {
            $action = 'display';
        }
        if ($action == 'display') {
            $this->addBreadcrumb($this->title);
        }
        if (\Pramnos\Http\Request::$requestMethod != 'GET') {
            $actionWithMethod = strtolower(
                \Pramnos\Http\Request::$requestMethod . ucfirst($action)
            );
            if (method_exists($this, $actionWithMethod)
                && $this->auth($action)
                && $this->auth($actionWithMethod)) {
                return $this->_runThroughMiddleware(
                    $actionWithMethod,
                    fn() => $this->$actionWithMethod($args)
                );
            }
        }
        if (array_search($action, $this->actions) !== false
                || array_search($action, $this->actions_auth) !== false) {
            if ($this->auth($action)) {
                return $this->_runThroughMiddleware(
                    $action,
                    fn() => $this->$action($args)
                );
            } else {
                $this->_throwAuthFailure();
            }
        } elseif (array_search('display', $this->actions) !== false) {
            if ($this->auth('display')) {
                return $this->_runThroughMiddleware(
                    'display',
                    fn() => $this->display($args)
                );
            } else {
                $this->_throwAuthFailure();
            }
        }
    }

    /**
     * Default action.
     *
     * **Declares no parameter, and must not.** `exec()` calls
     * `$this->display($args)`, so a controller that wants the request's
     * arguments declares `display(array $args = [])` and gets them — which is
     * what every scaffolded controller does, and what the guides show.
     *
     * Declaring the parameter here looks like the honest fix and is a breaking
     * one: PHP requires a child to accept at least what its parent accepts, so
     * every existing `function display()` with no argument — including
     * `LogController` in this framework — becomes a fatal
     * "must be compatible with" on upgrade. The extra argument PHP discards is
     * the mechanism that makes the parameter opt-in.
     *
     * That is the difference from the discarded *password* arguments fixed on
     * 2026-08-27: those were a caller believing a check happened. This one is a
     * documented dispatch convention, and the arguments reach anybody who asks
     * for them.
     */
    function display()
    {

    }

    /**
     * Throw the appropriate exception when an auth check fails.
     *
     * Unauthenticated users (not logged in) are redirected to the login page
     * so they get a useful response instead of a bare 403.
     * Authenticated users who lack the required permission still get 403.
     *
     * @throws \Pramnos\Http\RedirectException when the session is not logged in
     * @throws \Exception (403) when the user is logged in but lacks permission
     */
    private function _throwAuthFailure(): never
    {
        $session = \Pramnos\Http\Session::getInstance();
        if (!$session->isLogged() && defined('sURL')) {
            $loginUrl = sURL . 'login';
            $current  = \Pramnos\Http\Request::$requestUri ?? '';
            if ($current !== '' && $current !== '/') {
                $loginUrl .= '?return=' . urlencode($current);
            }
            throw new \Pramnos\Http\RedirectException($loginUrl);
        }
        throw new \Exception('Not authenticated users cannot do that.', 403);
    }

    /**
     * Check if a user can execute a controller action
     * @param string $action
     * @return boolean
     * @throws \Exception
     */
    public function auth($action)
    {
        $session = \Pramnos\Http\Session::getInstance();
        if (array_search($action, $this->actions_auth) !== false) {
            if ($session->isLogged() != true) {
                return false;
            }
        }

        // If we have user permissions, check them
        if (!empty($this->user_permissions)) {
            if (isset($this->action_permissions[$action])) {
                $required_permissions = $this->action_permissions[$action];
                if (!$this->_auth_hasPermissions($required_permissions, $this->user_permissions)) {
                    throw new \Exception(
                        'You do not have the required permissions to perform this action.',
                        403
                    );
                }
            }
        }

        return true;
    }


    /**
     * Get a model
     * @param string $name Model name
     * @return \Pramnos\Application\Model
     * @throws \Exception
     */
    public function &getModel($name = '')
    {
        if (isset($this->application->applicationInfo['namespace'])) {
            if ($this->application->appName == '') {
                $class = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\Models\\'
                    . $name;
            } else {
                $class = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\'
                    . $this->application->appName
                    . '\\Models\\'
                    . $name;
            }
        } elseif ($this->application->appName == '') {
            $class = '\\Pramnos\\Models\\'
                . $name;
        } else {
            $class = '\\Pramnos\\'
                . $this->application->appName
                . '\\Models\\'
                . $name;
        }
        if (class_exists($class)) {
            $model = new $class($this, $name);
            return $model;
        }
        if (class_exists(str_replace($name, ucfirst($name), $class))) {
            $class = str_replace($name, ucfirst($name), $class);
            $model = new $class($this, ucfirst($name));
            return $model;
        }
        throw new \Exception(
            'Cannot find model: ' . $name . ' (Class: ' . $class . ')'
        );


    }





    /**
     * Check if a user has the required permissions.
     *
     * @param array $requiredPermissions The permissions required by the route
     * @param array $userPermissions The permissions that the current user has
     * @return bool True if the user has the required permissions, false otherwise
     */
    protected function _auth_hasPermissions($requiredPermissions, $userPermissions = array())
    {
        $requiredPermissions = $this->_auth_normalizePermissions($requiredPermissions);
        $userPermissions = $this->_auth_normalizePermissions($userPermissions);

        if (empty($requiredPermissions)) {
            return true;
        }

        foreach ($requiredPermissions as $requiredScope) {
            if ($this->_auth_hasScope($requiredScope, $userPermissions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize permissions to an array.
     *
     * @param array|string $permissions The permissions to normalize
     * @return array The normalized permissions
     */
    protected function _auth_normalizePermissions($permissions)
    {
        if (is_string($permissions)) {
            return explode(' ', $permissions);
        }
        return (array) $permissions;
    }

    /**
     * Check if a user has a specific scope.
     *
     * @param string $requiredScope The required scope
     * @param array $userScopes The scopes that the user has
     * @return bool True if the user has the required scope, false otherwise
     */
    protected function _auth_hasScope($requiredScope, $userScopes)
    {
        if (in_array($requiredScope, $userScopes)) {
            return true;
        }

        // Check for wildcard matches
        foreach ($userScopes as $userScope) {
            if ($this->_auth_wildcardMatch($requiredScope, $userScope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a required scope matches a user scope with wildcards.
     *
     * @param string $requiredScope The required scope (e.g., "posts:edit")
     * @param string $userScope The user's scope (e.g., "posts:*")
     * @return bool True if the scopes match, false otherwise
     */
    protected function _auth_wildcardMatch($requiredScope, $userScope)
    {
        if (strpos($userScope, '*') === false) {
            return false;
        }

        $pattern = '/^' . str_replace('\*', '.*', preg_quote($userScope, '/')) . '$/';
        return preg_match($pattern, $requiredScope) === 1;
    }

    /**
     *
     * @param string $path
     * @param string $name
     * @param string $type
     * @return \pramnos_application_view|\classname|boolean
     * @throws \Exception
     */
    /**
     * @param array $args Accepted and unused, and declared so that saying so is
     *                    possible: `getView()` has always advertised it and
     *                    passed it here, this method declared three parameters,
     *                    and PHP dropped the fourth silently. Nothing downstream
     *                    consumes it — `View::__construct()` takes no arguments
     *                    array — so it is retained for the callers that pass it
     *                    rather than quietly discarded.
     */
    private function _getView($path, $name, $type, $args = array())
    {
        if ($type === '') {
            $doc = \Pramnos\Framework\Factory::getDocument();
            $type = $doc->type;
        }
        $tp = $path . DS . 'Views' . DS . $name;
        if (!file_exists($tp)) { // Check if template path exists
            $tp = $path . DS . 'views' . DS . $name;
            if (!file_exists($tp)) { // Check if template path exists
                return false;
            }
        }

        if (!is_dir($tp)){
            throw new \Exception('View is not a directory');
        }

        /**
         * Search for the right view class
         */

        if ($this->application !== null && isset($this->application->applicationInfo['namespace'])) {
            if ($this->application->appName != '') {
                $className = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\'
                    . $this->application->appName
                    . '\\Views\\'
                    . $name;
            } else {
                $className = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\Views\\'
                    . $name;
            }
        } else {
            if ($this->application !== null && $this->application->appName != '') {
                $className = '\\Pramnos\\'
                    . $this->application->appName
                    . '\\Views\\'
                    . $name;
            } else {
                $className = '\\Pramnos\\Views\\'
                    . $name;
            }
        }
        if (class_exists($className)) {
            $view = new $className($this);
            return $view;
        }
        // The view-group directory ($tp) exists and holds its templates, so bind the
        // View to it: display('anyTemplate') then resolves $tp/anyTemplate.<type>.php.
        //
        // Historically this only happened when the group also contained a default
        // "{group}" or "view" template; a group that shipped only secondary templates
        // (e.g. passkey/manage, account/profile — no passkey/account default) fell back
        // to $path, the PARENT directory, so every display('sub') resolved a
        // non-existent $path/sub.<type>.php and rendered nothing (blank page).
        // Binding to $tp unconditionally is correct — the templates live in $tp — and
        // is a no-op for groups that already had a default template.
        return new \Pramnos\Application\View($this, $tp, $name, $type);
    }



    /**
     * Where this installation keeps its applications.
     *
     * `APPS_PATH` when defined, because that is the constant whose whole purpose is to
     * answer this, and {@see \Pramnos\Translator\StringFinder} already reads it. The
     * legacy controller built the same fallback from it; this one started deriving the
     * answer from `INCLUDES` instead, which describes where the *code* lives. They are
     * the same directory in a stock layout and different ones the moment an installation
     * moves its applications — and then this search path points at a directory that does
     * not exist, silently, because a fallback that finds nothing looks exactly like a
     * view that is genuinely absent.
     *
     * Falls back to the old expression, so an installation that defines no `APPS_PATH`
     * searches exactly where it searched before.
     *
     * @return string
     */
    protected static function applicationsBasePath()
    {
        if (defined('APPS_PATH') && APPS_PATH !== '') {
            return rtrim((string) APPS_PATH, DS);
        }

        return ROOT . DS . INCLUDES;
    }

    /**
     * Gets a pramnos_application_view object
     * @param string $name
     * @param string $type
     * @param array $args Accepted for compatibility and not used — see
     *                    {@see _getView()}. It was passed on to a method that
     *                    did not declare it, so it has never reached anything.
     * @return \Pramnos\Application\View
     */
    function &getView($name = '', $type = '', $args = array())
    {
        $paths = array_merge(
            $this->_priorityPaths,
            $this->_extraPaths
        );
        foreach ($paths as $path){
            $view = $this->_getView($path, $name, $type, $args);
            if ($view){
                return $view;
            }
        }
        // In case we can't find the view, we search in Application path.
        // Check for app extra paths
        if ($this->application !== null) {
            $base = static::applicationsBasePath();

            if ($this->application->appName == '') {
                $appPaths = array_merge(
                    array($base),
                    $this->application->getExtraPaths(),
                    $this->_lastPaths
                );
            } else {
                $appPaths = array_merge(
                    array($base . DS . $this->application->appName),
                    $this->application->getExtraPaths(),
                    $this->_lastPaths
                );
            }

            foreach ($appPaths as $path) {
                $view = $this->_getView($path, $name, $type, $args);
                if ($view){
                    return $view;
                }
            }
        }

        // Framework scaffolding fallback — try bundled theme views so auth
        // flows work out of the box without requiring a scaffold step.
        $fallbackDirs = $this->_getScaffoldingFallbackDirs();
        foreach ($fallbackDirs as $fallbackDir) {
            $view = $this->_getView($fallbackDir, $name, $type);
            if ($view) {
                return $view;
            }
        }

        if ($type == '') {
            $doc = \Pramnos\Framework\Factory::getDocument();
            $type = $doc->type;
        }
        \Pramnos\Logs\Logger::log(
            'Cannot find view: '
            . $name
            . ' (type: ' . $type . ', class: ' . $name . ')'
        );
        throw new \Exception(
            'Cannot find view: '
            . $name
            . ' (type: ' . $type . ', class: ' . $name . ')'
        );
    }

    /**
     * Return the list of scaffolding theme directories to use as a final
     * view-lookup fallback.
     *
     * If the application config contains a `scaffold_theme` key (set by
     * `pramnos init`), only that theme's directory is returned.
     * Otherwise every bundled theme directory is returned so projects
     * that pre-date scaffold_theme tracking still benefit from the fallback.
     *
     * @return string[]
     */
    private function _getScaffoldingFallbackDirs(): array
    {
        $raw          = $this->application->applicationInfo ?? [];
        $info         = is_array($raw) ? $raw : [];
        $scaffoldTheme = \Pramnos\Application\ScaffoldingHelper::getScaffoldTheme($info);
        if ($scaffoldTheme !== null) {
            $dir = \Pramnos\Application\ScaffoldingHelper::getThemeDir($scaffoldTheme);
            return is_dir($dir) ? [$dir] : [];
        }
        return \Pramnos\Application\ScaffoldingHelper::getAvailableThemeDirs();
    }


    /**
     * Set content type for the theme
     * @param string $contentType
     */
    public function setContentType($contentType)
    {
        $document = \Pramnos\Document\Document::getInstance();
        if ($document->themeObject !== NULL) {
            $document->themeObject->setContentType($contentType);
        }
    }

}
