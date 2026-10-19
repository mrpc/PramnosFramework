<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\DashboardController;
use Pramnos\Application\Settings;
use Pramnos\Dashboard\Layouts;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Core\CreateUserDashboardsTable;

/**
 * The dashboard with its redirect and view caught, for the layout actions.
 */
class LayoutDashboardController extends DashboardController
{
    /** Where the last action sent the browser. */
    public ?string $redirectedTo = null;

    /** The view the last display filled. */
    public ?\stdClass $view = null;

    /** Catch the redirect instead of ending the request. */
    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        $this->redirectedTo = (string) $url;
    }

    /** Let anybody signed in through, so the test needs no usertype ladder. */
    protected function requireMinUserType(int $minType): bool
    {
        return false;
    }

    /** A plain object instead of a theme view: the test reads what display put on it. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $this->view = new class extends \stdClass {
            /** The template asked for. */
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
 * Each person's dashboard, kept per area — on MySQL here, on PostgreSQL in the sibling.
 *
 * What holds: somebody with no layout gets the default; a saved one comes back cleaned, in its
 * order and widths; "back to the default" forgets it; an area's layout is its own; the controller
 * saves what a page posts and answers what it kept, resets to the default, and draws the person's
 * widgets with what they may add; and the framework's widgets draw from the real figures.
 */
#[CoversClass(Layouts::class)]
#[CoversClass(DashboardController::class)]
#[CoversClass(CreateUserDashboardsTable::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\QueueStatus::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\EmailActivity::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\AiSpend::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\RecentErrors::class)]
class DashboardLayoutsMySQLTest extends TestCase
{
    protected const ME = 990101;

    protected Database $db;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        \Pramnos\Framework\Testing\Schema::ensure([CreateUserDashboardsTable::class], $this->db);
        $this->db->queryBuilder()->table('#PREFIX#user_dashboards')->where('userid', self::ME)->delete();
        WidgetRegistry::reset();
        Figures::forget();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#user_dashboards')->where('userid', self::ME)->delete();
        \Pramnos\Http\RequestIdentity::reset();
        Figures::forget();
        $_POST = [];
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

    /** An administrator of the dashboard's level. */
    private static function me(): \Pramnos\User\User
    {
        $me = new \Pramnos\User\User();
        $me->userid   = self::ME;
        $me->usertype = 90;

        return $me;
    }

    /**
     * No layout is the default; a saved one comes back in its order and widths, cleaned of an
     * unknown widget; an area's is its own; null forgets it.
     */
    public function testALayoutIsKeptPerPersonAndArea(): void
    {
        // Arrange
        $layouts = new Layouts($this->db);
        $me      = self::me();

        // Act
        $before = $layouts->for($me);
        $kept   = $layouts->save($me, [['id' => 'health', 'size' => 'half'], ['id' => 'nope'], ['id' => 'database', 'size' => 'full']]);
        $after  = $layouts->for($me);
        $other  = $layouts->for($me, 'partners');
        $layouts->save($me, [['id' => 'database', 'size' => 'half']], 'partners');
        $again  = $layouts->save($me, [['id' => 'users.active', 'size' => 'half']]);
        $reset  = $layouts->save($me, null);

        // Assert
        $this->assertSame(Layouts::defaults($me), $before);
        $this->assertSame([['id' => 'health', 'size' => 'm', 'settings' => []], ['id' => 'database', 'size' => 'full', 'settings' => []]], $kept);
        $this->assertSame($kept, $after, 'read back as kept');
        $this->assertSame(Layouts::defaults($me), $other, 'another area starts from the default');
        $this->assertSame([['id' => 'users.active', 'size' => 'm', 'settings' => []]], $again, 'a second save replaces the first');
        $this->assertSame(Layouts::defaults($me), $reset);
        $this->assertSame(Layouts::defaults($me), $layouts->for($me), 'forgotten');
        $this->assertSame([['id' => 'database', 'size' => 'm', 'settings' => []]], $layouts->for($me, 'partners'), 'the other area kept its own');
    }

    /**
     * Every layout saved for an area, as `dashboard:precompute` reads it (FW-167): this area's,
     * with each card's settings, and not another area's.
     */
    public function testTheSavedLayoutsOfAnArea(): void
    {
        // Arrange
        $layouts = new Layouts($this->db);
        $layouts->save(self::me(), [['id' => 'database', 'size' => 'full']], 'pf-precompute');
        $layouts->save(self::me(), [['id' => 'health']], 'pf-elsewhere');

        // Act
        $saved = $layouts->saved('pf-precompute');

        // Assert
        $this->assertSame([[['id' => 'database', 'size' => 'full', 'settings' => []]]], $saved);
        $this->assertSame([], $layouts->saved('pf-nobody'));
    }

    /**
     * The controller: a posted layout is kept and answered cleaned; display draws this person's
     * widgets in order, offers the rest, and still hands a view of the older shape its figures;
     * reset forgets the layout and goes back to the dashboard; nobody signed in is refused.
     */
    public function testTheControllerSavesDrawsAndResets(): void
    {
        // Arrange
        $controller = new LayoutDashboardController(null);
        $refused = $controller->savelayout();
        \Pramnos\Http\RequestIdentity::seal(self::me(), 'test');
        $_POST = ['layout' => json_encode([['id' => 'database', 'size' => 'full'], ['id' => 'nope']])];

        // Act
        $saved = json_decode((string) $controller->savelayout()->getBody(), true);
        $controller->display();
        $view = $controller->view;
        $controller->resetlayout();

        // Assert
        $this->assertSame(401, $refused->getStatusCode());
        $this->assertSame(['layout' => [['id' => 'database', 'size' => 'full', 'settings' => []]]], $saved);
        $this->assertSame('admin_dashboard', $view->shown);
        $this->assertSame(['database'], array_column($view->widgets, 'id'));
        $this->assertSame('full', $view->widgets[0]['size']);
        $this->assertSame([], $view->widgets[0]['options'], 'the framework\'s cards have nothing to set');
        $this->assertStringContainsString('pf-stat-tile', $view->widgets[0]['html'], 'drawn from the real figures');
        $this->assertSame(['users.active', 'api.performance', 'health'], array_keys($view->addable));
        $this->assertNotSame('', $view->catalogue['health']['description'], 'each offered with what it shows');
        $this->assertStringEndsWith('LayoutDashboardController/card', $view->cardUrl);
        // The controller's own address — this subclass's name — as a wrapper's would be
        $this->assertStringEndsWith('LayoutDashboardController/savelayout', $view->saveUrl);
        $this->assertInstanceOf(\Pramnos\Dashboard\Widgets\LazyFigures::class, $view->healthResults, 'not worked out until read');
        $this->assertIsArray($view->dbStats->toArray(), 'the figures a view of the older shape reads');
        $this->assertSame(['title' => 'Database', 'placed' => true], array_intersect_key($view->catalogue['database'], ['title' => 1, 'placed' => 1]), 'a card on the board, in the catalogue');
        $this->assertFalse($view->catalogue['health']['placed']);
        $this->assertContains('The dashboard is back to the default.', $_SESSION['_messages'] ?? [], 'reset says so');
        $this->assertStringEndsWith('LayoutDashboardController', (string) $controller->redirectedTo);
        $this->assertSame(Layouts::defaults(self::me()), (new Layouts($this->db))->for(self::me()));
    }

    /**
     * One card alone: drawn with this person's saved width and settings, its inside drawn with
     * them; one they have not placed, with the widget's own; one they may not have, or nobody
     * signed in, is 404.
     */
    public function testOneCardIsDrawnAlone(): void
    {
        // Arrange
        WidgetRegistry::register(\Pramnos\Tests\Unit\Dashboard\SalesWidget::class);
        $controller = new LayoutDashboardController(null);
        $anonymous  = $controller->card('sales');
        \Pramnos\Http\RequestIdentity::seal(self::me(), 'test');
        (new Layouts($this->db))->save(self::me(), [['id' => 'sales', 'size' => 'full', 'settings' => ['period' => '90']]]);

        // Act
        $placed    = $controller->card('sales');
        $saved     = $controller->view->widget;
        $_GET      = ['id' => 'health'];
        $notPlaced = $controller->card();
        $health    = $controller->view->widget;
        $_GET      = [];
        $unknown   = $controller->card('nope');

        // Assert
        $this->assertSame(404, $anonymous->getStatusCode());
        $this->assertSame([200, 'no-store'], [$placed->getStatusCode(), $placed->getHeaderLine('Cache-Control')]);
        $this->assertSame('card', $controller->view->shown);
        $this->assertSame(['full', ['period' => '90', 'market' => 'all', 'chart' => 'bar']], [$saved['size'], $saved['settings']]);
        $this->assertSame(['input' => 'date', 'label' => 'From', 'values' => [], 'when' => ['period' => '90']], $saved['fields']['from'], 'a field, as the view draws it');
        $this->assertSame('Period', $saved['fields']['period']['label'], 'no label: the name');
        $this->assertSame('<p>sales over 90</p>', $saved['html'], 'drawn with the settings');
        $this->assertSame(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'], $saved['options']['period']);
        $this->assertSame(['all' => 'all', 'gr' => 'gr', 'cy' => 'cy'], $saved['options']['market'], 'a plain list, valued by itself');
        $this->assertNull($saved['options']['title'], 'a free value');
        $this->assertSame([200, 'full'], [$notPlaced->getStatusCode(), $health['size']], 'the widget\'s own width');
        $this->assertSame(404, $unknown->getStatusCode());
    }

    /** A widget that throws is a short note on its card, not a dashboard that fails to draw. */
    public function testAFailingWidgetIsANote(): void
    {
        // Arrange
        WidgetRegistry::register(new class extends \Pramnos\Dashboard\Widget {
            /** Its id. */
            public function id(): string { return 'fails'; }
            /** Its heading. */
            public function title(): string { return 'Fails'; }
            /** Its inside: it throws, naming the controller it was handed. */
            public function render(): string { throw new \RuntimeException('no figures from ' . get_class($this->controller)); }
        });
        \Pramnos\Http\RequestIdentity::seal(self::me(), 'test');
        (new Layouts($this->db))->save(self::me(), [['id' => 'fails'], ['id' => 'users.active']]);
        $controller = new LayoutDashboardController(null);

        // Act
        $controller->display();

        // Assert
        $this->assertStringContainsString('could not be drawn', $controller->view->widgets[0]['html']);
        // The widget was handed the controller drawing it: the log line names it
        $this->assertStringContainsString('LayoutDashboardController', (string) file_get_contents(LOG_PATH . DS . 'logs' . DS . 'dashboard.log'));
        $this->assertStringContainsString('pf-stat-tile', $controller->view->widgets[1]['html'], 'the next card still drawn');
    }

    /** The framework's cards draw from the real figures: the API's and the active users'. */
    public function testTheFrameworksCardsDraw(): void
    {
        // Act
        $users = (new \Pramnos\Dashboard\Widgets\ActiveUsers())->render();
        $api   = (new \Pramnos\Dashboard\Widgets\ApiPerformance())->render();

        // Assert
        $this->assertStringContainsString('pf-stat-grid', $users);
        $this->assertStringContainsString('Last 24 hours', $users);
        $this->assertStringContainsString('Requests', $api);
        $this->assertStringContainsString('Errors', $api);
    }

    /**
     * The queue, email, AI-spend and errors cards draw from the real tables: a failed task shows as
     * a failure, critical; a tracked message opened shows a rate; a priced call shows its cost.
     * Each reads the same figures the analyst answers with.
     */
    public function testTheScreensCardsDraw(): void
    {
        // Arrange — one of each, made now, marked so they can be removed
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Queue\CreateQueueitemsTable::class,
            \Pramnos\Framework\Migrations\Core\CreatePramnosSchema::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Llm\CreateLlmCallsTable::class,
            \Pramnos\Framework\Migrations\Llm\AddTokensToLlmCalls::class,
        ], $this->db);
        $now    = gmdate('Y-m-d H:i:s', time() - 60);
        $marker = 'dash-' . bin2hex(random_bytes(4));
        $qb     = fn (string $table) => $this->db->queryBuilder()->table($table);
        $qb('#PREFIX#queueitems')->insert(['type' => $marker, 'payload' => '{}', 'status' => 'failed', 'error' => 'boom', 'createdat' => $now, 'updatedat' => $now]);
        $qb('pramnos.emailtracking')->insert(['tracking_id' => $marker, 'list' => 'news', 'subject' => 's', 'sent_at' => time() - 60, 'opens' => 1, 'proxy_opens' => 0, 'clicks' => 0]);
        // The send history: one sent, one failed — what every installation has, tracked or not
        foreach ([1, 0] as $status) {
            $qb('#PREFIX#mails')->insert(['status' => $status, 'frommail' => 'a@b.c', 'fromname' => '', 'tomail' => 'x@y.z', 'toname' => '', 'subject' => $marker,
                'content' => '', 'date' => time() - 60, 'module' => '', 'moduleinfo' => '', 'extrainfo' => '', 'path' => '', 'hash' => md5($marker . $status)]);
        }
        $qb('#PREFIX#llm_calls')->insert(['purpose' => $marker, 'provider' => 'openai', 'model' => 'm', 'tokens' => 100, 'cost' => 0.5, 'currency' => 'USD', 'created_at' => $now]);
        // An error in a log of its own, written as the framework writes one, with markup in it —
        // often enough to be the commonest, whatever else the installation's logs hold today
        $log = \Pramnos\Logs\LogManager::getLogFilePath($marker, 'log');
        @mkdir(dirname($log), 0777, true);
        $line = json_encode(['timestamp' => date('d/m/Y H:i:s', time() - 60), 'message' => $marker . ' <b>broke</b>', 'level' => 'error']) . "\n";
        file_put_contents($log, str_repeat($line, 5000));

        try {
            // Act
            $queue  = (new \Pramnos\Dashboard\Widgets\QueueStatus())->render();
            $email  = (new \Pramnos\Dashboard\Widgets\EmailActivity())->render();
            $spend  = (new \Pramnos\Dashboard\Widgets\AiSpend())->render();
            $errors = (new \Pramnos\Dashboard\Widgets\RecentErrors())->render();

            // Assert
            $this->assertMatchesRegularExpression('#data-state="critical"><span[^>]*>Failed</span>#', $queue, 'a failure in the last day is critical');
            $this->assertStringContainsString('Waiting', $queue);
            $this->assertMatchesRegularExpression('#>Sent</span><span[^>]*>[1-9][\d,]*</span>#', $email, 'from the send history');
            $this->assertMatchesRegularExpression('#data-state="(warning|critical)"><span[^>]*>Failed</span>#', $email, 'a failure is flagged');
            $this->assertStringContainsString('>Queued<', $email);
            $this->assertMatchesRegularExpression('#>Opened</span><span[^>]*>[\d.]+%</span>#', $email, 'a rate, since tracked mail was sent this week');
            $this->assertMatchesRegularExpression('#>Cost</span><span[^>]*>\$[\d,]+\.\d{2}</span>#', $spend);
            $this->assertMatchesRegularExpression('#data-state="warning"><span[^>]*>Errors</span>#', $errors, 'an error in the last day');
            $this->assertStringContainsString($marker . ' &lt;b&gt;broke&lt;/b&gt;</span><span class="pf-dashboard-status">5000×', $errors, 'listed with its count, its markup escaped');
        } finally {
            @unlink($log);
            $qb('#PREFIX#queueitems')->where('type', $marker)->delete();
            $qb('pramnos.emailtracking')->where('tracking_id', $marker)->delete();
            $qb('#PREFIX#mails')->where('subject', $marker)->delete();
            $qb('#PREFIX#llm_calls')->where('purpose', $marker)->delete();
        }
    }

    /**
     * Where the feature's table is not there — never installed — its card draws nothing rather than
     * failing: the analyst's tool says the table is absent, and an empty card is "nothing today".
     */
    public function testACardWithoutItsTableIsEmpty(): void
    {
        // Arrange — a connection with no such tables: a prefix nothing was made under
        $prefix = $this->db->prefix;
        $this->db->prefix = 'nothere_' . bin2hex(random_bytes(3)) . '_';

        try {
            // Act
            $queue = (new \Pramnos\Dashboard\Widgets\QueueStatus())->render();
            $spend = (new \Pramnos\Dashboard\Widgets\AiSpend())->render();
            $email = (new \Pramnos\Dashboard\Widgets\EmailActivity())->render();
        } finally {
            $this->db->prefix = $prefix;
        }

        // Assert
        $this->assertSame(['', ''], [$queue, $spend]);
        if ($this->db->type === 'mysql') {
            // On MySQL `pramnos.emailtracking` is a prefixed table too; on PostgreSQL it is a schema the prefix does not reach.
            $this->assertSame('', $email);
        }
    }

    /**
     * Mail sent with no tracking in the week gets no "Opened" tile at all — not "0%", which would
     * say nobody read it when nobody was counting.
     */
    public function testUntrackedMailShowsNoOpenRate(): void
    {
        // Arrange — tracked mail only from before the week
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Core\CreatePramnosSchema::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
        ], $this->db);
        $tracking = fn () => $this->db->queryBuilder()->table('pramnos.emailtracking');
        $recent   = $tracking()->where('sent_at', '>=', time() - 8 * 86400)->count();
        if ($recent > 0) {
            $this->markTestSkipped('This database has tracked mail from this week.');
        }

        // Act
        $email = (new \Pramnos\Dashboard\Widgets\EmailActivity())->render();

        // Assert
        $this->assertStringContainsString('>Sent<', $email);
        $this->assertStringNotContainsString('Opened', $email);
    }

    /** Without the table — before `migrate` — a person still gets the default dashboard. */
    public function testWithoutTheTableTheDefaultStillDraws(): void
    {
        // Arrange — the table gone, as before the migration ran
        $layouts = new Layouts($this->db);
        $this->db->schema()->dropTableIfExists('user_dashboards');

        try {
            // Act
            $layout = $layouts->for(self::me());

            // Assert
            $this->assertSame(Layouts::defaults(self::me()), $layout);
        } finally {
            \Pramnos\Framework\Testing\Schema::ensure([CreateUserDashboardsTable::class], $this->db);
        }
    }
}
