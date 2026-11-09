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
     * The administration ability this screen is, e.g. `admin.users` — its menu item's id.
     *
     * Declared by every administration screen. It makes {@see requireMinUserType()} ask
     * {@see \Pramnos\Auth\AdminAccess} rather than compare usertypes, which under
     * `admin_access = permissions` means the screen opens for whoever was granted it.
     * Empty for everything that is not an administration screen.
     */
    protected string $adminAbility = '';

    /**
     * Actions of an administration screen that answer without its ability — a public
     * monitor endpoint on the same controller, say. Everything else is checked by
     * {@see exec()} before it runs.
     *
     * @var list<string>
     */
    protected array $adminPublicActions = [];

    /**
     * What one record of this screen is — `user`, `post` — when its actions take the record's
     * id in the address: `/admin/Users/edit/5` is user 5 to the side panel and to a question
     * asked from it. See {@see \Pramnos\Assist\Page}.
     */
    protected string $assistSubject = '';

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
     * Actions that change state, and so accept only a `POST` carrying this session's token.
     *
     * @var list<string> Lowercased action names
     */
    protected array $writeActions = [];

    /**
     * Declare actions that delete, revoke, remove, reset, send or save.
     *
     * {@see exec()} refuses such an action unless it arrives as a `POST` with a token this
     * session issued — before the action runs, so the action needs no check of its own. One
     * line in the constructor covers a controller:
     *
     * ```php
     * $this->addWriteAction(['delete', 'lock', 'unlock', 'grantpermission']);
     * ```
     *
     * Why: a state change reachable by a `GET` link can be made by **any page** a signed-in
     * user opens (`<img src="…/users/delete/5">` needs no click), and a `POST` without the token
     * by a form on any site. Links to these actions are rendered as
     * {@see \Pramnos\Html\Icon::postButton()} or a form with the session's token field.
     *
     * @param string|list<string> $action
     */
    public function addWriteAction($action): static
    {
        foreach ((array) $action as $act) {
            $this->writeActions[] = strtolower((string) $act);
        }

        return $this;
    }

    /**
     * Actions that write `$_SESSION` on a `GET` and so must keep the session lock, even
     * though they cannot be (or are not) declared a {@see $writeActions} write.
     *
     * @var list<string> Lowercased action names
     */
    protected array $sessionActions = [];

    /**
     * Declare an action that writes `$_SESSION` itself and so needs the lock kept, even
     * under {@see releaseSessionOnRead()}, without declaring it a CSRF-checked write.
     *
     * Two real shapes neither fit {@see addWriteAction()}: an OAuth callback a third-party
     * provider redirects back to with a `GET` — there is no token to ask that provider to
     * carry, and the callback still has to write the exchanged tokens/state into the
     * session — and a console re-authentication that overwrites the session's own user.
     * Both are already gated by {@see addAuthAction()}; this only keeps the lock for them.
     *
     * ```php
     * $this->addAuthAction(['oauthcallback', 'reauth']);
     * $this->addSessionAction(['oauthcallback', 'reauth']);
     * $this->releaseSessionOnRead();
     * ```
     *
     * @param string|list<string> $action
     */
    public function addSessionAction($action): static
    {
        foreach ((array) $action as $act) {
            $this->sessionActions[] = strtolower((string) $act);
        }

        return $this;
    }

    /**
     * Whether a non-write action releases the session lock before it runs.
     *
     * @see releaseSessionOnRead()
     */
    protected bool $releaseSessionOnRead = false;

    /**
     * Release the session's write lock before any action runs that is in neither
     * {@see $writeActions} nor {@see $sessionActions}, instead of holding it for the
     * whole request.
     *
     * PHP's default file session handler locks the session file for the
     * entire request. An admin UI where one session routinely has 20-30 tabs
     * open serializes all of them behind whichever one is slowest (a video
     * stream, a long poll) — FW-309 measured this up to 150s in production.
     * A declared write action, or one declared via {@see addSessionAction()},
     * still keeps the lock for its whole run, since it may need to write
     * {@see $_SESSION} itself.
     *
     * Opt-in per controller, and only useful alongside {@see addWriteAction()}: a
     * controller that never declares a write action has nothing to release early from,
     * since every action is already free to write the session for its entire run.
     *
     * @return static
     */
    public function releaseSessionOnRead(): static
    {
        $this->releaseSessionOnRead = true;

        return $this;
    }

    /**
     * Close the session's write lock, if one is open, and arm a shutdown
     * check that logs if the action still wrote to `$_SESSION` afterward.
     *
     * A write after `session_write_close()` is never persisted and PHP
     * gives no warning of it — this turns that silent data loss into a log
     * line instead, since the action is trusted not to need the lock at all
     * but has not been audited for it.
     */
    private function _releaseSessionLock(string $action): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Seed the synchronizer CSRF token before the lock closes. `Session::getCsrfToken()`
        // writes it to `$_SESSION` lazily, on whichever request first asks for one — and a
        // read action that releases the lock before any write action ever reaches
        // `CsrfMiddleware::tokenField()` would otherwise render that token into a form from
        // memory without ever persisting it. Every subsequent write action then fails
        // `isVerifiedWrite()` with nothing to compare against — found in production by this
        // method's own safety net below, on a controller's first read action after it opted
        // into `releaseSessionOnRead()`.
        \Pramnos\Http\Session::getInstance()->getCsrfToken();

        $before = $_SESSION;
        session_write_close();

        register_shutdown_function(
            fn() => $this->_warnIfSessionChangedAfterRelease($action, $before)
        );
    }

    /**
     * The safety net {@see _releaseSessionLock()} arms: a write to
     * `$_SESSION` after `session_write_close()` is never persisted and PHP
     * raises nothing for it, so this logs it instead of letting it vanish
     * unnoticed. Split out from `_releaseSessionLock()` so it can run
     * outside a real shutdown, for tests.
     */
    private function _warnIfSessionChangedAfterRelease(string $action, array $before): void
    {
        if ($_SESSION === $before) {
            return;
        }

        \Pramnos\Logs\Logger::log(
            sprintf(
                '%s::%s released the session lock as a read action, '
                . 'but $_SESSION still changed afterward (lost on close).',
                static::class,
                $action
            ),
            'session-lock',
            'log',
            false,
            ['type' => 'warning']
        );
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
        if ($this->answeringApi) {
            throw new \Pramnos\Application\Api\ScreenAnswer(\Pramnos\Application\Api\ScreenAnswer::REDIRECT, (string) $url);
        }
        $this->application->redirect($url, $quit, $code);
    }

    /**
     * Whether this screen is answering the application's API rather than drawing a page.
     */
    protected bool $answeringApi = false;

    /**
     * Answer the API, not the browser: from here on a redirect, a refusal and the view's
     * `display()` end the action with a {@see \Pramnos\Application\Api\ScreenAnswer} that
     * {@see \Pramnos\Application\Api\ScreenApi} turns into the API's answer. The person is the
     * API's — a bearer token or the page's session with its CSRF header, both checked before the
     * route ran — and the screen's floor, `auth()` and write checks still apply.
     */
    public function answerTheApi(): static
    {
        $this->answeringApi = true;

        return $this;
    }

    /** Whether {@see answerTheApi()} was called. */
    public function isAnsweringApi(): bool
    {
        return $this->answeringApi;
    }

    /**
     * End the request — and do not end the process when something else is using it.
     *
     * **A bare `exit` inside a test runner is worse than a failure.** PHPUnit's process
     * simply stops: no summary, no failure count, and **exit status 0**, which every CI
     * reads as a pass. A test that walked a project's controllers truncated the run at 126
     * of 198 and printed a grant page where the summary should have been.
     *
     * This lived in eight controllers as eight copies of `protected function terminate()`,
     * in three different behaviours: five exited unconditionally, `Adminer` returned under
     * PHPUnit, and `DevPanelController` threw under `PRAMNOS_TESTING`. Each copy carried a
     * comment saying it "can be mocked in tests" — which is a seam, and a seam only helps
     * somebody who already knows they need it. The ninth controller to end a request would
     * have been written by somebody who did not.
     *
     * So it is here, once, and the default is right. A subclass that needs different
     * behaviour still overrides it; nobody has to.
     *
     * **It throws rather than returning**, matching `Application::close()` and the type it
     * throws, so a caller that already catches `ApplicationClosedException` around one part
     * of the framework catches it around this one too. Returning would let the code after
     * the call run in a request that had decided it was finished — which is a different bug
     * and a quieter one.
     *
     * The status is read back from PHP rather than passed in: every call site has already
     * set it with `http_response_code()` by the time it gets here, and a parameter would be
     * a signature change for everybody who has overridden this.
     *
     * @throws \Pramnos\Application\ApplicationClosedException Under any test runner
     * @return void
     */
    protected function terminate(): void
    {
        // Three constants because there are three ways to be under a runner and only one of
        // them is ours: `TestEnvironment::setup()` defines `PRAMNOS_TESTING` for a project
        // that uses the framework's own bootstrap, and PHPUnit defines one of the other two
        // whichever way it was installed. A project running PHPUnit without our bootstrap
        // is exactly the case that got this wrong.
        if (defined('PRAMNOS_TESTING')
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
        ) {
            $status = http_response_code();

            throw new \Pramnos\Application\ApplicationClosedException(
                static::class . '::terminate() called',
                is_int($status) ? $status : 200
            );
        }

        exit;
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
        $this->applyDefaultAdminFloor();
        parent::__construct();
    }

    /**
     * A framework screen's floor is {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}.
     *
     * The framework's controllers declare `$requiredUserType` with a literal, and every action
     * reads the property. Replaced here only while it is still the framework's own declaration
     * at its declared value: an application that redeclares the property, or sets it, keeps
     * what it said.
     */
    private function applyDefaultAdminFloor(): void
    {
        if ($this->adminAbility === '' || !property_exists($this, 'requiredUserType')) {
            return;
        }

        $property = new \ReflectionProperty($this, 'requiredUserType');
        // Declared in the framework's own source — not the namespace, which a test or an
        // application may share.
        if (str_starts_with((string) $property->getDeclaringClass()->getFileName(), dirname(__DIR__) . DIRECTORY_SEPARATOR)
            && $property->hasDefaultValue()
            && $this->requiredUserType === $property->getDefaultValue()) {
            $this->requiredUserType = \Pramnos\Auth\AdminAccess::defaultUsertype();
        }
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
        \Pramnos\Assist\Page::entered($this, (string) $action, $this->assistSubject);
        // An administration screen is checked here, once, before any action runs — so an
        // action that forgets its own check is still behind the screen's ability. The floor
        // is the screen's own `$requiredUserType` where it declares one, which is what
        // decides under `admin_access = usertype`.
        //
        // Inside the administration area only, where there is one: an application that
        // extends a screen to serve a public page outside it — a status page built on the
        // Health screen — is not an administration screen there. An action that calls
        // requireMinUserType() itself is still checked wherever it is reached.
        $inArea = $this->answeringApi || \Pramnos\Http\AdminArea::prefix() === '' || \Pramnos\Http\AdminArea::isActive();
        if ($this->adminAbility !== ''
            && $inArea
            && !in_array(strtolower((string) $action), array_map('strtolower', $this->adminPublicActions), true)
            && $this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        if (in_array(strtolower((string) $action), $this->writeActions, true) && !$this->isVerifiedWrite()) {
            $this->refuseUnverifiedWrite();

            return null;
        }
        $lowerAction = strtolower((string) $action);
        if ($this->releaseSessionOnRead
            && !in_array($lowerAction, $this->writeActions, true)
            && !in_array($lowerAction, $this->sessionActions, true)) {
            $this->_releaseSessionLock((string) $action);
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
        if ($this->answeringApi) {
            throw new \Pramnos\Application\Api\ScreenAnswer(\Pramnos\Application\Api\ScreenAnswer::REFUSED, 'You do not have the rights to do that.', 403);
        }
        if (!$session->isLogged() && defined('sURL')) {
            throw new \Pramnos\Http\RedirectException(self::signInAndBack());
        }
        throw new \Exception('Not authenticated users cannot do that.', 403);
    }

    /**
     * Where a guest goes to sign in, and come back to the page they asked for.
     *
     * Inside an administration area: the area's sign-in, and back to the whole path —
     * `Request::$requestUri` has the area's prefix taken off, so it would return the guest to the
     * site's page of the same name.
     */
    private static function signInAndBack(): string
    {
        if (\Pramnos\Http\AdminArea::isActive()) {
            return \Pramnos\Http\AdminArea::loginRedirect((string) ($_SERVER['REQUEST_URI'] ?? ''));
        }
        return \Pramnos\Auth\SecurityPolicy::loginUrl((string) (\Pramnos\Http\Request::$requestUri ?? ''));
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
            // Answering the API, the person is the one the API's credentials sealed.
            $apiPerson = $this->answeringApi && (int) (\Pramnos\Http\RequestIdentity::user()->userid ?? 0) > 1;
            if ($session->isLogged() != true && !$apiPerson) {
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
     * A single-page section in place of a view: the theme draws the page around it.
     *
     * ```php
     * public function display() { return $this->spaSection('users'); }
     * ```
     *
     * Its router works under this controller's address — `/admin/Users` inside the area — and
     * every address below it reaches `display()`, so a deep link opens the section on the screen
     * it names. See {@see SpaSection}.
     *
     * @param string               $name  As declared under `spa_sections` in app/app.php
     * @param array<string, mixed> $props Initial data the section reads
     */
    protected function spaSection(string $name, array $props = []): string
    {
        $route = (string) \Pramnos\Http\Request::getInstance()->getController();
        $base  = \Pramnos\Http\AdminArea::isActive()
            ? \Pramnos\Http\AdminArea::url($route)
            : (defined('sURL') ? \sURL : '/') . $route;

        return SpaSection::render($name, (string) parse_url($base, PHP_URL_PATH), $props, $this->application instanceof Application ? $this->application : null);
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
        // Only the class's own name is capitalised: the namespace before it — an application
        // named `shop`, say — is kept as it is, or the retry looks in a directory that is not there.
        $upper = substr($class, 0, strlen($class) - strlen($name)) . ucfirst($name);
        if (class_exists($upper)) {
            $model = new $upper($this, ucfirst($name));
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
            // Documented as `array|string`, and the string a caller has is usually a
            // column it read somewhere. This method cannot tell, and the difference
            // between the two readings is a working permission check and a silent
            // refusal.
            return \Pramnos\User\Token::parseScopes($permissions);
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

        /**
         * A view class in the area's own namespace, when this request is in an area.
         *
         * `<Ns>\Admin\Views\Users` before `<Ns>\Views\Users`, so an area can hold
         * a view class of its own without renaming the site's. Most views have no
         * class at all — the directory below is what usually answers — so this is the
         * uncommon path, and it falls through.
         */
        if ($this->application !== null
            && ($this->application->area ?? '') !== ''
            && isset($this->application->applicationInfo['namespace'])
        ) {
            $areaClass = '\\'
                . $this->application->applicationInfo['namespace']
                . '\\' . $this->application->area
                . '\\Views\\'
                . $name;
            if (class_exists($areaClass)) {
                return new $areaClass($this);
            }
        }

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

            /**
             * `src/Admin/Views/<name>` before `src/Views/<name>`.
             *
             * First, not instead: an area holds the screens that belong to it, and
             * everything it shares with the site — a partial, a form, an error page —
             * is still found where it already lives. An area that overrides one view
             * therefore does not have to copy the other thirty.
             */
            if (($this->application->area ?? '') !== '') {
                $view = $this->_getView(
                    $base . DS . $this->application->area,
                    $name,
                    $type,
                    $args
                );
                if ($view) {
                    return $view;
                }
            }

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

    /**
     * The usertype floor {@see exec()} checks an administration screen against.
     *
     * A screen with no `$requiredUserType` of its own gets the framework's default rather
     * than 0, which under `admin_access = usertype` would open it to every account.
     */
    protected function adminFloor(): int
    {
        // 0 is "the administration floor", never "anybody": a screen that leaves it unset, or
        // an application that set it to 0, is not opened to every account.
        return property_exists($this, 'requiredUserType') && (int) $this->requiredUserType > 0
            ? (int) $this->requiredUserType
            : \Pramnos\Auth\AdminAccess::defaultUsertype();
    }

    /** Answering the API, a refusal is the API's: 401 for nobody, 403 for somebody below the floor. */
    private function refuseTheApi(?object $user): void
    {
        if ($this->answeringApi) {
            throw new \Pramnos\Application\Api\ScreenAnswer(\Pramnos\Application\Api\ScreenAnswer::REFUSED,
                $user === null ? 'Sign in first.' : 'You do not have access to that screen.', $user === null ? 401 : 403);
        }
    }

    /**
     * Refuse this request unless the signed-in user is at least `$minType`.
     *
     * The guard every administration screen opens with, and it lived as an **identical
     * eleven-line copy in eleven controllers** — so a new admin screen either copied it
     * again or, as happened, assumed the base class had it and failed at runtime with
     * `Call to undefined method`. One definition, and a screen that forgets to call it is
     * the only remaining way to be unguarded.
     *
     * Returns `true` when the request was refused, so the caller's first line reads
     * `if ($this->requireMinUserType(...)) { return; }` — the shape the copies used.
     *
     * Redirects to the site root rather than to a login form: somebody signed in who is
     * merely not senior enough is not helped by being asked to sign in again, and being
     * asked would read as a broken session.
     *
     * A screen that declares {@see $adminAbility} is decided by
     * {@see \Pramnos\Auth\AdminAccess}: by this same floor under `admin_access = usertype`,
     * by the grant under `permissions`. Refused there, somebody signed in is sent to the first
     * administration screen they may open, with a message — not to the site root, which from
     * inside the area reads as having been thrown out.
     *
     * @param  int  $minType The usertype floor for this screen
     * @return bool Whether the request was refused
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();
        $user = $user === false ? null : $user;

        if ($this->adminAbility !== '') {
            if (\Pramnos\Auth\AdminAccess::allows($user, $this->adminAbility, $minType)) {
                return false;
            }

            $landing = null;
            if ($user !== null && \Pramnos\Auth\AdminAccess::usesPermissions()) {
                $landing = \Pramnos\Auth\AdminAccess::landingFor(
                    $user,
                    (array) ($this->application->applicationInfo['features'] ?? []),
                    $this->adminAbility
                );
                if ($landing !== null) {
                    $this->addError('You do not have access to that screen.');
                }
            }
            $this->refuseTheApi($user);
            $this->redirect($landing ?? ($user === null && defined('sURL') ? self::signInAndBack() : (defined('sURL') ? \sURL : '/')));

            return true;
        }

        if ($user === null || (int) $user->usertype < $minType) {
            // A guest — somebody who opened a report's link from an email, say — signs in and
            // comes back; somebody signed in without the standing goes to the site.
            $this->refuseTheApi($user);
            $this->redirect($user === null && defined('sURL') ? self::signInAndBack() : (defined('sURL') ? \sURL : '/'));

            return true;
        }

        return false;
    }

    /**
     * Answer a state change that arrived without a verified token — nothing is changed.
     *
     * A script (it asked for JSON, or says it is an XHR) gets `403` and a JSON reason; a page
     * gets an error message and is sent back where it came from on this site, or to the site
     * root.
     */
    protected function refuseUnverifiedWrite(): void
    {
        $message = 'That request could not be verified, so nothing was changed. Try again from the page.';

        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            \Pramnos\Framework\Factory::getDocument('raw')->setContent((string) json_encode(['error' => $message]));
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
            }

            return;
        }

        $this->addError($message);

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $site    = defined('sURL') ? (string) \sURL : '/';
        $this->redirect($referer !== '' && str_starts_with($referer, $site) ? $referer : $site);
    }

    /**
     * Is this a `POST` carrying a token this session issued?
     *
     * Either of the framework's two: the form token `Session::getTokenField()` renders, or the
     * synchronizer token `CsrfMiddleware::tokenField()` renders — as a field, or as the
     * `X-CSRF-Token` header a page's own `fetch()` sends from `<meta name="csrf">`. Views of both
     * kinds exist, and a guard that accepted only one would refuse the other's forms.
     */
    protected function isVerifiedWrite(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return false;
        }
        // The API's own check came first: a bearer token, or the session with its CSRF header.
        if ($this->answeringApi && \Pramnos\Http\RequestIdentity::user() !== null) {
            return true;
        }

        $session = \Pramnos\Http\Session::getInstance();

        foreach ([$_POST['_csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $session->verifyCsrfToken($candidate)) {
                return true;
            }
        }

        return $session->checkToken('post');
    }
}
