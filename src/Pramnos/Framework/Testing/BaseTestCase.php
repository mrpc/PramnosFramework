<?php
namespace Pramnos\Framework\Testing;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use PDO;

/**
 * Base test case for all Pramnos Framework applications.
 * 
 * Provides common setup and helper methods for all test classes,
 * including database interaction, application initialization, and session management.
 */
abstract class BaseTestCase extends TestCase
{
    /**
     * How long to wait for a database that accepts a connection and then hangs.
     *
     * A firewalled port or a wedged server, where the socket opens and nothing comes
     * back: without this, a test waits for the driver's own default, which is minutes.
     *
     * It does **not** help with a hostname that cannot be resolved. That block happens
     * inside `getaddrinfo()` before any socket exists — measured at 8.00 seconds flat
     * in this project's container, which is the resolver giving up, not TCP. The tests
     * that assert which DSN was built therefore point at an **IP literal** rather than
     * a made-up name, which skips resolution entirely and fails immediately. Worth
     * writing down because the timeout looks like it should have fixed those, and it
     * did not.
     */
    private const CONNECT_TIMEOUT = 1;

    /**
     * PDO database connection instance.
     * @var PDO|null
     */
    protected $pdo;
    
    /**
     * Main application instance.
     * @var \Pramnos\Application\Application
     */
    protected $application;
    
    /**
     * Database configuration for {@see getConnection()}, or null to read the settings.
     *
     * Declared here and **never populated**, for as long as it existed: `getConnection()`
     * read it for host, database and credentials and got null for each, so the documented
     * `assertDatabaseHas()` failed with
     * `buildDsn(): Argument #2 ($host) must be of type string, null given`. It now falls
     * back to the settings the suite has already loaded, and stays a property so a test
     * that wants another connection can still set one.
     *
     * @var array|object|null
     */
    protected static $dbConfig;

    /**
     * Users this test created or handed over, erased in {@see tearDown()}.
     *
     * @var array<int, \Pramnos\Database\Database> user id => the connection it lives on
     */
    private array $testUsers = [];

    /**
     * Tables watched for rows a test class leaves behind: table => its auto-increment key.
     *
     * An application adds its own in its base test case, keeping these:
     *
     * ```php
     * protected static array $watchedTables = [
     *     '#PREFIX#users' => 'userid',
     *     'organizations' => 'organization_id',
     * ];
     * ```
     *
     * Only tables keyed by an increasing number can be watched: what is above the highest
     * key when a class starts is what that class added.
     *
     * @var array<string, string>
     */
    protected static array $watchedTables = ['#PREFIX#users' => 'userid'];

    /** @var array<class-string, array{0: \Pramnos\Database\Database, 1: array<string, int>}> Highest key per table when each class started */
    private static array $watermarks = [];

    /** @var array<class-string, array<string, int>> Rows each class left behind, removed at its end */
    private static array $leftBehind = [];

    /**
     * Initialize test environment before each test.
     * 
     * Resets framework singletons, initializes the application, 
     * and sets up session state to prevent cross-test contamination.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up request-scoped state so nothing leaks between tests.
        $this->resetRequestState();

        // The site is the current application at the start of every test. Without this, an
        // application built by the test before — the API — answered for this one's
        // getInstance() and bare TestClient, and only in a full run.
        \Pramnos\Application\Application::resetCurrentInstance();

        // Initialize application if possible
        try {
            if (class_exists('\Pramnos\Application\Application')) {
                $this->application = \Pramnos\Application\Application::getInstance();
                // Avoid full init in core unit tests if settings missing or no instance
                if ($this->application !== null
                    && defined('APP_PATH')
                    && file_exists(APP_PATH . '/app.php')
                ) {
                    $this->application->init();
                }
            }
        } catch (\Exception $e) {
            // Silently fail for core unit tests
        }

        // Initialize session
        $this->initializeSession();

        $this->watchForRowsLeftBehind();
    }

    /**
     * Note the highest key in each watched table, once per test class.
     *
     * Called by {@see setUp()}. A test class with a `setUp()` of its own that does not call
     * the parent's calls this once its connection is open.
     *
     * Only on a connection that is already open: a class that never touches the database
     * must not be made to open one for this.
     */
    protected function watchForRowsLeftBehind(): void
    {
        if (isset(self::$watermarks[static::class]) || static::$watchedTables === []) {
            return;
        }

        try {
            $database = \Pramnos\Framework\Factory::getDatabase();
            if (!$database->connected) {
                return;
            }

            $marks = [];
            foreach (static::$watchedTables as $table => $key) {
                if ($database->schema()->hasTable($table)) {
                    $marks[$table] = (int) $database->queryBuilder()->table($table)->max($key);
                }
            }
            self::$watermarks[static::class] = [$database, $marks];
        } catch (\Throwable) {
            // No database for this class: nothing to watch.
        }
    }

    /**
     * Remove what this test class left behind in the watched tables, and say so.
     *
     * Rows above the class's watermark that are still there when it ends were made by its
     * tests, or by the code they ran, and removed by nothing. A user goes through
     * {@see \Pramnos\Auth\AccountErasure}, with everything that belongs to it; another row is
     * deleted. Each class's count goes into the report printed when the suite ends, so the
     * test that leaks is named rather than discovered months later as a slow suite.
     */
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$watermarks[static::class])) {
            [$database, $marks] = self::$watermarks[static::class];
            unset(self::$watermarks[static::class]);

            foreach ($marks as $table => $mark) {
                try {
                    $removed = self::removeAbove($database, $table, static::$watchedTables[$table], $mark);
                } catch (\Throwable) {
                    continue; // The connection or the table went with the class.
                }
                if ($removed > 0) {
                    self::$leftBehind[static::class][$table] = $removed;
                }
            }

            if (self::$leftBehind !== []) {
                self::reportAtShutdown();
            }
        }

        parent::tearDownAfterClass();
    }

    /**
     * Remove the rows above a key, and count them.
     */
    private static function removeAbove(\Pramnos\Database\Database $database, string $table, string $key, int $mark): int
    {
        $ids = $database->queryBuilder()->table($table)->where($key, '>', $mark)->pluck($key);
        if ($ids === []) {
            return 0;
        }

        if ($table === '#PREFIX#users') {
            foreach ($ids as $userId) {
                try {
                    (new \Pramnos\Auth\AccountErasure($database))->erase((int) $userId);
                } catch (\Throwable) {
                    // A listener refused, or a row of theirs is still referenced: the user row goes anyway.
                    $database->queryBuilder()->table($table)->where($key, $userId)->delete();
                }
            }

            return count($ids);
        }

        $database->queryBuilder()->table($table)->where($key, '>', $mark)->delete();

        return count($ids);
    }

    /**
     * Print, once, when the suite ends, which classes left rows behind.
     */
    private static function reportAtShutdown(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(static function (): void {
            if (self::$leftBehind === []) {
                return;
            }
            $lines = [];
            foreach (self::$leftBehind as $class => $tables) {
                $counts = [];
                foreach ($tables as $table => $rows) {
                    $counts[] = str_replace('#PREFIX#', '', $table) . ' ' . $rows;
                }
                $lines[] = '  ' . $class . ': ' . implode(', ', $counts);
            }
            fwrite(STDERR, "\nRows left behind by test classes, removed when each class ended:\n"
                . implode("\n", $lines)
                . "\nCreate users with createTestUser() or hand them over with trackTestUser().\n");
        });
    }

    /**
     * What the test classes so far left behind: class => table => rows.
     *
     * @return array<class-string, array<string, int>>
     */
    public static function rowsLeftBehind(): array
    {
        return self::$leftBehind;
    }

    /**
     * Erase the users this test created, with everything that belongs to them.
     *
     * Through {@see \Pramnos\Auth\AccountErasure}, the same erase an account deletion
     * performs: the framework's tables, and the application's own through its
     * `account.data_erase` listeners. So a test leaves nothing of its users behind, and the
     * application describes what belongs to a person once, for both.
     *
     * A failed erase is raised, as a test error: a user that cannot be removed is a row
     * that every later run will carry, which is how one project's suite came to hold 5,079
     * administrators and take 22 minutes.
     */
    protected function tearDown(): void
    {
        $users           = $this->testUsers;
        $this->testUsers = [];

        foreach ($users as $userId => $database) {
            (new \Pramnos\Auth\AccountErasure($database))->erase($userId);
        }

        parent::tearDown();
    }

    /**
     * Create a user for this test, erased when the test ends.
     *
     * ```php
     * $userId = $this->createTestUser();                              // a member
     * $adminId = $this->createTestUser(['usertype' => 99]);           // an administrator
     * $userId = $this->createTestUser(['password' => 'correct horse']); // can sign in
     * ```
     *
     * Any column of `users` can be set. A unique username and email are generated when not
     * given. `password` is the plain password: it is hashed the way the framework hashes it,
     * at the cheap cost the test bootstrap sets.
     *
     * @param  array<string, mixed> $attributes Columns of `users`, and `password` in plain text
     * @return int The new user's id
     */
    protected function createTestUser(array $attributes = []): int
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $name     = 'test_' . bin2hex(random_bytes(6));
        $password = $attributes['password'] ?? null;
        unset($attributes['password']);

        $database->queryBuilder()->table('#PREFIX#users')->insert($attributes + [
            'username'  => $name,
            'email'     => $name . '@example.test',
            'password'  => '',
            'active'    => 1,
            'validated' => 1,
            'regdate'   => time(),
            'modified'  => time(),
        ]);

        $row = $database->queryBuilder()->table('#PREFIX#users')
            ->select('userid')->where('username', $attributes['username'] ?? $name)->first();
        $userId = (int) $row->fields['userid'];
        $this->trackTestUser($userId, $database);

        if ($password !== null) {
            // After the insert: the hash is peppered with the user's id.
            $database->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)
                ->update(['password' => \Pramnos\Auth\PasswordHash::make((string) $password, $userId)]);
        }

        return $userId;
    }

    /**
     * Have a user this test did not create with {@see createTestUser()} erased when it ends.
     *
     * For a user the code under test created — a sign-up, an invitation accepted.
     *
     * @param  int                              $userId
     * @param  \Pramnos\Database\Database|null $database The connection it lives on; the current one by default
     * @return int The same id, so a call can be wrapped around the code that returns it
     */
    protected function trackTestUser(int $userId, ?\Pramnos\Database\Database $database = null): int
    {
        if ($userId > 0) {
            $this->testUsers[$userId] = $database ?? \Pramnos\Framework\Factory::getDatabase();
        }

        return $userId;
    }

    /**
     * Reset request-scoped superglobals and the Request static state.
     *
     * Controller actions read route parameters from these superglobals — most
     * importantly the third URL segment, exposed as `$_GET['_option']` (see
     * {@see \Pramnos\Http\Request::staticGetOption()}). Because they are
     * process-global, a value set by one test would otherwise leak into the
     * next. setUp() calls this for every test; test cases that provide their
     * own bespoke setUp() (and therefore do not chain to this one) should call
     * it explicitly so they get the same isolation.
     */
    protected function resetRequestState(): void
    {
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $requestClass = '\Pramnos\Http\Request';
        if (class_exists($requestClass)) {
            $request = new $requestClass();
            $request->setAction('');
            $requestClass::$originalRequest = '';
            $requestClass::$requestMethod = 'GET';
        }
    }

    /**
     * Build the schema for specific tables by running the real migrations.
     *
     * Tests should exercise the same table definitions production ships, not
     * hand-rolled CREATE TABLE statements that can silently drift from the
     * migrations. This helper runs the up() of each given migration against the
     * test database — the migration files (under database/migrations/) are the
     * single source of truth for the schema.
     *
     * Migrations are classmap-autoloaded (see composer.json "classmap"), so
     * pass fully-qualified class names, e.g.
     * `\Pramnos\Framework\Migrations\Auth\CreateUserTwofactorTable::class`.
     *
     * Each migration's up() is idempotent (it early-returns when the table
     * already exists); DROP the target tables first if you need a guaranteed
     * fresh schema.
     *
     * @param array<int,class-string<\Pramnos\Database\Migration>> $migrationClasses
     * @param \Pramnos\Database\Database|null $db Target DB (defaults to the Factory singleton).
     */
    protected function runMigrations(array $migrationClasses, $db = null): void
    {
        // One implementation, in Testing\Schema, because the tests that need this most
        // are the ones that do not extend this class — a unit test with its own bare
        // TestCase cannot reach a protected method, and that is exactly how a second,
        // hand-rolled `CREATE TABLE` gets written instead.
        Schema::ensure($migrationClasses, $db);
    }

    /**
     * Get or create a PDO database connection.
     * 
     * Automatically handles both MySQL and PostgreSQL connections and 
     * detects Docker environments for hostname resolution.
     * 
     * @return PDO
     * @throws \PDOException
     */
    protected function getConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        /*
         * The settings the suite already loaded, when nothing has been set explicitly.
         * `Settings::getSetting()` answers an object; the cast below reads either.
         */
        $config = (array) (self::$dbConfig ?? \Pramnos\Application\Settings::getSetting('database'));

        if ($config === []) {
            throw new \RuntimeException(
                'No database settings for the test connection. Load them with '
                . 'Settings::loadSettings(), or set ' . static::class . '::$dbConfig. '
                . 'Note that TestEnvironment::setup() does NOT load them: it reads the '
                . 'test settings file directly, for the credentials it needs to recreate '
                . 'the database, and tells nothing else about them.'
            );
        }

        $host = $config['hostname'];
        $port = $config['port'] ?? null;
        $dbName = $config['database'];
        $user = $config['user'];
        $pass = $config['password'];
        $type = $config['type'] ?? 'mysql';

        // Docker detection (assumes standard container names)
        if ($host === 'localhost' && $this->isDocker()) {
            $host = ($type === 'postgresql' || $type === 'pgsql') ? 'postgres' : 'mysql';
        }

        try {
            $dsn = $this->buildDsn($type, $host, $dbName, $port);

            // Assigned, not just returned. The `if ($this->pdo !== null)` above was
            // unreachable and `$this->pdo` stayed null for ever — which is what
            // assertDatabaseHas() then called `prepare()` on.
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE   => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT   => self::CONNECT_TIMEOUT,
            ]);

            return $this->pdo;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * The DSN this configuration produces.
     *
     * Extracted so it can be asserted on directly. The tests that cover it used to
     * attempt a connection and read the host out of the failure message, which meant
     * three of the slowest tests in the suite were waiting **8.00 seconds each** for a
     * resolver to give up on a hostname that was never going to exist — to prove
     * something visible here without a socket at all.
     *
     * A DSN is a string built from configuration. Asserting on the string is both
     * faster and a closer statement of what is being claimed.
     *
     * @param  string      $type   mysql, postgresql or pgsql
     * @param  string      $host   Already Docker-substituted by the caller
     * @param  string      $dbName
     * @param  int|string|null $port
     * @return string
     */
    protected function buildDsn(string $type, string $host, string $dbName, $port = null): string
    {
        if ($type === 'postgresql' || $type === 'pgsql') {
            $dsn = "pgsql:host=$host;dbname=$dbName";
            if ($port) {
                $dsn .= ";port=$port";
            }
            // PostgreSQL takes its connect timeout in the DSN rather than as a driver
            // attribute; see CONNECT_TIMEOUT for what it does and does not cover.
            return $dsn . ';connect_timeout=' . self::CONNECT_TIMEOUT;
        }

        $dsn = "mysql:host=$host;dbname=$dbName";
        if ($port) {
            $dsn .= ";port=$port";
        }

        return $dsn;
    }

    /**
     * The database host this configuration resolves to, Docker substitution included.
     *
     * The substitution is the interesting part — inside a container `localhost` is the
     * container, not the database — and it was previously only observable by failing to
     * connect.
     *
     * @return string
     */
    protected function resolvedHost(): string
    {
        $config = (array) self::$dbConfig;
        $host   = (string) ($config['hostname'] ?? 'localhost');
        $type   = (string) ($config['type'] ?? 'mysql');

        if ($host === 'localhost' && $this->isDocker()) {
            return ($type === 'postgresql' || $type === 'pgsql') ? 'postgres' : 'mysql';
        }

        return $host;
    }

    /**
     * Initialize or reset PHP session with test data.
     * 
     * @param array $data Data to populate $_SESSION with
     */
    protected function initializeSession(array $data = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        $_SESSION = array_merge([
            'auth' => false,
            'user_id' => null,
            'username' => null,
            'csrf_token' => null
        ], $data);
    }

    /**
     * Sign a user in for the rest of the test.
     *
     * The keys are the ones the framework reads, which is not what this used to
     * set. `Session::staticIsLogged()` wants `logged` and a `uid` above 1, and
     * `User::getCurrentUser()` builds the user from `uid`; this set `auth` and
     * `user_id`, neither of which anything looks at. So the helper returned
     * quietly having signed nobody in, and a test using it exercised the
     * signed-out path while reading as though it covered the signed-in one — the
     * guard it meant to test was never reached.
     *
     * `uid` must be above 1: ids 0 and 1 are reserved for the guest and the
     * built-in system account, and `staticIsLogged()` rejects both.
     *
     * @param int|string $userId
     */
    protected function loginUser($userId): void
    {
        $this->initializeSession([
            // Kept: existing code and the session-tracking middleware read them.
            'auth' => true,
            'user_id' => $userId,
            // The ones that decide whether anybody is signed in.
            'logged' => true,
            'uid' => $userId,
        ]);

        /**
         * The web-session token a real login also leaves behind.
         *
         * `Auth::` creates one on every sign-in, and more than request logging reads
         * it: it is half of what lets a page call the application's own API — see
         * {@see \Pramnos\Http\Middleware\ApiAuthMiddleware}. Without it a test
         * signed a user in to a session that no real login produces, and the
         * same-origin path could not be tested at all.
         *
         * Best-effort: a unit test has no database, and failing to write a token is
         * not a reason to fail signing in.
         */
        try {
            $user = new \Pramnos\User\User((int) $userId);
            if ((int) $user->userid > 1) {
                $user->createWebSessionToken();
            }
        } catch (\Throwable) {
            // No database, or no usertokens table — the rest of the session stands.
        }

        // The identity is cached on the application for the length of a request,
        // so a stale one would answer for the user just signed in.
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app !== null) {
            $app->currentUser = null;
        }
    }

    /**
     * The `X-CSRF-Token` header a same-origin call to this application's API needs.
     *
     * A page presents no API key — it presents its session plus this token, which is
     * what `ApiAuthMiddleware` accepts in place of one. Spelled as a `$_SERVER` key
     * because that is what a test dispatcher takes.
     *
     * @return array{HTTP_X_CSRF_TOKEN: string}
     */
    protected function sameOriginApiHeaders(): array
    {
        return [
            'HTTP_X_CSRF_TOKEN' => \Pramnos\Http\Session::getInstance()->getCsrfToken(),
        ];
    }

    /**
     * Sign out, undoing loginUser().
     *
     * A test that signs in and then checks the guest path needs this: the
     * session is process-wide, so without it the login carries into every test
     * after it.
     */
    protected function logoutUser(): void
    {
        unset(
            $_SESSION['logged'],
            $_SESSION['uid'],
            $_SESSION['auth'],
            $_SESSION['user_id'],
            // Left behind, this is still a live credential: it is what
            // ApiAuthMiddleware accepts from a same-origin page.
            $_SESSION['usertoken']
        );

        $app = \Pramnos\Application\Application::currentInstance();
        if ($app !== null) {
            $app->currentUser = null;
        }
    }

    /**
     * Generate and store a CSRF token in the session.
     * 
     * @return string
     */
    protected function generateCSRFToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Get the current CSRF token from the session.
     * 
     * @return string|null
     */
    protected function getCSRFToken(): ?string
    {
        return $_SESSION['csrf_token'] ?? null;
    }

    /**
     * Populate $_POST with a valid CSRF token from the current session.
     */
    protected function addValidCsrfPostField(): void
    {
        $token = $this->getCSRFToken();

        if (!$token) {
            $token = $this->generateCSRFToken();
        }

        $_POST['csrf_token'] = $token;
    }

    /**
     * A minted-and-solved human check, as the two form fields a submission carries.
     *
     * For tests of a form that has `auth.security.human_check` switched on. Without this
     * they have to either scrape the challenge out of the rendered page and reimplement
     * the worker's search, or switch the check off for the test run — and the second one
     * leaves the shipped configuration untested, which is how a check that locks every
     * visitor out reaches production with a green suite.
     *
     * Pass the challenge a test scraped out of a rendered form to solve that one — which is
     * what a helper posting a real form should do. With nothing passed, one is minted here
     * instead: it is signed with the same key the request will verify it against, because
     * this is the same process, and its difficulty is the minimum, because the point is to
     * prove the flow accepts a correct answer rather than to spend the suite's time on
     * hashes.
     *
     * @param  ?string $challenge The token from the form's `human_challenge` field.
     * @return array{human_challenge: string, human_solution: string}
     */
    protected function solvedHumanCheckFields(?string $challenge = null): array
    {
        $check = new \Pramnos\Security\HumanCheck(1);

        if ($challenge === null || $challenge === '') {
            $challenge = $check->challenge()['challenge'];
        }

        // The signed payload is the token without its signature — the last of its four
        // dot-separated fields — and the difficulty is the second. Hashing the whole token
        // instead produces a solution `verify()` refuses.
        $parts      = explode('.', $challenge);
        $payload    = implode('.', array_slice($parts, 0, 3));
        $difficulty = (int) ($parts[1] ?? 0);

        for ($nonce = 0; $nonce < 50000000; $nonce++) {
            $candidate = base_convert((string) $nonce, 10, 36);

            if ($check->meetsDifficulty($payload, $candidate, $difficulty)) {
                return [
                    'human_challenge' => $challenge,
                    'human_solution'  => $candidate,
                ];
            }
        }

        // Unreachable in practice — a solution exists for any difficulty this class mints.
        // Failing loudly beats returning fields that will be refused for a reason the test
        // then reports as something else.
        throw new \RuntimeException(
            'No solution found for a ' . $difficulty . '-bit human check'
        );
    }

    /**
     * Assert that a record exists in the database.
     * 
     * @param string $table Table name
     * @param array  $criteria Column-value pairs
     */
    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $where = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $where[] = "$column = ?";
            $params[] = $value;
        }
        
        $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $where);
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        
        $this->assertGreaterThan(0, $stmt->fetchColumn(), "Database table '$table' does not contain matching record.");
    }

    /**
     * Assert that a record does not exist in the database.
     * 
     * @param string $table Table name
     * @param array  $criteria Column-value pairs
     */
    protected function assertDatabaseMissing(string $table, array $criteria): void
    {
        $where = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $where[] = "$column = ?";
            $params[] = $value;
        }
        
        $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $where);
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        
        $this->assertEquals(0, $stmt->fetchColumn(), "Database table '$table' contains unexpected matching record.");
    }

    /**
     * Detect if running inside a Docker container.
     * 
     * @return bool
     */
    protected function isDocker(): bool
    {
        return file_exists('/.dockerenv');
    }
}
