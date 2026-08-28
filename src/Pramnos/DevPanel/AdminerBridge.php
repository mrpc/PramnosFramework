<?php

declare(strict_types=1);

namespace Pramnos\DevPanel;

/**
 * The authentication wrapper behind `/adminer`.
 *
 * Adminer asks for a server, a username, a password and a database. The application already
 * knows all four — it is connected to that database right now — so asking the operator to
 * retype them is asking them to keep the production database password somewhere they can copy
 * it from. Which is a worse outcome than this: a password manager entry is fine, a sticky note
 * is not, and the sticky note is what actually happens.
 *
 * ## How Adminer is told
 *
 * Not by filling in its form. Adminer's login state is a session slot and a set of query
 * parameters, and the officially supported way in is `adminer_object()` — a **global** function
 * it calls once its own classes are loaded, whose return value replaces its `Adminer` object.
 * {@see plugin()} is what that function returns.
 *
 * Three things happen in there, and the order matters:
 *
 * 1. **The password goes into Adminer's session, not ours.** `get_password()` reads
 *    `$_SESSION["pwds"][DRIVER][SERVER][username]`, and Adminer starts its own session
 *    (`adminer_sid`) during bootstrap — *after* our request's session has been closed and
 *    *before* this hook runs. Seeding `$_SESSION` any earlier writes into a session Adminer is
 *    about to replace, which is a password stored in the wrong place and a login screen anyway.
 * 2. **`credentials()` supplies the connection**, from what the application is using.
 * 3. **`login()` returns true.** The default refuses an empty password and probes the server
 *    with one to decide whether it requires passwords — an extra connection to answer a
 *    question the application has already answered.
 *
 * ## What is deliberately not done
 *
 * The route's own gate is not relaxed. Auto-login means reaching that URL *is* database access,
 * so the URL is the whole lock: root usertype on any deployment, or a development environment
 * subject to the DevPanel's floor, and a 404 for everybody else. An installation that would
 * rather type the credentials turns it off:
 *
 * ```php
 * 'devpanel' => ['adminer_autologin' => false],
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AdminerBridge
{
    /**
     * The connection Adminer should open, as `driver`, `server`, `user`, `password`, `database`
     * and `name`.
     *
     * Static because the hook Adminer calls is a *global function* with no arguments, so
     * something has to hold the answer between the controller preparing it and Adminer asking.
     *
     * @var array<string, string>
     */
    protected static array $connection = [];

    /**
     * The framework's database type, as Adminer names its drivers.
     *
     * `server` for MySQL is not a typo — it is Adminer's own key for its first driver, kept for
     * backwards compatibility, and it is what `drivers/mysql.inc.php` defines `DRIVER` as.
     */
    public static function driverFor(string $type): string
    {
        return match (strtolower(trim($type))) {
            'postgresql', 'postgres', 'pgsql', 'timescaledb' => 'pgsql',
            'sqlite', 'sqlite3' => 'sqlite',
            'mssql', 'sqlsrv'   => 'mssql',
            'oracle', 'oci8'    => 'oracle',
            default             => 'server',
        };
    }

    /**
     * What the application is connected to, or an empty array when it cannot be read.
     *
     * From the settings rather than from the live connection object: a `Database` holds a
     * handle, not the password it opened it with.
     *
     * @return array<string, string>
     */
    public static function applicationConnection(): array
    {
        /*
         * Two shapes, and `database` means different things in each.
         *
         * A modern config nests the connection — `'database' => ['hostname' => …, 'database' =>
         * …]` — and an older one has those keys at the top level. `Settings::getSetting()` knows
         * about the nested form for `hostname`, `user`, `password` and the rest, but **not for
         * `database` itself**: the key is set at the top level (it holds the whole array), so
         * the early return hands back the array as an object and a string cast throws
         * *Object of class stdClass could not be converted to string*. Which it did, in
         * production, on the first request to this route.
         *
         * The nested block is read directly when it is there, and every value is checked for
         * being a scalar before it is used — a settings file is written by hand, and "an array
         * where a string was expected" is the failure this whole method is now shaped around.
         */
        $block = static::databaseBlock();

        $get = static function (string $key) use ($block): string {
            $value = $block[$key] ?? null;

            if ($value === null) {
                $value = \Pramnos\Application\Settings::getSetting($key);
            }

            return is_scalar($value) ? trim((string) $value) : '';
        };

        $user     = $get('user');
        $database = $get('database');

        if ($user === '' || $database === '') {
            // Nothing useful to hand over. The login form is the right answer then, rather
            // than a half-filled one that fails with "Invalid credentials".
            return [];
        }

        $type = $get('type') ?: 'mysql';

        /*
         * The schema, for the drivers that have one.
         *
         * Without it Adminer redirects once to add `ns=`, which is its own correct behaviour and
         * puts the connection into the address bar on the way. The framework already knows which
         * schema it is using, so there is nothing to discover.
         */
        $schema = '';

        try {
            $live = \Pramnos\Framework\Factory::getDatabase();
            $schema = is_scalar($live->schema ?? '') ? trim((string) $live->schema) : '';
        } catch (\Throwable) {
            $schema = '';
        }

        if ($schema === '' && static::driverFor($type) === 'pgsql') {
            // PostgreSQL's own default, and the one every migration in this framework writes to.
            $schema = 'public';
        }

        return [
            'driver'   => static::driverFor($type),
            'schema'   => $schema,
            // Empty means "the driver's default", which is what Adminer's own placeholder
            // says. `localhost` would be wrong inside a container.
            'server'   => $get('hostname'),
            'user'     => $user,
            'password' => $get('password'),
            'database' => $database,
            'name'     => (static function (): string {
                $name = \Pramnos\Application\Settings::getSetting('sitename');

                return is_scalar($name) ? trim((string) $name) : '';
            })(),
        ];
    }

    /**
     * Every connection this installation declares, keyed by a short name.
     *
     * The primary, plus the `read` and `write` replicas when the settings name them —
     * `'database' => ['read' => [...], 'write' => [...]]`, the same blocks
     * `Database::connectToReplica()` uses. Deduplicated, because a `write` block that repeats
     * the primary is the common case and two identical entries in a picker say nothing.
     *
     * This list is also the **allow-list**: {@see chosen()} will not connect to anything that is
     * not in it. Adminer's own login form is removed, and a hand-made request naming another
     * server gets the default instead of the server it asked for.
     *
     * @return array<string, array<string, string>>
     */
    public static function connections(): array
    {
        $primary = static::applicationConnection();

        if ($primary === []) {
            return [];
        }

        $connections = ['primary' => $primary];
        $block = static::databaseBlock();

        foreach (['read', 'write'] as $role) {
            $replica = $block[$role] ?? null;

            if (is_object($replica)) {
                $replica = (array) $replica;
            }

            if (!is_array($replica) || $replica === []) {
                continue;
            }

            $scalar = static fn ($value): string => is_scalar($value) ? trim((string) $value) : '';

            $connection = [
                'driver'   => $primary['driver'],
                'server'   => $scalar($replica['hostname'] ?? $primary['server']),
                'user'     => $scalar($replica['user'] ?? $primary['user']),
                'password' => $scalar($replica['password'] ?? $primary['password']),
                'database' => $scalar($replica['database'] ?? $primary['database']),
                'schema'   => $primary['schema'] ?? '',
                'name'     => $primary['name'] ?? '',
            ];

            if ($connection['user'] === '' || $connection['database'] === '') {
                continue;
            }

            // Same host, user and database as one we already have: the same connection under
            // another name.
            $duplicate = false;

            foreach ($connections as $existing) {
                if ($existing['server'] === $connection['server']
                    && $existing['user'] === $connection['user']
                    && $existing['database'] === $connection['database']
                ) {
                    $duplicate = true;
                    break;
                }
            }

            if (!$duplicate) {
                $connections[$role] = $connection;
            }
        }

        return $connections;
    }

    /**
     * The connection the request is asking for — from the allow-list, or the default.
     *
     * A request that names a server, a user or a database this installation did not declare is
     * answered with the default rather than obeyed. Adminer normally treats those parameters as
     * "who to connect as", which is exactly what this route is not for: the gate on the URL is
     * *the* authorisation, so a URL that could point Adminer at another host would be a way to
     * use somebody else's credentials through our door.
     *
     * @return array<string, string>
     */
    public static function chosen(): array
    {
        $connections = static::connections();

        if ($connections === []) {
            return [];
        }

        $wanted = [
            'user'     => is_scalar($_GET['username'] ?? null) ? (string) $_GET['username'] : '',
            'database' => is_scalar($_GET['db'] ?? null) ? (string) $_GET['db'] : '',
        ];

        foreach ($connections as $connection) {
            $server = is_scalar($_GET[$connection['driver']] ?? null)
                ? (string) $_GET[$connection['driver']]
                : null;

            if ($wanted['user'] !== '' && $wanted['user'] !== $connection['user']) {
                continue;
            }

            if ($wanted['database'] !== '' && $wanted['database'] !== $connection['database']) {
                continue;
            }

            if ($server !== null && $server !== $connection['server']) {
                continue;
            }

            return $connection;
        }

        // Named something we do not have. The default, not a refusal: a stale bookmark should
        // land somewhere useful rather than on an error.
        return reset($connections);
    }

    /**
     * The `database` settings block, in whichever shape the file uses.
     *
     * @return array<string, mixed>
     */
    protected static function databaseBlock(): array
    {
        $nested = \Pramnos\Application\Settings::getSetting('database');

        if (is_object($nested)) {
            return (array) $nested;
        }

        return is_array($nested) ? $nested : [];
    }

    /**
     * Remember the connection, for the `adminer_object()` hook to use.
     *
     * Only remembered. Putting the parameters into `$_GET` is what this used to do, and it
     * caused an infinite redirect: Adminer builds its own self-links from
     * `$_SERVER['REQUEST_URI']`, so with the connection in `$_GET` and absent from the URI its
     * idea of "the canonical address of this page" was the bare route — which it redirected to,
     * arriving back where they were injected again. They belong in the URL, once, by redirect;
     * see {@see query()} and the controller.
     *
     * @param array<string, string> $connection From {@see applicationConnection()}
     */
    public static function remember(array $connection): void
    {
        static::$connection = $connection;
    }

    /**
     * Does the request's own URL already name this connection?
     *
     * The driver key is the one that matters — Adminer identifies the driver by *which* key is
     * present (`?pgsql=host`) — but a request that names some other driver counts too: somebody
     * following "log in as somebody else" inside Adminer must not be dragged back to the
     * application's own database.
     *
     * @param array<string, string> $connection
     */
    public static function urlNamesConnection(array $connection): bool
    {
        return isset($_GET[$connection['driver']]) || static::driverChosen();
    }

    /**
     * The connection as a query string.
     *
     * The password is **not** in it. It travels in Adminer's session, seeded by
     * {@see plugin()} — a password in a URL is a password in a browser history, a proxy log and
     * an access log.
     *
     * @param  array<string, string> $connection
     * @return string
     */
    public static function query(array $connection): string
    {
        $parameters = [
            $connection['driver'] => $connection['server'],
            'username'            => $connection['user'],
            'db'                  => $connection['database'],
        ];

        if (($connection['schema'] ?? '') !== '') {
            $parameters['ns'] = $connection['schema'];
        }

        return http_build_query($parameters);
    }

    /**
     * Put the connection in `$_GET` and in the request URI Adminer reads, together.
     *
     * Together, because they are two halves of one answer and Adminer compares them. It reads
     * parameters from `$_GET` and builds its own self-links from `$_SERVER['REQUEST_URI']`; if
     * the two disagree it concludes the visitor is at the wrong address and redirects to the one
     * it derived — which, with the parameters only in `$_GET`, is the bare route. Straight back
     * here, injected again: `ERR_TOO_MANY_REDIRECTS` on the first click.
     *
     * The visitor's address bar is left alone. A real redirect to the parameterised URL would
     * also have worked and would have written the driver, the host, the username and the
     * database name into the address bar, the browser history and every log between here and
     * there.
     *
     * @param array<string, string> $connection
     * @param string $path This route's own path, e.g. `/adminer`
     */
    public static function alignRequestUri(array $connection, string $path): void
    {
        $query = static::query($connection);

        parse_str($query, $parameters);

        foreach ($parameters as $key => $value) {
            if (!isset($_GET[$key])) {
                $_GET[$key] = $value;
            }
        }

        $_SERVER['REQUEST_URI'] = $path . '?' . $query;
        $_SERVER['QUERY_STRING'] = $query;
    }

    /**
     * Has the request already named a driver?
     *
     * Adminer identifies the driver by the presence of its key in the query string, so adding
     * ours to a request that already names one would define two and connect to whichever
     * driver file happens to be included first.
     */
    protected static function driverChosen(): bool
    {
        foreach (['server', 'pgsql', 'sqlite', 'mssql', 'oracle', 'mongo', 'elastic'] as $driver) {
            if (isset($_GET[$driver])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name Adminer gives its own session.
     *
     * Hard-coded there — `session_name("adminer_sid")` in its bootstrap — so it is hard-coded
     * here too rather than guessed.
     */
    public const SESSION_NAME = 'adminer_sid';

    /**
     * Take this framework's CSRF token out of Adminer's session, if it got in.
     *
     * A repair, and it exists because the first version of this route left our session open.
     * Adminer starts a session of its own only when none is active, so it used ours — and one of
     * the keys it uses is `token`. Ours is a hex string; Adminer's is
     * `rand() ^ $_SESSION["token"]`, which gives «A non-numeric value encountered» twice per
     * page and a CSRF check that cannot work.
     *
     * Closing our session fixed it for a new visitor. It did not fix it for anybody who had
     * already loaded the broken page: the bad value had been written into their `adminer_sid`
     * session, where it sat waiting for every later request. So the value is repaired rather
     * than merely prevented — the alternative is telling people to clear their cookies, which
     * is what software says when it cannot fix itself.
     *
     * Only that one key, and only when it is not a number. Anything else in there is Adminer's.
     */
    public static function repairSession(): void
    {
        $cookie = $_COOKIE[self::SESSION_NAME] ?? null;

        if (!is_string($cookie) || $cookie === '' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // A session id is chosen by PHP and must look like one; anything else is a visitor
        // editing their own cookie, and `session_id()` would reject it noisily.
        if (preg_match('/^[a-zA-Z0-9,\-]{16,128}$/', $cookie) !== 1) {
            return;
        }

        $previousName = session_name();

        /*
         * `use_cookies => false`, and **restored afterwards**.
         *
         * The option keeps this repair from emitting a `Set-Cookie` of its own — the browser
         * already has the cookie, and a second one with different parameters is a mess. But
         * `session_start($options)` applies those options as ini settings **for the rest of the
         * request**, so Adminer's own `session_set_cookie_params()` then warned «Session cookies
         * cannot be used when session.use_cookies is disabled» at the top of every page. A repair
         * that leaves a warning behind has not repaired anything.
         */
        $savedUseCookies     = ini_get('session.use_cookies');
        $savedOnlyCookies    = ini_get('session.use_only_cookies');

        try {
            session_name(self::SESSION_NAME);
            session_id($cookie);

            if (@session_start(['use_cookies' => false, 'use_only_cookies' => false]) !== true) {
                return;
            }

            if (isset($_SESSION['token']) && !is_numeric($_SESSION['token'])) {
                unset($_SESSION['token']);
            }

            session_write_close();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not repair the Adminer session: ' . $exception->getMessage(),
                'devpanel'
            );
        } finally {
            // Adminer sets the name itself, but leaving the process pointed at a session name
            // that is not ours is the kind of thing that surprises whatever runs next.
            session_name($previousName);

            if (is_string($savedUseCookies)) {
                ini_set('session.use_cookies', $savedUseCookies);
            }

            if (is_string($savedOnlyCookies)) {
                ini_set('session.use_only_cookies', $savedOnlyCookies);
            }
        }
    }

    /**
     * The object Adminer asks for — with the password seeded into its own session.
     *
     * Called from the global `adminer_object()` in `adminer-object.php`, which is the hook
     * Adminer looks for. By this point Adminer's session is started and its classes are
     * loaded, which is why the seeding happens here and not earlier.
     */
    public static function plugin(): object
    {
        $connection = static::$connection;

        if ($connection !== []) {
            // Exactly the slot `get_password()` reads. A plain string rather than the
            // encrypted array form, which is only for the permanent-login cookie.
            $_SESSION['pwds'][$connection['driver']][$connection['server']][$connection['user']]
                = $connection['password'];
        }

        return static::instance($connection);
    }

    /**
     * The `Adminer` subclass, declared here because its parent does not exist any earlier.
     *
     * `Adminer\Adminer` is defined by an include partway through Adminer's own bootstrap, so a
     * subclass written at the top of a file could not be loaded at all. Declared inside the
     * method it is returned from, it is defined at the moment the parent exists.
     *
     * @param array<string, string> $connection
     */
    protected static function instance(array $connection): object
    {
        if (!class_exists(__NAMESPACE__ . '\\PramnosAdminer', false)) {
            eval(static::subclassSource());
        }

        $class = __NAMESPACE__ . '\\PramnosAdminer';

        return new $class($connection);
    }

    /**
     * The subclass, as source.
     *
     * `eval`, and it is worth saying why rather than leaving it looking like a shortcut. The
     * parent class lives in a namespace and is loaded by an `include` inside Adminer's
     * bootstrap; a file of ours declaring `extends \Adminer\Adminer` cannot be included before
     * that point (the parent is missing) and this method is called *during* it. A `class`
     * statement inside a method body would be evaluated when this file is compiled, which is
     * the same problem one step earlier.
     *
     * There is no input here: the string is a constant, the values arrive through the
     * constructor.
     */
    protected static function subclassSource(): string
    {
        return <<<'PHP'
namespace Pramnos\DevPanel;

/**
 * Adminer, told what the application is already connected to.
 */
class PramnosAdminer extends \Adminer\Adminer
{
    /** @param array<string, string> $connection */
    public function __construct(private array $connection = [])
    {
    }

    /**
     * The connection, from the configuration — never from the request.
     *
     * The default reads the server and username out of the query string, which is Adminer's
     * design and the wrong one behind a single-purpose gate: it would make a URL enough to point
     * this at another host. {@see \Pramnos\DevPanel\AdminerBridge::chosen()} picks from the
     * connections the installation declared and answers with the default when the request names
     * anything else.
     *
     * @return array{string, string, string}
     */
    public function credentials(): array
    {
        if ($this->connection === []) {
            return parent::credentials();
        }

        return [
            $this->connection['server'],
            $this->connection['user'],
            $this->connection['password'],
        ];
    }

    /**
     * The application vouched for this request; the route's gate is the lock.
     *
     * The default refuses an empty password and, to decide whether the server requires one,
     * connects with an empty password to find out — an extra connection to answer a question
     * the application answered by being connected.
     */
    public function login(string $login, string $password)
    {
        return true;
    }

    /**
     * No login form.
     *
     * Adminer's form takes a driver, a server, a username, a password and a database — that is,
     * it lets whoever reached this URL connect to **anything they can reach from this machine**
     * with any credentials they happen to know. The gate on the route is the authorisation here,
     * so leaving that form in place would turn one permission into a general-purpose database
     * client pointed at the rest of the network.
     *
     * What replaces it is a sentence saying where the connection comes from. The connections
     * this installation declares — the primary and any replicas — are offered in the bar above,
     * which is a list, not a text field.
     */
    public function loginForm(): void
    {
        echo "<p class='message'>"
            . 'This Adminer is connected with the credentials the application itself uses. '
            . 'There is deliberately no form: the connection comes from the configuration, '
            . 'and the declared connections are in the bar above.'
            . "</p>\n";
    }

    /**
     * Whose database this is, in the header.
     *
     * Adminer's own name and logo are a link to adminer.org, which is the least useful thing
     * to read on a page that can drop a table. What matters is which installation you are
     * looking at — a production database and a local copy are otherwise identical on screen.
     */
    public function name(): string
    {
        $site = $this->connection['name'] ?? '';

        return "<span id='h1'>"
            . ($site !== '' ? htmlspecialchars($site, ENT_QUOTES) . ' &middot; ' : '')
            . "Adminer</span>";
    }
}
PHP;
    }
}
