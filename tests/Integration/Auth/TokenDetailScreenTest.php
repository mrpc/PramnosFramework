<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\TokensController;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The token detail page — `TokensController::view()`, 32 of the class's 42 unexecuted statements.
 *
 * Its siblings are covered: the list, the revoke, the bulk revoke, the per-user list, the usertype
 * floors. This is the one method with no test, and it is the page somebody opens when an
 * integration misbehaves — whose token it is, which application issued it, when it was last used,
 * how many calls it has made and what the last of them were.
 *
 * Three of its lines are the ones worth pinning, and none of them is about what the page shows:
 *
 *   - **a `limit` is clamped, not obeyed.** It arrives in the query string, and a token belonging
 *     to a busy integration has tens of thousands of actions. `?limit=100000` on an admin page is
 *     a request that either times out or exhausts memory, and the person who typed it is
 *     debugging something else;
 *   - **a `page` below one is floored.** Otherwise the offset goes negative, which on PostgreSQL
 *     is an error rather than a clamp — so the page would work in development and fail in
 *     production, or the other way round;
 *   - **a token that is not there is said so.** An operator following a link from an old ticket
 *     gets a sentence and the list, rather than a page of empty labels that reads as the token
 *     existing and having done nothing.
 *
 * Both backends: {@see TokenDetailScreenPostgreSQLTest} re-runs it. The action list is a join with
 * a `COUNT(*)` beside it and a `LIMIT`/`OFFSET`, and `tokenactions` is a hypertable on one engine.
 */
#[CoversClass(TokensController::class)]
class TokenDetailScreenTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private int $tokenId = 0;

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

        /*
         * Dropped, then migrated.
         *
         * `usertokens` and `tokenactions` each have several owners in this suite, some of which
         * build them by hand with a narrower shape and `CREATE TABLE IF NOT EXISTS`. Whichever
         * ran first would otherwise decide what this test is asserting against — which is how
         * three runs were lost today.
         */
        foreach (['#PREFIX#tokenactions', '#PREFIX#urls'] as $table) {
            $this->db->query(
                'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable($table)
            );
        }
        $this->runMigrations([
            // `getDetails()` left-joins `applications` for the issuing client's name, so the
            // table has to exist even when the token belongs to no application.
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\Auth\AddTokenLookupToUsertokens::class,
            \Pramnos\Framework\Migrations\Auth\CreateUrlsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateTokenactionsTable::class,
        ], $this->db);

        $user = new User();
        $user->username = 'tokenview_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->uid = (int) $user->userid;

        $this->tokenId = $this->seedToken();

        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/admin/Tokens/view';
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        if ($this->tokenId > 0) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#tokenactions')
                    ->where('tokenid', $this->tokenId)->delete();
                $this->db->queryBuilder()->table('#PREFIX#usertokens')
                    ->where('tokenid', $this->tokenId)->delete();
            } catch (\Throwable $exception) {
                // Nothing to undo.
            }
        }

        if ($this->uid > 0) {
            foreach (['#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }

        $_GET  = [];
        $_POST = [];
        unset($_SERVER['REQUEST_URI']);
        \Pramnos\Http\Request::resetInstance();
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── What the page shows ───────────────────────────────────────────────────

    /**
     * The page joins what three separate screens showed apart.
     *
     * Details, statistics and the recent calls in one place, which is the whole reason the method
     * exists: an operator investigating an integration had the list and the actions log and no
     * page that put them together.
     */
    public function testThePageCarriesTheDetailsTheStatsAndTheActions(): void
    {
        // Arrange
        $this->seedActions(3);
        $probe = $this->probe();
        $_GET['id'] = (string) $this->tokenId;
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertSame([], $probe->redirects, 'the page redirected instead of rendering');
        $this->assertIsArray($probe->assigned['token'] ?? null, 'no details were assigned');
        $this->assertIsArray($probe->assigned['stats'] ?? null, 'no statistics were assigned');
        $this->assertCount(3, (array) ($probe->assigned['actions'] ?? []));
        $this->assertSame(3, (int) ($probe->assigned['pagination']['total'] ?? 0));
    }

    /**
     * The link shape the dashboard actually uses is accepted.
     *
     * The path segment is what the router exposes as the "option", and `?id=` is how the
     * dashboard's own links are written. Accepting only one of the two makes half the links on
     * the site land on "the id in that link is not valid".
     */
    public function testTheQueryStringFormOfTheLinkIsAccepted(): void
    {
        // Arrange
        $probe = $this->probe();
        $_GET['id'] = (string) $this->tokenId;
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertSame([], $probe->redirects);
        $this->assertSame($this->tokenId, (int) ($probe->assigned['token']['tokenid'] ?? 0));
    }

    // ── What it refuses ───────────────────────────────────────────────────────

    /** A link with no usable id says so and goes back to the list. */
    public function testALinkWithNoIdIsRefused(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->view();

        // Assert
        $this->assertNotSame([], $probe->errors, 'a bad link produced no message');
        $this->assertNotSame([], $probe->redirects);
        $this->assertSame([], $probe->assigned, 'a page was built for a token nobody named');
    }

    /**
     * A token that is not there is said so, rather than drawn empty.
     *
     * An operator following a link out of a six-month-old ticket. A page of empty labels reads as
     * the token existing and having done nothing, which is the opposite of the truth and the
     * beginning of a long afternoon.
     */
    public function testATokenThatIsNotThereIsSaidSo(): void
    {
        // Arrange
        $probe = $this->probe();
        $_GET['id'] = '987654321';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertNotSame([], $probe->errors);
        $this->assertNotSame([], $probe->redirects);
        $this->assertSame([], $probe->assigned);
    }

    // ── The two numbers that arrive in the query string ───────────────────────

    /**
     * A `limit` outside the allowed range falls back to the default.
     *
     * It comes from the query string of an admin page. A token belonging to a busy integration
     * has tens of thousands of actions, so `?limit=100000` is a request that times out or
     * exhausts memory — and the person who typed it was debugging something else entirely.
     */
    public function testAnOutOfRangeLimitFallsBackToTheDefault(): void
    {
        // Arrange
        $this->seedActions(2);

        // Act & Assert
        foreach (['100000', '0', '-5', 'nonsense', '501'] as $attempt) {
            $probe = $this->probe();
            $_GET  = ['id' => (string) $this->tokenId, 'limit' => $attempt];
            \Pramnos\Http\Request::resetInstance();

            $probe->view();

            $this->assertSame(
                50,
                (int) ($probe->assigned['pagination']['limit'] ?? 0),
                'a limit of ' . $attempt . ' was obeyed'
            );
        }
    }

    /** A limit inside the range is honoured, so the clamp is not just a constant. */
    public function testALimitInsideTheRangeIsHonoured(): void
    {
        // Arrange
        $this->seedActions(5);
        $probe = $this->probe();
        $_GET  = ['id' => (string) $this->tokenId, 'limit' => '2'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertSame(2, (int) ($probe->assigned['pagination']['limit'] ?? 0));
        $this->assertCount(2, (array) ($probe->assigned['actions'] ?? []));
        $this->assertSame(
            5,
            (int) ($probe->assigned['pagination']['total'] ?? 0),
            'the total counts the page rather than the token'
        );
    }

    /**
     * A page below one is floored rather than passed through.
     *
     * `($page - 1) * $limit` with a page of zero or less is a negative offset, which PostgreSQL
     * refuses outright while MySQL is more forgiving — so without the floor this page works on
     * one engine and 500s on the other, which is the worst way to find out.
     */
    public function testAPageBelowOneIsFloored(): void
    {
        // Arrange
        $this->seedActions(2);

        // Act & Assert
        foreach (['0', '-3', 'nonsense'] as $attempt) {
            $probe = $this->probe();
            $_GET  = ['id' => (string) $this->tokenId, 'page' => $attempt];
            \Pramnos\Http\Request::resetInstance();

            $probe->view();

            $this->assertSame(
                1,
                (int) ($probe->assigned['pagination']['page'] ?? 0),
                'a page of ' . $attempt . ' was passed through'
            );
            $this->assertCount(2, (array) ($probe->assigned['actions'] ?? []));
        }
    }

    /** The page count is derived from the total and the limit, so the view can draw a pager. */
    public function testThePageCountIsDerived(): void
    {
        // Arrange
        $this->seedActions(5);
        $probe = $this->probe();
        $_GET  = ['id' => (string) $this->tokenId, 'limit' => '2'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertSame(3, (int) ($probe->assigned['pagination']['pages'] ?? 0));
    }

    /** A second page returns the rest, which is what makes the pager mean anything. */
    public function testASecondPageReturnsTheRest(): void
    {
        // Arrange
        $this->seedActions(5);
        $probe = $this->probe();
        $_GET  = ['id' => (string) $this->tokenId, 'limit' => '4', 'page' => '2'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertCount(1, (array) ($probe->assigned['actions'] ?? []));
        $this->assertSame(2, (int) ($probe->assigned['pagination']['page'] ?? 0));
    }

    /** A token with no calls yet renders, with an empty list and a total of nought. */
    public function testATokenWithNoCallsStillRenders(): void
    {
        // Arrange
        $probe = $this->probe();
        $_GET['id'] = (string) $this->tokenId;
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->view();

        // Assert
        $this->assertSame([], $probe->redirects);
        $this->assertSame([], (array) ($probe->assigned['actions'] ?? []));
        $this->assertSame(0, (int) ($probe->assigned['pagination']['total'] ?? 0));
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the view recorded and the usertype floor granted.
     *
     * The floor is `requireMinUserType()`, whose own refusals are covered by the unit tests
     * beside this class; granting it here leaves the method's own decisions, which are what had
     * never run.
     */
    private function probe(): object
    {
        return new class ($this->db) extends TokensController {
            /** @var array<string, mixed> what the view was given */
            public array $assigned = [];

            public array $errors = [];

            public array $redirects = [];

            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            protected function requireMinUserType(int $minimum): bool
            {
                return false;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $controller = $this;

                $view = new class ($controller) {
                    public function __construct(private object $controller)
                    {
                    }

                    public function __set(string $key, mixed $value): void
                    {
                        $this->controller->assigned[$key] = $value;
                    }

                    public function display($view = '')
                    {
                        return 'recorded ' . $view;
                    }
                };

                return $view;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }
        };
    }

    /** One active token for the fixture account. */
    private function seedToken(): int
    {
        $token = 'tokenview-' . bin2hex(random_bytes(8));

        /*
         * `deviceinfo`, `scope`, `notes` and the rest are here because the shipped table declares
         * them NOT NULL with no default.
         *
         * This fixture used to omit them and pass, which is the part worth the comment: it was
         * passing against a `usertokens` left behind by whichever test ran first, not against the
         * shape the migration ships. `addToken()` — the framework's own writer — supplies all of
         * them, so an insert that does not is a fixture the database would reject in production.
         */
        $this->db->queryBuilder()->table('#PREFIX#usertokens')->insert([
            'userid'        => $this->uid,
            'tokentype'     => 'auth',
            'token'         => $token,
            'token_lookup'  => \Pramnos\User\Token::lookup($token),
            'applicationid' => 0,
            'status'        => 1,
            'created'       => time(),
            'lastused'      => time(),
            'notes'         => 'seeded by a test',
            'actions'       => 0,
            'removedate'    => 0,
            'deviceinfo'    => '',
            'scope'         => '',
        ]);

        return (int) $this->db->getInsertId();
    }

    /** $count recorded API calls for the fixture token, each against its own URL row. */
    private function seedActions(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $path = '/api/probe/' . $index;

            $this->db->queryBuilder()->table('#PREFIX#urls')->insert([
                'url'  => $path,
                'hash' => crc32($path),
            ]);
            $urlId = (int) $this->db->getInsertId();

            $this->db->queryBuilder()->table('#PREFIX#tokenactions')->insert([
                'tokenid'       => $this->tokenId,
                'urlid'         => $urlId,
                'method'        => 'GET',
                'params'        => '{}',
                'servertime'    => time() - $index,
                'return_status' => 200,
            ]);
        }
    }
}
