<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\ClientErrorsController;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Core\CreateJavascripterrorsTable;
use Pramnos\Logs\ClientErrors;

/**
 * A controller whose report is the form, and whose origin and rate checks can be played.
 */
class FormClientErrorsController extends ClientErrorsController
{
    /** What the next request posted, as a form. */
    public array $posted = [];

    /** The report: the posted form, as when no JSON body came. */
    protected function report(): array
    {
        return $this->posted;
    }
}

/**
 * The log screens with their redirect and view caught, for the browser-errors actions.
 */
class BrowserErrorsLogController extends \Pramnos\Application\Controllers\LogController
{
    protected ?string $logsRoute = 'Logs';

    /** Where the last action sent the browser. */
    public ?string $redirectedTo = null;

    /** The view the last action filled, a plain object standing in for the theme's. */
    public ?\stdClass $view = null;

    /** Catch the redirect instead of ending the request. */
    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        $this->redirectedTo = (string) $url;
    }

    /** A plain object instead of a theme view: the test reads what the action put on it. */
    protected function logsView(): mixed
    {
        $this->view = new class extends \stdClass {
            /** The template the action asked for. */
            public string $shown = '';

            /** Record the template instead of rendering it. */
            public function display(string $tpl = ''): string
            {
                $this->shown = $tpl;

                return '';
            }
        };

        return $this->view;
    }
}

/**
 * Browser errors, stored one row per distinct error — on MySQL here, on PostgreSQL in the sibling.
 *
 * What the screen and the reporters rely on: a repeat of an error counts on its row rather than
 * adding one; the older one-line shape is stored as applications stored it, under the hash they
 * computed, so their rows keep counting; a table such an application already has is extended, not
 * replaced, and keeps its rows; the newest first, deleted one at a time or all at once; and the
 * address that takes reports refuses another origin, another method and an oversized body.
 */
#[CoversClass(ClientErrors::class)]
#[CoversClass(ClientErrorsController::class)]
#[CoversClass(CreateJavascripterrorsTable::class)]
class ClientErrorsMySQLTest extends TestCase
{
    protected Database $db;

    /** @var array<string, mixed> The request as the suite had it */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        \Pramnos\Framework\Testing\Schema::ensure([CreateJavascripterrorsTable::class], $this->db);
        (new ClientErrors($this->db))->clear();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        \Pramnos\Http\RequestIdentity::reset();
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }

        return $db;
    }

    /** Every row, by hash. */
    private function rows(): array
    {
        $rows = [];
        foreach ((new ClientErrors($this->db))->list(1, 200)['items'] as $row) {
            $rows[$row['idhash']] = $row;
        }

        return $rows;
    }

    /**
     * The same error twice — from another browser, on another page — is one row seen twice, with
     * the latest page and agent; the one-line `error` says where and what; the query string of the
     * page and the script are gone.
     */
    public function testARepeatCountsOnItsRow(): void
    {
        // Arrange
        $errors = new ClientErrors($this->db);
        $report = ['message' => 'x is undefined', 'source' => '/assets/js/app.js?v=12', 'line' => 40, 'column' => 7, 'stack' => "at f (app.js:40:7)", 'page' => 'https://app.test/orders?gclid=abc'];

        // Act
        $first  = $errors->record($report, 'Firefox', 5);
        $second = $errors->record(['page' => 'https://app.test/invoices'] + $report, 'Chrome');
        $rows   = $this->rows();

        // Assert
        $this->assertSame($first, $second, 'the same error, whatever the browser and the page');
        $this->assertCount(1, $rows);
        $row = $rows[$first];
        $this->assertSame(2, (int) $row['seentimes']);
        $this->assertSame('Chrome', $row['agent'], 'the latest reporter');
        $this->assertSame('https://app.test/invoices', $row['page']);
        $this->assertSame('/assets/js/app.js', $row['source'], 'no query string');
        // The one line the older readers show: where, what, and the page it was first seen on
        $this->assertSame("/assets/js/app.js:40\n\nx is undefined: https://app.test/orders", $row['error']);
        $this->assertSame('error', $row['kind']);
        $this->assertSame(7, (int) $row['col']);
        $this->assertSame(5, (int) $row['userid'], 'the signed-in reporter is kept when the next is anonymous');
        $this->assertGreaterThanOrEqual((int) $row['firstseen'], (int) $row['lastseen']);
    }

    /**
     * The older shape — one line and the agent — is stored as applications stored it, under
     * `md5(error . agent)`, so a row they already had keeps counting.
     */
    public function testTheOlderShapeKeepsItsHash(): void
    {
        // Arrange
        $errors = new ClientErrors($this->db);
        $line   = "app.js:3\n\nboom: https://app.test/";

        // Act
        $hash = $errors->record(['error' => $line], 'Safari');
        $errors->record(['error' => $line], 'Safari');

        // Assert
        $this->assertSame(md5($line . 'Safari'), $hash);
        $row = $this->rows()[$hash];
        $this->assertSame($line, $row['error']);
        $this->assertSame(2, (int) $row['seentimes']);
        $this->assertNull($row['message'], 'nothing invented for the older shape');
    }

    /** A report with nothing in it is not stored; an unknown kind is an error; long text is cut. */
    public function testNothingIsNotStoredAndTextIsBounded(): void
    {
        // Arrange
        $errors = new ClientErrors($this->db);

        // Act
        $empty = $errors->record(['message' => '   ']);
        $none  = $errors->record(['error' => '']);
        $hash  = $errors->record(['message' => str_repeat('m', 3000), 'kind' => 'nonsense', 'source' => str_repeat('s', 400), 'release' => str_repeat('r', 100)]);

        // Assert
        $this->assertNull($empty);
        $this->assertNull($none);
        $row = $this->rows()[$hash];
        $this->assertSame('error', $row['kind']);
        $this->assertSame(2000, mb_strlen((string) $row['message']));
        $this->assertSame(255, mb_strlen((string) $row['source']));
        $this->assertSame(255, mb_strlen((string) $row['error']));
        $this->assertSame(64, mb_strlen((string) $row['release']));
    }

    /** Newest first; one deleted by its hash, a missing one is not; then all of them. */
    public function testListDeleteAndClear(): void
    {
        // Arrange
        $errors = new ClientErrors($this->db);
        $old = $errors->record(['message' => 'old']);
        $this->db->queryBuilder()->table('#PREFIX#javascripterrors')->where('idhash', $old)->update(['lastseen' => 1000]);
        $new = $errors->record(['message' => 'new']);

        // Act
        $list    = $errors->list();
        $deleted = $errors->delete($old);
        $missing = $errors->delete('nope');
        $cleared = $errors->clear();

        // Assert
        $this->assertSame(2, $list['total']);
        $this->assertSame([$new, $old], array_column($list['items'], 'idhash'));
        $this->assertTrue($deleted);
        $this->assertFalse($missing);
        $this->assertSame(1, $cleared);
        $this->assertSame(0, $errors->list()['total']);
    }

    /**
     * A `javascripterrors` table an application already had — the six original columns, with rows —
     * gains the new columns and keeps its rows; rolled back, it loses only those columns. The
     * table this migration creates goes with its rollback.
     */
    public function testAnApplicationsTableIsExtendedNotReplaced(): void
    {
        // Arrange — the application's own table, as it created it
        $schema = $this->db->schema();
        $schema->dropTableIfExists('javascripterrors');
        $schema->createTable('javascripterrors', function ($table) {
            $table->string('idhash', 100);
            $table->unsignedInteger('firstseen');
            $table->unsignedInteger('lastseen');
            $table->integer('seentimes');
            $table->string('error', 255);
            $table->string('agent', 255);
            $table->unique(['idhash'], 'idx_app_jserrors_idhash');
        });
        $this->db->queryBuilder()->table('#PREFIX#javascripterrors')->insert([
            'idhash' => 'h1', 'firstseen' => 1, 'lastseen' => 2, 'seentimes' => 9, 'error' => 'kept', 'agent' => 'a',
        ]);
        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;
        $migration = new CreateJavascripterrorsTable($application);

        try {
            // Act
            $migration->up();
            $extended = $schema->hasColumn('javascripterrors', 'stack') && $schema->hasColumn('javascripterrors', 'userid');
            $kept     = (int) $this->db->queryBuilder()->table('#PREFIX#javascripterrors')->where('idhash', 'h1')->where('seentimes', 9)->count();
            $hash     = (new ClientErrors($this->db))->record(['message' => 'new on old'], 'x');
            $migration->down();

            // Assert
            $this->assertTrue($extended, 'the new columns added');
            $this->assertSame(1, $kept, 'the application\'s row kept');
            $this->assertNotNull($hash, 'and new reports stored in it');
            $this->assertTrue($schema->hasTable('javascripterrors'), 'rolled back, the application\'s table stays');
            $this->assertFalse($schema->hasColumn('javascripterrors', 'stack'), 'without what was added');
        } finally {
            // Back to the table this migration creates, which its rollback drops
            $schema->dropTableIfExists('javascripterrors');
            $migration->up();
            $migration->down();
            $this->assertFalse($schema->hasTable('javascripterrors'), 'its own table goes with the rollback');
            \Pramnos\Framework\Testing\Schema::ensure([CreateJavascripterrorsTable::class], $this->db);
        }
    }

    /**
     * The address stores a same-origin POST and answers 204; it refuses a GET (405), another
     * origin or none (403), and a body over the limit (413) — each without storing anything.
     */
    public function testTheAddressTakesOnlyItsOwnPagesReports(): void
    {
        // Arrange
        $controller = new FormClientErrorsController(null);
        $controller->posted = ['message' => 'from a page', 'page' => 'https://app.test/x'];
        $_SERVER['HTTP_HOST'] = 'app.test';
        $status = static function () use ($controller): int {
            return $controller->display()->getStatusCode();
        };

        // Act & Assert — each refusal, then the one that is taken
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame(405, $status());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame(403, $status(), 'no origin at all');
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
        $this->assertSame(403, $status(), 'another origin');
        $_SERVER['HTTP_ORIGIN'] = 'https://app.test';
        $_SERVER['CONTENT_LENGTH'] = (string) (ClientErrorsController::MAX_BODY + 1);
        $this->assertSame(413, $status());
        $this->assertSame(0, (new ClientErrors($this->db))->list()['total'], 'nothing stored by a refusal');

        $_SERVER['CONTENT_LENGTH'] = '100';
        unset($_SERVER['HTTP_ORIGIN']);
        $_SERVER['HTTP_REFERER'] = 'https://app.test/orders';
        $this->assertSame(204, $status(), 'a Referer from this site will do');
        $this->assertSame(1, (new ClientErrors($this->db))->list()['total']);
    }

    /**
     * The log screens list the errors with their paging, forget one by its hash and forget all;
     * a toolbar link leads there. Each write goes back to the list.
     */
    public function testTheLogScreensListAndForgetThem(): void
    {
        // Arrange
        $errors = new ClientErrors($this->db);
        $one = $errors->record(['message' => 'one']);
        $errors->record(['message' => 'two']);
        $controller = new BrowserErrorsLogController(null);
        $_GET = [];

        // Act
        $controller->jserrors();
        $listed = $controller->view;
        $controller->deletejserror($one);
        $afterDelete = $errors->list()['total'];
        $controller->clearjserrors();

        // Assert
        $this->assertSame('jserrors', $listed->shown);
        $this->assertCount(2, $listed->jsErrors);
        $this->assertSame(2, $listed->total);
        $this->assertSame(1, $listed->pages);
        $this->assertNull($listed->notice);
        $this->assertContains('Browser Errors', array_column($listed->toolbar, 'label'));
        $this->assertSame(1, $afterDelete);
        $this->assertSame(0, $errors->list()['total']);
        $this->assertStringEndsWith('Logs/jserrors', (string) $controller->redirectedTo);
    }

    /** A same-origin POST from this site's page, read by the controller as it stands. */
    private function sameOriginPost(string $ip): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST']      = 'app.test';
        $_SERVER['HTTP_ORIGIN']    = 'https://app.test';
        $_SERVER['CONTENT_LENGTH'] = '80';
        $_SERVER['REMOTE_ADDR']    = $ip;
    }

    /**
     * Without a JSON body the report is the posted form — the older `error`/`agent` shape among it,
     * and nothing that is not a report's field — and the signed-in reporter is kept.
     */
    public function testTheFormIsReadWhenThereIsNoJson(): void
    {
        // Arrange
        $this->sameOriginPost('10.9.' . random_int(0, 255) . '.' . random_int(0, 255));
        $_POST = ['error' => 'legacy line', 'agent' => 'Old Browser', 'password' => 'never stored'];
        $me = new \Pramnos\User\User();
        $me->userid = 990301;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');

        try {
            // Act
            $status = (new ClientErrorsController(null))->display()->getStatusCode();

            // Assert
            $this->assertSame(204, $status);
            $row = $this->rows()[md5('legacy line' . 'Old Browser')];
            $this->assertSame('legacy line', $row['error']);
            $this->assertSame('Old Browser', $row['agent'], 'the agent the report carried');
        } finally {
            \Pramnos\Http\RequestIdentity::reset();
            $_POST = [];
        }
    }

    /** Thirty reports a minute from one address; the next is refused with 429 and not stored. */
    public function testAFloodFromOneAddressIsRefused(): void
    {
        // Arrange
        if (!function_exists('apcu_inc') || !apcu_enabled()) {
            $this->markTestSkipped('APCu counts the reports; it is not enabled here.');
        }
        $this->sameOriginPost('10.8.' . random_int(0, 255) . '.' . random_int(0, 255));
        $controller = new FormClientErrorsController(null);
        $statuses   = [];

        // Act
        for ($i = 0; $i <= ClientErrorsController::PER_MINUTE; $i++) {
            $controller->posted = ['message' => 'flood ' . $i];
            $statuses[] = $controller->display()->getStatusCode();
        }

        // Assert
        $this->assertSame(array_fill(0, ClientErrorsController::PER_MINUTE, 204), array_slice($statuses, 0, ClientErrorsController::PER_MINUTE));
        $this->assertSame(429, end($statuses));
        $this->assertSame(ClientErrorsController::PER_MINUTE, (new ClientErrors($this->db))->list()['total']);
    }

    /**
     * Without APCu the limit holds too, in the application's cache: thirty reports a minute from
     * one address, then 429 — production had no APCu, so one broken browser's reports were never
     * refused; a cache that fails lets the report through rather than failing it; and the session
     * is let go of first, so the reports of one session do not queue on its lock.
     */
    public function testWithoutApcuTheCacheHoldsTheLimit(): void
    {
        // Arrange — a bucket in memory, standing for Redis
        $this->sameOriginPost('10.9.' . random_int(0, 255) . '.' . random_int(0, 255));
        $bucket     = new \Pramnos\Http\TokenBucket(new \Pramnos\Tests\Support\InMemoryBucketCache());
        $controller = new class (null) extends FormClientErrorsController {
            public ?\Pramnos\Http\TokenBucket $given = null;

            public bool $broken = false;

            /** No APCu here: the application's cache decides. */
            protected function apcuCounts(): bool
            {
                return false;
            }

            /** The bucket given, or one that fails. */
            protected function bucket(): \Pramnos\Http\TokenBucket
            {
                if ($this->broken) {
                    throw new \RuntimeException('cache down');
                }

                return $this->given;
            }
        };
        $controller->given = $bucket;
        $statuses = [];

        // Act
        for ($i = 0; $i <= ClientErrorsController::PER_MINUTE; $i++) {
            $controller->posted = ['message' => 'no apcu ' . $i];
            $statuses[] = $controller->display()->getStatusCode();
        }
        $controller->broken = true;
        $controller->posted = ['message' => 'cache down'];
        $throughBroken      = $controller->display()->getStatusCode();

        // Assert
        $this->assertSame(204, $statuses[0]);
        $this->assertSame(429, end($statuses), 'the 31st of the minute');
        $this->assertSame(204, $throughBroken, 'a failing cache refuses nothing');
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'the session was let go of');
    }

    /** A store that fails — no table, no database — is logged, and the page still gets its 204. */
    public function testAFailingStoreIsNotTheReportersProblem(): void
    {
        // Arrange
        $this->sameOriginPost('10.7.' . random_int(0, 255) . '.' . random_int(0, 255));
        $controller = new class (null) extends FormClientErrorsController {
            /** A store over a database nothing connected. */
            protected function errors(): ClientErrors
            {
                $db = new Database();
                $db->type = 'mysql';
                $db->server = '127.0.0.1';
                $db->port = 1;

                return new ClientErrors($db);
            }
        };
        $controller->posted = ['message' => 'nowhere to go'];

        // Act
        $status = $controller->display()->getStatusCode();

        // Assert
        $this->assertSame(204, $status);
    }
}
