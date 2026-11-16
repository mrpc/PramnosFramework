<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\MetricDefinitions as Screen;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Metrics\CreateMetricDeclarationsTable;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\NoCodeMetrics;
use Pramnos\Tests\Integration\Audience\AdminScreen;

/**
 * `/admin/MetricDefinitions` (BI phase 7i) on MySQL: the list, the form — new, checked with its
 * planner cost shown, confirmed and saved — turning a declaration off, deleting it, and every
 * theme's view rendered with what the controller gave it.
 */
#[CoversClass(Screen::class)]
#[CoversClass(NoCodeMetrics::class)]
class MetricDefinitionsControllerTest extends TestCase
{
    use AdminScreen;

    private const ORDERS = 'nocode_test_orders';

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricDeclarationsTable::class], $db);
        $db->queryBuilder()->table('pramnos.metric_declarations')->whereLike('name', 'test.%')->delete();
        $schema = $db->schema();
        $schema->dropTableIfExists(self::ORDERS);
        $schema->createTable(self::ORDERS, function ($table) {
            $table->increments('id');
            $table->integer('placed_at');
            $table->decimal('amount', 10, 2);
            $table->string('status', 20);
        });
        Application::getInstance()->applicationInfo['metrics'] = ['no_code' => ['tables' => [
            self::ORDERS => ['time' => ['placed_at'], 'unix' => true, 'columns' => ['amount', 'status']],
        ]]];
        Metrics::reset();
        $this->signIn(98, 2);
    }

    protected function tearDown(): void
    {
        $db = Factory::getDatabase();
        $db->queryBuilder()->table('pramnos.metric_declarations')->whereLike('name', 'test.%')->delete();
        $db->schema()->dropTableIfExists(self::ORDERS);
        unset(Application::getInstance()->applicationInfo['metrics']);
        Metrics::reset();
        $this->clearRequest();
        parent::tearDown();
    }

    /** Every theme's rendering of one view, with these variables. @return array<string, string> */
    private function everyTheme(string $file, array $vars): array
    {
        $html = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $html[$theme] = $this->render($theme, $file, $vars);
        }

        return $html;
    }

    /** The list, the two-step save (checked, then confirmed), turning it off, and deleting it. */
    public function testTheScreen(): void
    {
        // Arrange
        $screen = $this->screen(Screen::class);

        // Act — the empty list, and the new-metric form
        $this->request('GET');
        $screen->display();
        $empty = (array) $screen->view;
        $this->request('GET');
        $screen->edit();
        $new = (array) $screen->view;

        // Act — checked without confirm: refused (bad column), then valid and shown its cost
        $this->request('POST', null, [], ['name' => 'test.bad', 'label' => 'Bad', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'sum', 'value_column' => 'id', 'confirm' => '0']);
        $screen->save();
        $refused = $screen->flash;
        $this->request('POST', null, [], ['name' => 'test.paid', 'label' => 'Paid orders', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'sum', 'value_column' => 'amount', 'where' => 'status=paid', 'dimensions' => 'bystatus=status', 'confirm' => '0']);
        $screen->save();
        $checked = (array) $screen->view;

        // Act — confirmed: actually saved
        $this->request('POST', null, [], ['name' => 'test.paid', 'label' => 'Paid orders', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'sum', 'value_column' => 'amount', 'where' => 'status=paid', 'dimensions' => 'bystatus=status', 'confirm' => '1']);
        $screen->save();
        $savedRedirect = $screen->redirects;
        $row = NoCodeMetrics::all()[0];

        // Act — edit the saved one, turn it off, then delete it
        $this->request('GET', null, ['id' => (string) $row['declaration_id']]);
        $screen->edit();
        $edited = (array) $screen->view;
        $this->request('POST', null, [], ['id' => (string) $row['declaration_id'], 'on' => '0']);
        $screen->active();
        $this->request('POST', null, [], ['id' => (string) $row['declaration_id']]);
        $screen->delete();
        $afterDelete = NoCodeMetrics::all();

        // Act — every theme renders both templates with real variables
        $listHtml = $this->everyTheme('metricdefinitions/list.tpl.php', ['rows' => [$row], 'csrfField' => '', 'urls' => ['list' => '#', 'edit' => '#', 'active' => '#', 'delete' => '#']]);
        $editHtml = $this->everyTheme('metricdefinitions/edit.tpl.php', $checked + ['urls' => ['list' => '#', 'save' => '#']]);

        // Assert
        $this->assertSame([], $empty['rows'], 'nothing defined yet');
        $this->assertSame(0, $new['id']);
        $this->assertNotEmpty($new['tables'], 'the allowed table is offered');
        $this->assertSame(['error', 'value_column: not one of this table\'s allowed columns.'], $refused[0] ?? null);
        $this->assertNotNull($checked['cost'], 'checked without confirm shows the planner\'s cost, not yet saved');
        $this->assertSame(0, (int) $checked['row']['declaration_id'], 'not written by the check alone');
        $this->assertStringContainsString('MetricDefinitions', $savedRedirect[0] ?? '', 'confirmed: redirected back to the list');
        $this->assertSame('test.paid', $row['name']);
        $this->assertSame('status=paid', $edited['where']);
        $this->assertSame([], $afterDelete, 'removed for good');
        foreach ($listHtml as $theme => $html) {
            $this->assertStringContainsString('test.paid', $html, $theme);
        }
        foreach ($editHtml as $theme => $html) {
            $this->assertStringContainsString((string) $checked['cost']['rows'], $html, $theme);
        }
    }

    /** Asking for a declaration that does not exist is refused, sent back to the list. */
    public function testEditingAMissingDeclarationIsRefused(): void
    {
        $screen = $this->screen(Screen::class);

        $this->request('GET', null, ['id' => '999999']);
        $screen->edit();

        $this->assertSame(['error', 'There is no such declaration.'], $screen->flash[0] ?? null);
        $this->assertNotEmpty($screen->redirects);
    }

    /** Below the admin floor, every action refuses — nothing of this screen is reached. */
    public function testBelowTheFloorEveryActionIsRefused(): void
    {
        // Arrange
        $this->signIn(1, 2);
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET');
        $display = $screen->display();
        $this->request('GET');
        $edit = $screen->edit();
        $this->request('POST');
        $save = $screen->save();
        $this->request('POST');
        $screen->active();
        $this->request('POST');
        $screen->delete();

        // Assert
        $this->assertNull($display);
        $this->assertNull($edit);
        $this->assertNull($save);
        $this->assertNull($screen->view, 'no view was ever filled for any of the five actions');
    }
}
