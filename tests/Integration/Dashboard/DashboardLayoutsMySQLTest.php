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
        $this->assertSame([['id' => 'health', 'size' => 'half'], ['id' => 'database', 'size' => 'full']], $kept);
        $this->assertSame($kept, $after, 'read back as kept');
        $this->assertSame(Layouts::defaults($me), $other, 'another area starts from the default');
        $this->assertSame([['id' => 'users.active', 'size' => 'half']], $again, 'a second save replaces the first');
        $this->assertSame(Layouts::defaults($me), $reset);
        $this->assertSame(Layouts::defaults($me), $layouts->for($me), 'forgotten');
        $this->assertSame([['id' => 'database', 'size' => 'half']], $layouts->for($me, 'partners'), 'the other area kept its own');
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
        $this->assertSame(['layout' => [['id' => 'database', 'size' => 'full']]], $saved);
        $this->assertSame('admin_dashboard', $view->shown);
        $this->assertSame(['database'], array_column($view->widgets, 'id'));
        $this->assertSame('full', $view->widgets[0]['size']);
        $this->assertStringContainsString('pf-stat-tile', $view->widgets[0]['html'], 'drawn from the real figures');
        $this->assertSame(['users.active', 'api.performance', 'health'], array_keys($view->addable));
        // The controller's own address — this subclass's name — as a wrapper's would be
        $this->assertStringEndsWith('LayoutDashboardController/savelayout', $view->saveUrl);
        $this->assertIsArray($view->dbStats, 'the figures a view of the older shape reads');
        $this->assertStringEndsWith('LayoutDashboardController', (string) $controller->redirectedTo);
        $this->assertSame(Layouts::defaults(self::me()), (new Layouts($this->db))->for(self::me()));
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
