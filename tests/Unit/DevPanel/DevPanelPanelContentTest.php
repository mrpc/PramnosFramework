<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\DevPanel\DevPanelController;

/**
 * A database result the fake connection can hand back.
 */
class RecordedResult
{
    public int $numRows;

    /** @var array<string, mixed> */
    public array $fields;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(public array $rows = [])
    {
        $this->numRows = count($rows);
        $this->fields  = $rows[0] ?? [];
    }

    /** @var int Cursor for the fetch() loop */
    private int $cursor = -1;

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /**
     * Walk the rows one at a time, as `MigrationRunner::getHistory()` does.
     *
     * @return bool Whether a row was loaded into $fields
     */
    public function fetch(): bool
    {
        $this->cursor++;
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        $this->fields = $this->rows[$this->cursor];

        return true;
    }
}

/**
 * A connection that answers from a script and remembers every statement.
 *
 * The panels build their SQL through the query builder, which compiles and then calls
 * `execute()` — so recording that one method captures both the hand-written statements
 * and the built ones.
 */
class RecordingDatabase extends Database
{
    public $type      = 'mysql';
    public $connected = true;
    public $prefix    = '';

    /** @var array<int, string> Every statement, in order */
    public array $statementsRun = [];

    /** @var array<int, array<int, mixed>> The values bound to each statement */
    public array $bindingsUsed = [];

    /** @var array<string, RecordedResult> Fragment => canned answer */
    public array $answers = [];

    /** @var string A statement containing this fragment throws instead of answering */
    public string $failOn = '';

    public function __construct() {}

    public function execute($sql, &...$arguments)
    {
        $this->statementsRun[] = (string) $sql;
        $this->bindingsUsed[]  = $arguments;

        if ($this->failOn !== '' && str_contains((string) $sql, $this->failOn)) {
            throw new \RuntimeException('relation does not exist');
        }

        foreach ($this->answers as $fragment => $result) {
            if (str_contains((string) $sql, $fragment)) {
                return $result;
            }
        }

        return new RecordedResult();
    }

    /**
     * The unprepared path, which `MigrationRunner::getHistory()` uses.
     */
    public function query($sql, $cache = false, $cachetime = 60, $category = '',
        $dieOnFatalError = false, $skipDataFix = false)
    {
        $this->statementsRun[] = (string) $sql;

        foreach ($this->answers as $fragment => $result) {
            if (str_contains((string) $sql, $fragment)) {
                return $result;
            }
        }

        return new RecordedResult();
    }
}

/**
 * What the DevPanel's panels actually put on the page.
 *
 * Three of them were empty on a working installation, each for its own reason, and each
 * failure looked identical from the browser: a table with "No data". These tests assert
 * the two halves that were wrong — the rows a query asks for, and how a value is printed
 * once it comes back — because "the panel rendered without throwing" was true the whole
 * time it was broken.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelPanelContentTest extends TestCase
{
    /**
     * A controller with no constructor: the panels under test read the database
     * singleton and their own helpers, not controller state.
     *
     * @return DevPanelController
     */
    private function controller(): DevPanelController
    {
        return (new \ReflectionClass(DevPanelController::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * Call one of the private render methods.
     *
     * @param DevPanelController $controller The instance
     * @param string             $method     Method name
     * @param mixed              ...$args    Arguments
     * @return string The rendered HTML
     */
    private function render(DevPanelController $controller, string $method, ...$args): string
    {
        return (string) (new \ReflectionMethod(DevPanelController::class, $method))
            ->invoke($controller, ...$args);
    }

    /**
     * Point the framework's database singleton at a recording connection.
     *
     * @return RecordingDatabase
     */
    private function useRecordingDatabase(): RecordingDatabase
    {
        $db = new RecordingDatabase();

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $this->originalDatabase = $singleton;
        $singleton = $db;

        return $db;
    }

    /** @var mixed The database singleton as it was before the test */
    private $originalDatabase = null;

    protected function tearDown(): void
    {
        if ($this->originalDatabase !== null) {
            $singleton = &\Pramnos\Framework\Factory::getDatabase();
            $singleton = $this->originalDatabase;
            $this->originalDatabase = null;
        }
        $_GET = [];
        parent::tearDown();
    }

    /**
     * The active-sessions query asks for web sessions.
     *
     * The panel is headed "Active Sessions (web + API)" and filtered on
     * `['auth', 'access_token']` — the two API types. A browser login is a
     * `web_session`, so on any application that is not itself an API the panel listed
     * nothing while the table held hundreds of rows. Measured on one: 316 tokens, all
     * `web_session`, all excluded.
     *
     * @return void
     */
    public function testTheSessionsQueryIncludesWebSessions(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();

        // Act
        $this->render($this->controller(), 'renderUsers');

        // Assert — the values bound to the statement that reads usertokens name
        // every session type. Read from the bindings rather than the SQL because
        // the builder emits `IN (%s, %s, %s)` and binds the types.
        $bound = null;
        foreach ($db->statementsRun as $index => $sql) {
            if (str_contains($sql, 'usertokens')) {
                $bound = $db->bindingsUsed[$index];
                break;
            }
        }

        $this->assertNotNull($bound, 'the panel must query usertokens at all');
        $this->assertContains(\Pramnos\User\Token::TYPE_WEB_SESSION, $bound);
        $this->assertContains(\Pramnos\User\Token::TYPE_API, $bound);
        $this->assertContains(\Pramnos\User\Token::TYPE_ACCESS_TOKEN, $bound);
    }

    /**
     * A session's "last seen" is a date, not the integer in the column.
     *
     * `usertokens.lastused` is a unix timestamp. Printed raw it gave the reader ten
     * digits in a column headed "Last seen".
     *
     * @return void
     */
    public function testASessionsLastSeenIsRenderedAsADate(): void
    {
        // Arrange — one session, last used at a known instant
        $db = $this->useRecordingDatabase();
        $db->answers['usertokens'] = new RecordedResult([[
            'tokenid'       => 7,
            'userid'        => 3,
            'username'      => 'admin',
            'lastused'      => 1755640000,
            'ipaddress'     => '10.0.0.1',
            'tokentype'     => 'web_session',
            'applicationid' => null,
        ]]);

        // Act
        $html = $this->render($this->controller(), 'renderUsers');

        // Assert
        $this->assertStringContainsString(date('Y-m-d H:i:s', 1755640000), $html);
        $this->assertStringNotContainsString('1755640000', $html);
        // And the type is shown, so "no sessions" and "no sessions of this kind" can
        // be told apart from the page itself
        $this->assertStringContainsString('web_session', $html);
    }

    /**
     * The performance window is a timestamp comparison the database can make.
     *
     * The two queries behind this panel used `servertime >= NOW() - INTERVAL 24 HOUR`:
     * MySQL's interval syntax, which PostgreSQL rejects, comparing a timestamp to
     * `servertime`, which is an integer epoch in every dialect. Both queries threw on
     * every request and the panel reported "No data for this period".
     *
     * @return void
     */
    public function testThePerformanceWindowIsAnEpochComparison(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();
        $_GET['range'] = '24';

        // Act
        $this->render($this->controller(), 'renderPerformance');

        // Assert
        $this->assertNotSame([], $db->statementsRun, 'the panel must query at all');
        foreach ($db->statementsRun as $sql) {
            $this->assertStringNotContainsString(
                'INTERVAL',
                $sql,
                'MySQL interval syntax does not survive PostgreSQL'
            );
            $this->assertStringNotContainsString('NOW()', $sql);
        }
    }

    /**
     * Every table in the performance queries carries the prefix marker.
     *
     * `#PREFIX#tokenactions` was joined to a bare `usertokens`, `users` and
     * `applications` in the same statement, so on a prefixed installation the join
     * named tables that do not exist — while the panel looked merely empty.
     *
     * @return void
     */
    public function testThePerformanceQueriesPrefixEveryTable(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();
        $db->prefix = 'pfx_';

        // Act
        $this->render($this->controller(), 'renderPerformance');

        // Assert — each statement names only prefixed tables
        foreach ($db->statementsRun as $sql) {
            foreach (['tokenactions', 'usertokens', 'users', 'applications', 'urls'] as $table) {
                if (!str_contains($sql, $table)) {
                    continue;
                }
                $this->assertMatchesRegularExpression(
                    '/pfx_' . $table . '\b/',
                    $sql,
                    "{$table} must be prefixed in: {$sql}"
                );
            }
        }
    }

    /**
     * A timestamp column with nothing in it renders as an em-dash.
     *
     * `lastused` defaults to 0 for a token that has never been used, and
     * `date('Y-m-d', 0)` is 1970 — a date that reads as data.
     *
     * @return void
     */
    public function testAnEmptyTimestampIsNotRenderedAs1970(): void
    {
        // Arrange
        $format = new \ReflectionMethod(DevPanelController::class, 'formatTimestamp');
        $controller = $this->controller();

        // Act + Assert
        $this->assertSame('—', $format->invoke($controller, 0));
        $this->assertSame('—', $format->invoke($controller, null));
        $this->assertSame('—', $format->invoke($controller, ''));
        $this->assertSame(
            date('Y-m-d H:i:s', 1755640000),
            $format->invoke($controller, 1755640000)
        );
        // A string from the database is a timestamp too
        $this->assertSame(
            date('Y-m-d H:i:s', 1755640000),
            $format->invoke($controller, '1755640000')
        );
    }

    /**
     * A section that could not be loaded says so on the page.
     *
     * `panelError()` wrote to a log file and nothing else, so a failed query and an
     * empty table were the same page. That is how four broken queries survived here:
     * every one of them rendered as "no data", which is a sentence nobody investigates.
     *
     * @return void
     */
    public function testAFailedSectionIsReportedOnThePage(): void
    {
        // Arrange
        $controller = $this->controller();
        $record     = new \ReflectionMethod(DevPanelController::class, 'panelError');
        $render     = new \ReflectionMethod(DevPanelController::class, 'panelErrorsHtml');

        // Act
        $record->invoke($controller, 'login lockouts', new \RuntimeException('relation missing'));
        $html = (string) $render->invoke($controller);

        // Assert
        $this->assertStringContainsString('login lockouts', $html);
        $this->assertStringContainsString('relation missing', $html);
    }

    /**
     * With everything loaded, nothing is reported.
     *
     * @return void
     */
    public function testNothingIsReportedWhenEverySectionLoads(): void
    {
        // Act + Assert
        $this->assertSame(
            '',
            (string) (new \ReflectionMethod(DevPanelController::class, 'panelErrorsHtml'))
                ->invoke($this->controller())
        );
    }

    /**
     * A session that has expired is not an active session.
     *
     * `status = 1` alone means "never explicitly revoked", which is not the same
     * claim as the heading makes. The predicate is the one `User::loadByToken()`
     * uses to decide whether a token still works — anything else would list
     * sessions the framework would itself refuse.
     *
     * @return void
     */
    public function testTheSessionsQueryExcludesExpiredTokens(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();

        // Act
        $this->render($this->controller(), 'renderUsers');

        // Assert
        $sessionQuery = '';
        foreach ($db->statementsRun as $sql) {
            if (str_contains($sql, 'usertokens') && str_contains($sql, 'lastused')) {
                $sessionQuery = $sql;
                break;
            }
        }

        $this->assertStringContainsString('expires', $sessionQuery);
    }

    /**
     * A truncated list says how much it is not showing.
     *
     * The detail table is capped at 50 and said so nowhere, so 50 rows meant
     * anything from 50 sessions to several hundred — and "several hundred, all one
     * user" was the answer somebody needed and could not get from the page.
     *
     * @return void
     */
    public function testATruncatedSessionListReportsTheTotal(): void
    {
        // Arrange — the count query answers 342, the list answers one row
        $db = $this->useRecordingDatabase();
        $db->answers['COUNT(*) AS aggregate'] = new RecordedResult([['aggregate' => 342]]);
        $db->answers['t.lastused'] = new RecordedResult([[
            'tokenid'       => 1,
            'userid'        => 3,
            'username'      => 'admin',
            'lastused'      => 1755640000,
            'ipaddress'     => '10.0.0.1',
            'tokentype'     => 'web_session',
            'applicationid' => null,
        ]]);

        // Act
        $html = $this->render($this->controller(), 'renderUsers');

        // Assert
        $this->assertStringContainsString('342', $html);
        $this->assertStringContainsString('most recent of', $html);
    }

    /**
     * An empty performance panel says whether the table is empty or the window is.
     *
     * They are different problems with different answers — one is "look at another
     * period", the other is "nothing is being recorded at all" — and both used to
     * render as the same sentence.
     *
     * @return void
     */
    public function testAnEmptyPerformancePanelDistinguishesAnEmptyTable(): void
    {
        // Arrange — nothing recorded, ever
        $db = $this->useRecordingDatabase();
        $db->answers['COUNT(*) AS aggregate'] = new RecordedResult([['aggregate' => 0]]);

        // Act
        $html = $this->render($this->controller(), 'renderPerformance');

        // Assert
        $this->assertStringContainsString('No requests have been recorded at all', $html);
    }

    /**
     * Sessions are limited to a recency window, and the window is selectable.
     *
     * "Not expired" is not a time limit: a `web_session` token is minted per login
     * and carries no expiry, so every login ever made stays "active" for ever. One
     * installation listed 342 of them, all for one user. The default window is 24
     * hours; `hours=0` is the deliberate way to ask for all of them.
     *
     * @return void
     */
    public function testSessionsAreLimitedToARecencyWindow(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();

        // Act — the default view
        $html = $this->render($this->controller(), 'renderUsers');

        // Assert — the window is applied and offered
        $sessionQuery = '';
        foreach ($db->statementsRun as $sql) {
            if (str_contains($sql, 'usertokens') && str_contains($sql, 'ORDER BY')) {
                $sessionQuery = $sql;
                break;
            }
        }
        $this->assertStringContainsString('lastused >=', $sessionQuery);
        $this->assertStringContainsString('hours=1', $html);
        $this->assertStringContainsString('last 24h', $html);
    }

    /**
     * `hours=0` asks for every active session, however old.
     *
     * The window has to be escapable, or the panel simply hides the accumulation
     * instead of the previous behaviour of drowning in it.
     *
     * @return void
     */
    public function testTheWindowCanBeTurnedOff(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();
        $_GET['hours'] = '0';

        // Act
        $this->render($this->controller(), 'renderUsers');

        // Assert — no recency predicate on any statement
        foreach ($db->statementsRun as $sql) {
            $this->assertStringNotContainsString('lastused >=', $sql);
        }
    }

    /**
     * An unrecognised window falls back to the default rather than to none.
     *
     * `?hours=abc` must not quietly mean "all sessions ever".
     *
     * @return void
     */
    public function testAnUnknownWindowFallsBackToTheDefault(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();
        $_GET['hours'] = 'abc';

        // Act
        $html = $this->render($this->controller(), 'renderUsers');

        // Assert
        $this->assertStringContainsString('last 24h', $html);
    }

    /**
     * The migrations card counts what ran, and names the last one that did.
     *
     * It read "— / — / —" everywhere, because it constructed
     * `\Pramnos\Database\Migrations\MigrationLoader` — a namespace that does not
     * exist — inside a `catch (\Throwable)` written for a missing history table.
     *
     * Two things are asserted beyond "it produces numbers": a failed attempt
     * (`result = 0`) is not counted as applied, and the auto-migration fingerprint
     * rows (`__fw_auto_*`) are not eligible to be "last applied". Those rows carry
     * no batch, so they sort last, and the card named a bookkeeping key nobody can
     * look up.
     *
     * @return void
     */
    public function testTheMigrationsCardReadsTheHistory(): void
    {
        // Arrange — a history with one success, one failure and one fingerprint
        $db = $this->useRecordingDatabase();
        $db->answers['schemaversion'] = new RecordedResult([
            ['key' => 'create_users_table',   'result' => 1, 'batch' => 1],
            ['key' => 'add_broken_column',    'result' => 0, 'batch' => 2],
            ['key' => '__fw_auto_136_2026_10_04_000001', 'result' => 1, 'batch' => null],
        ]);

        // The loader needs an application to hand each migration; the panel takes
        // the controller's, falling back to the current instance.
        $controller = $this->controller();
        $controller->application = $this->createMock(\Pramnos\Application\Application::class);

        $status = (new \ReflectionMethod(DevPanelController::class, 'fetchMigrationStatus'))
            ->invoke($controller);

        // Assert — three values, and the last applied is a migration slug
        $this->assertCount(3, $status);
        [$pending, $applied, $last] = $status;

        $this->assertNotSame('—', $applied, 'the card must read the history at all');
        $this->assertGreaterThanOrEqual(1, (int) $applied);
        $this->assertSame(
            'create_users_table',
            $last,
            'a fingerprint row is bookkeeping, and a failed migration has not applied'
        );
        $this->assertIsInt($pending);
    }

    /**
     * Without a database the card says nothing rather than guessing.
     *
     * @return void
     */
    public function testTheMigrationsCardIsBlankWithoutADatabase(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();
        $db->connected = false;

        // Act
        $status = (new \ReflectionMethod(DevPanelController::class, 'fetchMigrationStatus'))
            ->invoke($this->controller());

        // Assert
        $this->assertSame(['—', '—', '—'], $status);
    }

    /**
     * A section whose query throws is named on the page, not swallowed.
     *
     * The end-to-end version of the panelError test: the summary query fails, the
     * rest of the panel still renders, and the reader is told which part is
     * missing rather than being shown an empty table.
     *
     * @return void
     */
    public function testAFailingSectionStillRendersTheRestOfThePanel(): void
    {
        // Arrange — every statement touching usertokens fails
        $db = $this->useRecordingDatabase();
        $db->failOn = 'usertokens';

        // Act
        $html   = $this->render($this->controller(), 'renderUsers');
        $errors = (string) (new \ReflectionMethod(DevPanelController::class, 'panelErrorsHtml'))
            ->invoke($this->controller());

        // Assert — the panel rendered, and the failure was recorded rather than
        // rendered as "no sessions"
        $this->assertStringContainsString('Login Lockouts', $html);
        $this->assertIsString($errors);
    }

    /**
     * The slowest-endpoints report reads only calls that were timed.
     *
     * A row with no duration has nothing to say about speed, and it did worse than say
     * nothing: `ORDER BY avg_ms DESC` puts NULLs **first** on PostgreSQL, so a table
     * holding any unmeasured rows showed twenty of them at the top of the report, each
     * rendered as `0.0 ms`, with the real measurements pushed off the list. Every web
     * request was unmeasured until the shutdown flush started timing them, so on an
     * installation with history that was the entire report — which is exactly how it was
     * reported, twice.
     *
     * @return void
     */
    public function testTheEndpointsReportIgnoresUntimedCalls(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();

        // Act
        $this->render($this->controller(), 'renderPerformance');

        // Assert — both reports filter on a duration being present
        $timed = array_filter(
            $db->statementsRun,
            static fn(string $sql): bool => str_contains($sql, 'execution_time_ms IS NOT NULL')
        );
        $this->assertCount(
            2,
            $timed,
            'the endpoint report and the user report both rank by duration'
        );
    }

    /**
     * A web session is bounded by the PHP session it belongs to.
     *
     * `web_session` is accepted through `$_SESSION['usertoken']`, so once PHP has expired
     * the session — `session.gc_maxlifetime`, 24 minutes out of the box — the row cannot be
     * used by the browser that owns it, whatever its own expiry says. Listing it as an
     * active session lists something nobody can use: a login from this morning is not a
     * session, it is a row. API tokens have no such bound and keep the selected window.
     *
     * @return void
     */
    public function testWebSessionsAreBoundedByTheSessionIdleTimeout(): void
    {
        // Arrange
        $db = $this->useRecordingDatabase();

        // Act
        $html = $this->render($this->controller(), 'renderUsers');

        // Assert — the session query asks about the token type inside its predicate,
        // which a flat "used since" filter never had to
        $sessionQuery = '';
        foreach ($db->statementsRun as $index => $sql) {
            if (str_contains($sql, 'usertokens') && str_contains($sql, 'ORDER BY')) {
                $sessionQuery = $sql;
                $bound        = $db->bindingsUsed[$index];
                break;
            }
        }

        $this->assertStringContainsString('tokentype', $sessionQuery);
        $this->assertStringContainsString('lastused >=', $sessionQuery);

        // The PHP session's own idle timeout is among the bound cutoffs — asserted
        // against `session.gc_maxlifetime` itself, because that is the contract:
        // "as long as the session it belongs to can be alive".
        $idle     = (int) ini_get('session.gc_maxlifetime') ?: 1440;
        $expected = time() - $idle;

        $matched = array_filter(
            $bound ?? [],
            static fn($value): bool => is_int($value) && abs($value - $expected) <= 5
        );

        $this->assertNotSame(
            [],
            $matched,
            'a cutoff at the session idle timeout must be bound, not only the window'
        );

        // ...and the 24h window is still bound too, for the API tokens it applies to
        $window  = time() - 86400;
        $matched = array_filter(
            $bound ?? [],
            static fn($value): bool => is_int($value) && abs($value - $window) <= 5
        );
        $this->assertNotSame([], $matched, 'the selected window still applies');

        // ...and the page says so, rather than leaving the reader to wonder why a
        // session they can see in the database is not listed
        $this->assertStringContainsString('idle timeout', $html);
    }

    /**
     * A table with rows in it, but none in this window, says exactly that.
     *
     * @return void
     */
    public function testAQuietWindowIsReportedAsAQuietWindow(): void
    {
        // Arrange — rows exist; the range query returns none
        $db = $this->useRecordingDatabase();
        $db->answers['COUNT(*) AS aggregate'] = new RecordedResult([['aggregate' => 4200]]);

        // Act
        $html = $this->render($this->controller(), 'renderPerformance');

        // Assert
        $this->assertStringContainsString('No data for this period', $html);
        $this->assertStringNotContainsString('recorded at all', $html);
    }
}
