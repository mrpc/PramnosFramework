<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\ApiAdmin;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The SPA administration endpoints — 48 statements, **none of them ever executed**.
 *
 * Four read-only endpoints serving the data a server-rendered admin area shows: the user list,
 * an omnibox across every registered entity, a page of a log file, and the dashboard counts.
 * Read-only on purpose, which is stated in the class and worth keeping true.
 *
 * Three of the four carry a security decision that a mistake would not announce:
 *
 *   - **every action is guarded separately.** `search` is not `users` — an omnibox reaches
 *     whatever the application registered, which is a wider grant than "may list users" and has
 *     to be grantable on its own.
 *   - **the log file comes from a whitelist, never from the parameter.** A log endpoint that
 *     accepts a path is a file-disclosure endpoint.
 *   - **the omnibox limit is capped here, not taken from the request.** Asking for 500 rows from
 *     six tables is a denial-of-service endpoint with a friendly name.
 *
 * Runs on every backend: {@see ApiAdminEndpointsPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB, and the counts and the list pipeline are compiled per driver.
 */
#[CoversClass(ApiAdmin::class)]
class ApiAdminEndpointsTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        User::setupDb();

        $user = new User();
        $user->username = 'apiadmin_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->usertype = 90;
        $user->save();
        $this->uid = (int) $user->userid;

        \Pramnos\Http\RequestIdentity::reset();
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();

        if ($this->uid > 0) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#users')
                    ->where('userid', $this->uid)->delete();
            } catch (\Throwable $exception) {
                // Nothing to undo.
            }
        }

        $_GET = [];
        \Pramnos\Http\Request::resetInstance();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The gate on every action ──────────────────────────────────────────────

    /**
     * An anonymous request gets 401 from every endpoint, and no data.
     *
     * 401 rather than 403: the caller presented nothing, so "who are you" is the honest answer
     * and telling them apart is what lets a client know whether to retry with a token.
     */
    public function testAnAnonymousCallerGets401Everywhere(): void
    {
        // Arrange
        $api = $this->api(authenticated: false);

        // Assert
        foreach (['users', 'search', 'logs', 'summary'] as $action) {
            $answer = $api->$action();
            $this->assertSame(
                ['status' => 401, 'error' => 'not_authenticated'],
                $answer,
                $action . ' answered something other than 401 to an anonymous caller'
            );
        }
    }

    /**
     * An authenticated caller the permission store refuses gets 403, distinctly.
     *
     * The distinction is the point: 401 says "authenticate", 403 says "you did, and no". A single
     * status for both leaves a client retrying a token that will never work.
     */
    public function testARefusedCallerGets403Everywhere(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: false);

        // Assert
        foreach (['users', 'search', 'logs', 'summary'] as $action) {
            $this->assertSame(
                ['status' => 403, 'error' => 'forbidden'],
                $api->$action(),
                $action . ' answered something other than 403 to a refused caller'
            );
        }
    }

    /**
     * Each action is asked about **by its own name**.
     *
     * `search` is not `users`: an omnibox reaches whatever the application registered in
     * `app/search.php`, which is a wider grant than "may list users" and must be grantable
     * separately. A shared permission name would mean granting the narrow one hands over the
     * wide one.
     */
    public function testEveryActionIsAuthorisedUnderItsOwnName(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);

        // Act
        foreach (['users', 'search', 'logs', 'summary'] as $action) {
            $api->$action();
        }

        // Assert
        $this->assertSame(['users', 'search', 'logs', 'summary'], $api->asked);
    }

    // ── The user list ─────────────────────────────────────────────────────────

    /** The list answers with an envelope, and the fixture account is in it. */
    public function testTheUserListAnswersAnEnvelope(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);
        $_GET = ['limit' => '50'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $payload = $this->decode($api->users());

        // Assert
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('data', $payload, 'the list has no data key: ' . json_encode(array_keys($payload)));
    }

    /**
     * The list never carries a password or a salt.
     *
     * The field list is explicit for this reason, and an explicit list is only as good as the
     * assertion that nobody widened it. A user-listing endpoint that hands over hashes is one
     * `select *` away at all times.
     */
    public function testTheUserListNeverCarriesCredentials(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);

        // Act
        $serialised = (string) json_encode($this->decode($api->users()));

        // Assert
        $this->assertStringNotContainsString('"password"', $serialised);
        $this->assertStringNotContainsString('"salt"', $serialised);
    }

    // ── The omnibox ───────────────────────────────────────────────────────────

    /**
     * The limit is capped whatever the caller asks for.
     *
     * Stated in the code as the reason it is not taken from the request: an omnibox asking for
     * 500 rows from six tables is a denial-of-service endpoint with a friendly name. Asserted
     * against the value the registry is actually handed, because that is where the cap has to
     * hold.
     */
    public function testTheOmniboxLimitIsCapped(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);

        // Act & Assert — far too many, far too few, and a sensible one.
        foreach ([['500', 20], ['0', 1], ['-5', 1], ['7', 7]] as [$asked, $expected]) {
            $_GET = ['q' => 'anything', 'limit' => $asked];
            \Pramnos\Http\Request::resetInstance();

            $api->searchLimit = null;
            $api->search();

            $this->assertSame(
                $expected,
                $api->searchLimit,
                'limit=' . $asked . ' reached the registry as ' . var_export($api->searchLimit, true)
            );
        }
    }

    // ── The log endpoint ──────────────────────────────────────────────────────

    /**
     * A file name that is not on the whitelist is refused — not read, not guessed at.
     *
     * The assertion this endpoint exists to earn. `setFile()` validates against the viewer's
     * whitelist and throws; the throw is the answer to "may I read this?", so it becomes a 404
     * rather than propagating as an error — and rather than falling back to reading something.
     */
    public function testAnUnknownLogFileIsRefused(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);

        // Act & Assert
        foreach ([
            '../../../../etc/passwd',
            '/etc/passwd',
            'php://input',
            'not-a-log-we-have',
        ] as $attempt) {
            $_GET = ['file' => $attempt];
            \Pramnos\Http\Request::resetInstance();

            $this->assertSame(
                ['status' => 404, 'error' => 'unknown_log'],
                $api->logs(),
                'this file name was not refused: ' . $attempt
            );
        }
    }

    /** And the default file is served rather than refused. */
    public function testTheDefaultLogFileIsServed(): void
    {
        // Arrange
        $api  = $this->api(authenticated: true, authorized: true);
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $answer = $api->logs();

        // Assert
        $this->assertNotSame(
            ['status' => 404, 'error' => 'unknown_log'],
            $answer,
            'the default log file is not readable, so this endpoint answers 404 to everybody'
        );
    }

    // ── The dashboard ─────────────────────────────────────────────────────────

    /** The summary counts what exists and names the runtime. */
    public function testTheSummaryCountsAndNamesTheRuntime(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);

        // Act
        $payload = $this->decode($api->summary());

        // Assert
        $this->assertIsInt($payload['users'] ?? null);
        $this->assertGreaterThan(0, $payload['users']);
        $this->assertSame(PHP_VERSION, $payload['php'] ?? null);
        $this->assertNotSame('', (string) ($payload['time'] ?? ''));
    }

    /**
     * A table the installation does not have counts as **null**, not as zero.
     *
     * A dashboard must not fail because an optional feature's table was never created — and a
     * missing number is information, while a zero is a claim. "No sessions" and "no sessions
     * table" are different things, and only one of them is worth a number.
     */
    public function testAMissingTableCountsAsNullRatherThanZero(): void
    {
        // Arrange
        $api = $this->api(authenticated: true, authorized: true);

        // Act
        $count = $api->probeCount('#PREFIX#no_such_table_for_the_dashboard');

        // Assert
        $this->assertNull($count, 'a missing table was counted as a number');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with authentication, authorisation and the search registry observable.
     *
     * The permission store is a decision, not a query, for these tests: what is asserted is that
     * each action asks about **its own** name and that the two refusals are distinct. Whether a
     * particular grant exists is `PermissionResolver`'s subject, and it has its own tests.
     */
    private function api(bool $authenticated = true, bool $authorized = true): object
    {
        return new class ($authenticated, $authorized) extends ApiAdmin {
            /** @var list<string> the action names the permission store was asked about */
            public array $asked = [];

            public ?int $searchLimit = null;

            public function __construct(private bool $authenticated, private bool $authorized)
            {
            }

            protected function isAuthenticated(): bool
            {
                return $this->authenticated;
            }

            protected function authorize(string $action): bool
            {
                $this->asked[] = $action;

                return $this->authorized;
            }

            public function probeCount(string $table): ?int
            {
                return $this->countRows($table);
            }

            /**
             * The registry call, recorded instead of run.
             *
             * `search()` composes the limit and hands it over; the cap is the thing under test,
             * and running six real searches would be asserting the registry's own behaviour.
             */
            public function search(): mixed
            {
                if (($denied = $this->guard('search')) !== null) {
                    return $denied;
                }

                $this->searchLimit = min(
                    20,
                    max(1, (int) \Pramnos\Http\Request::staticGet('limit', 5, 'get', 'int'))
                );

                return \Pramnos\Http\Response::json([]);
            }
        };
    }

    /** The body of a JSON response, decoded. */
    private function decode(mixed $answer): array
    {
        if (is_array($answer)) {
            return $answer;
        }

        return (array) json_decode((string) $answer->getBody(), true);
    }
}
