<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\MetricReports;
use Pramnos\Application\Settings;
use Pramnos\Dashboard\Presets;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Stats\CreateMetricValuesTable;
use Pramnos\Framework\Migrations\Stats\CreateReportsTable;
use Pramnos\Metrics\Dimension;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Report;
use Pramnos\Metrics\Reports;
use Pramnos\Metrics\Window;

/**
 * Reports built from metrics against the real database — MySQL here, PostgreSQL in the sibling:
 * the numbers of a report, the saved and shared reports, mailing them, and `/admin/MetricReports`
 * with every theme's template.
 *
 * What holds: a cell is the row's metric with the row's and the column's filters in the column's
 * period; a breakdown row's total is the metric without it (a distinct counted, not added); a row
 * of a metric the reader may not see is left out and counted; a cell the metric cannot answer
 * says why; a saved report is seen by whom its visibility says; a mailed report goes once a
 * closed period; the screen does what each action says, or says why not.
 */
#[CoversClass(Report::class)]
#[CoversClass(Reports::class)]
#[CoversClass(MetricReports::class)]
#[CoversClass(CreateReportsTable::class)]
#[CoversClass(\Pramnos\Console\Commands\MetricsReportsSend::class)]
class ReportsMySQLTest extends TestCase
{
    use \Pramnos\Tests\Integration\Audience\AdminScreen;

    protected Database $db;

    private array $info;

    /** The orders behind test.sales, by month: [region, customer, value]. */
    private const SALES = [
        '2026-08-01' => [['north', 'new', 1]],
        '2026-09-01' => [['north', 'new', 3], ['north', 'returning', 2], ['south', 'new', 1]],
    ];

    /** The buyers behind test.buyers in September: the total is not the regions added up. */
    private const BUYERS = ['' => 4, 'north' => 3, 'south' => 2];

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        // The owner of a mailed report is loaded to read it as they would.
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class, CreateReportsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class, \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $this->clean();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        Metrics::reset();
        Metrics::dimension(Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South']));
        Metrics::register(
            Metric::flow('test.sales', 'Sales')->dimensions(['region', 'customer'])
                ->source(static function (Window $w, array $by): array {
                    $rows = [];
                    foreach (self::SALES[$w->key()] ?? [] as [$region, $customer, $value]) {
                        $rows[] = array_intersect_key(['region' => $region, 'customer' => $customer], array_flip($by)) + ['value' => $value];
                    }

                    return $rows;
                })
                ->rows(static fn (Window $w, array $f): array => [['order' => 1, 'region' => $f['region'] ?? '']]),
            Metric::flow('test.buyers', 'Buyers')->aggregate(Metric::DISTINCT)->dimensions(['region'])
                ->source(static function (Window $w, array $by): array|int {
                    if ($w->key() !== '2026-09-01') {
                        return $by === [] ? 0 : [];
                    }

                    return $by === [] ? self::BUYERS[''] : [['region' => 'north', 'value' => self::BUYERS['north']], ['region' => 'south', 'value' => self::BUYERS['south']]];
                }),
            Metric::flow('test.secret', 'Secret')->allowed(static fn (): bool => false)->source(static fn (): int => 9),
        );
        Metrics::compute(null, ['month'], '2026-08-01', '2026-09-30', false, (int) strtotime('2026-11-06 12:00 UTC'));
        $this->clearRequest();
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->clearRequest();
        Application::getInstance()->applicationInfo = $this->info;
        Metrics::reset();
    }

    private function clean(): void
    {
        $this->db->queryBuilder()->table(Metrics::TABLE)->where('metric', 'like', 'test.%')->delete();
        $this->db->queryBuilder()->table(Reports::TABLE)->where('name', 'like', 'Test %')->delete();
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

    /** A manager reading. */
    private static function reader(int $usertype = 90, int $id = 2): object
    {
        return (object) ['userid' => $id, 'usertype' => $usertype];
    }

    /**
     * Columns as specs: the total, two filtered, another metric, the month before. Rows: one
     * plain, one per region with the total, one of a secret metric (left out), one that cannot
     * be answered; the section total adds the plain and the region rows, not their subtotal.
     */
    public function testACellIsTheRowWithTheColumnInItsPeriod(): void
    {
        // Arrange
        $report = Report::fromArray(['name' => 'Test sales', 'grain' => 'month', 'compare' => 'previous',
            'columns' => [
                ['label' => 'All'],
                ['label' => 'North new', 'filters' => ['region' => 'north', 'customer' => 'new']],
                ['label' => 'North returning', 'filters' => ['region' => 'north', 'customer' => 'returning']],
                ['label' => 'Buyers', 'metric' => 'test.buyers'],
                ['label' => 'August', 'offset' => -1],
            ],
            'sections' => [['title' => 'Sales', 'total' => true, 'chart' => 'bar', 'rows' => [
                ['label' => 'Sales', 'metric' => 'test.sales'],
                ['metric' => 'test.buyers', 'breakdown' => 'region', 'total' => true],
                ['metric' => 'test.secret'],
                ['label' => 'By plan', 'metric' => 'test.buyers', 'breakdown' => 'plan'],
            ]]],
        ]);

        // Act
        $table = $report->build('2026-09-15', self::reader());
        $rows  = array_column($table['sections'][0]['rows'], null, 'label');
        $value = static fn (array $row): array => array_column($row['cells'], 'value');

        // Assert
        $this->assertSame(['All', 'North new', 'North returning', 'Buyers', 'August'], $table['columns']);
        $this->assertSame('2026-09', $table['period']);
        $this->assertSame(1, $table['hidden'], 'the secret metric is left out and counted');
        $this->assertSame([6.0, 3.0, 2.0, 4.0, 1.0], $value($rows['Sales']), 'each cell its own filters and period; Buyers its own metric');
        $this->assertSame([1.0, 5.0], [$rows['Sales']['other'], $rows['Sales']['change']], 'August, and +500%');
        $this->assertSame([3.0, null, null, 3.0, 0.0], $value($rows['North']), 'buyers by region: the customer filter is not kept by a distinct');
        $this->assertSame(4.0, $value($rows['Buyers'])[0], 'the subtotal is the metric without the breakdown: 4, not 3 + 2');
        $this->assertTrue($rows['Buyers']['subtotal']);
        $this->assertStringContainsString('not broken down by customer', (string) $rows['North']['cells'][1]['error'], 'that cell says why; the row stands');
        $this->assertStringContainsString('not broken down by plan', (string) $rows['By plan']['error']);
        $this->assertSame(6.0 + 3.0 + 2.0, $table['sections'][0]['total'][0], 'the section total leaves the subtotal out');
        $this->assertSame(['metric' => 'test.sales', 'grain' => 'month', 'period' => '2026-09-01', 'filters' => ['region' => 'north', 'customer' => 'new']], $rows['Sales']['cells'][1]['link']);
        $this->assertNull($rows['North']['cells'][0]['link'], 'buyers list no records');

        // Act — drawn and exported
        $html = Report::html($table, '/admin/MetricReports/rows');
        $csv  = Report::csv($table);

        // Assert
        $this->assertStringContainsString('href="/admin/MetricReports/rows?metric=test.sales&amp;grain=month&amp;period=2026-09-01&amp;f%5Bregion%5D=north&amp;f%5Bcustomer%5D=new"', $html);
        $this->assertStringContainsString('data-pf-chart-figure', $html);
        $this->assertStringContainsString('+500.0%', $html);
        $this->assertStringContainsString('1 rows are not shown', $html);
        $this->assertStringContainsString('class="pf-report-total"', $html);
        $this->assertMatchesRegularExpression('/<td class="pf-muted" title="Metric test.buyers is not broken down by customer.">—<\/td>/', $html);
        $this->assertStringStartsWith("section,row,All,\"North new\",\"North returning\",Buyers,August,2026-08,change\n", $csv);
        $this->assertStringContainsString("Sales,Sales,6,3,2,4,1,1,5\n", $csv);
        $this->assertStringContainsString('Sales,"By plan","Metric test.buyers is not broken down by plan."', $csv);
        $this->assertStringContainsString("Sales,Total,11,", $csv);
    }

    /** The shorthands: the last periods, and a dimension's values — declared ones by default — with the total. */
    public function testTheShorthands(): void
    {
        // Act
        $periods   = Report::fromArray(['columns' => ['periods' => 3], 'sections' => [['rows' => [['metric' => 'test.sales']]]]])->build('2026-09-01', self::reader());
        $dimension = Report::fromArray(['columns' => ['dimension' => 'region', 'total' => true], 'sections' => [['rows' => [['metric' => 'test.buyers']]]]])->build('2026-09-01', self::reader());
        $listed    = Report::fromArray(['columns' => ['dimension' => 'region', 'values' => ['south'], 'total' => false], 'sections' => [['rows' => [['metric' => 'test.buyers', 'label' => 'B']]]]])->build('2026-09-01', self::reader());

        // Assert
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $periods['columns']);
        $this->assertSame([null, 1.0, 6.0], array_column($periods['sections'][0]['rows'][0]['cells'], 'value'), 'July was not computed');
        $this->assertSame('Sales', $periods['sections'][0]['rows'][0]['label'], 'no label: the metric\'s');
        $this->assertSame(['North', 'South', 'Total'], $dimension['columns']);
        $this->assertSame([3.0, 2.0, 4.0], array_column($dimension['sections'][0]['rows'][0]['cells'], 'value'));
        $this->assertSame(['South'], $listed['columns']);
        $this->assertNull($periods['compare']);
    }

    /**
     * Saved reports: checked when saved; seen by whom their visibility says — the app's first;
     * changed only by their owner; deleted by the owner or an administrator; due to be mailed once
     * per closed period.
     */
    public function testSavedReports(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['metrics']['reports'] = [
            'kpi' => ['name' => 'Test KPI', 'sections' => [['rows' => [['metric' => 'test.sales']]]]],
            'bad' => ['grain' => 'hour'],
        ];
        $store = new Reports();
        $owner = self::reader(90, 2);
        $other = self::reader(90, 3);
        $boss  = self::reader(99, 4);
        $refused = [];
        foreach ([
            [['name' => ''], Presets::PRIVATE, null, '', ''],
            [['name' => 'Test x'], Presets::PRIVATE, null, 'hourly', ''],
            [['name' => 'Test x'], Presets::PRIVATE, null, Reports::MONTHLY, 'not an address'],
            [['name' => 'Test x'], 'friends', null, '', ''],
        ] as [$definition, $visibility, $audience, $schedule, $to]) {
            try {
                $store->save($owner, $definition, $visibility, $audience, $schedule, $to);
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }

        // Act
        $private = $store->save($owner, ['name' => 'Test mine', 'sections' => []]);
        $shared  = $store->save($owner, ['name' => 'Test shared', 'sections' => []], Presets::ROLE, '99', Reports::MONTHLY, 'a@example.com, nope, B@example.com a@example.com');

        // Assert
        $this->assertCount(4, $refused);
        $this->assertSame(['app:kpi', (string) $private, (string) $shared], array_column($store->visibleTo($owner), 'id'), 'the app\'s first; the bad one left out');
        $this->assertSame(['app:kpi'], array_column($store->visibleTo($other), 'id'));
        $this->assertSame(['app:kpi', (string) $shared], array_column($store->visibleTo($boss), 'id'), 'shared from usertype 99 up');
        $this->assertSame('a@example.com, B@example.com', $store->find((string) $shared, $boss)['row']['recipients']);
        $this->assertNull($store->find((string) $private, $other));
        $this->assertSame(['a@example.com'], Reports::addresses(' a@example.com;;x '));

        // Act — change, delete, mail
        $store->save($owner, ['name' => 'Test mine renamed', 'grain' => 'week'], Presets::EVERYONE, null, '', '', $private);
        try {
            $store->save($other, ['name' => 'Test stolen'], Presets::PRIVATE, null, '', '', $private);
            $this->fail('somebody else changed it');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('There is no such report of yours.', $e->getMessage());
        }
        $due = $store->due((int) strtotime('2026-11-06 UTC'));
        $store->markSent($shared, '2026-10-01');

        // Assert
        $this->assertSame('week', $store->find((string) $private, $other)['report']->definition['grain'], 'now everyone\'s');
        $this->assertSame([[(string) $shared, '2026-10-01']], array_map(static fn (array $e): array => [$e['id'], $e['period']], $due));
        $this->assertSame([], $store->due((int) strtotime('2026-11-20 UTC')), 'October was mailed');
        $this->assertCount(1, $store->due((int) strtotime('2026-12-01 UTC')), 'November closed');
        $this->assertFalse($store->delete($private, $other));
        $this->assertTrue($store->delete($private, $other, true), 'an administrator deletes anybody\'s');
        $this->assertTrue($store->delete($shared, $owner));
    }

    /**
     * `metrics:reports-send`: a due report is mailed to each address, as its owner sees it, and
     * marked; the next run sends nothing; a mail the outbox refuses leaves it due and exits 1.
     */
    public function testMailingReports(): void
    {
        // Arrange
        $id = (new Reports())->save(self::reader(), ['name' => 'Test mailed', 'sections' => [['rows' => [['metric' => 'test.sales']]]]], Presets::PRIVATE, null, Reports::MONTHLY, 'a@example.com, b@example.com');
        $sent    = [];
        $command = new class ($sent) extends \Pramnos\Console\Commands\MetricsReportsSend {
            public bool $refuse = false;

            public bool $outbox = true;

            public function __construct(public array &$sent)
            {
                parent::__construct();
            }

            protected function mail(): \Pramnos\Email\Email
            {
                if ($this->refuse) {
                    throw new \RuntimeException('the outbox is closed');
                }
                $sent  = &$this->sent;
                $mail  = new class ($this->outbox) extends \Pramnos\Email\Email {
                    public function __construct(private bool $accepts)
                    {
                        parent::__construct();
                    }

                    public function queue(): bool
                    {
                        return $this->accepts;
                    }
                };
                $sent[] = $mail;

                return $mail;
            }
        };
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Act
        $first  = $tester->execute([]);
        $output = $tester->getDisplay();
        $afterFirst = count($sent);
        $second = $tester->execute([]);
        $afterSecond = count($sent);
        $this->db->queryBuilder()->table(Reports::TABLE)->where('reportid', $id)->update(['sent_period' => '']);
        $command->refuse = true;
        $third = $tester->execute([]);
        $closed = $tester->getDisplay();
        $command->refuse = false;
        $command->outbox = false;
        $fourth = $tester->execute([]);

        // Assert
        $this->assertSame(0, $first);
        $this->assertStringContainsString('Mailed 1 reports.', $output);
        $this->assertSame(2, $afterFirst, 'one mail to each address');
        $this->assertSame(0, $second);
        $this->assertSame(2, $afterSecond, 'nothing the second time');
        $this->assertSame(1, $third);
        $this->assertStringContainsString('the outbox is closed', $closed);
        $this->assertSame(1, $fourth);
        $this->assertStringContainsString('the outbox refused the mail to a@example.com', $tester->getDisplay());
        $this->assertSame('', (string) $this->db->queryBuilder()->table(Reports::TABLE)->where('reportid', $id)->first()->fields['sent_period'], 'still due');
    }

    /**
     * The screen: the list, a report with its table and navigation, CSV, the records behind a
     * cell, the form — new, own, a copy, and somebody else's refused — saving, refusing what does
     * not check, and deleting; nothing at all where no metric is declared. Each template drawn
     * in every theme through the template engine.
     */
    public function testTheScreen(): void
    {
        // Arrange
        $this->signIn(98, 2);
        $id = (new Reports())->save(self::reader(98, 2), ['name' => 'Test screen', 'columns' => ['periods' => 2], 'sections' => [['title' => 'S', 'rows' => [['metric' => 'test.sales']]]]]);
        $theirs = (new Reports())->save(self::reader(98, 7), ['name' => 'Test theirs', 'sections' => []], Presets::EVERYONE);
        $screen = $this->screen(MetricReports::class);
        $pages  = [];

        // Act — the list, a report, the CSV, the records
        $this->request('GET');
        $screen->display();
        $pages['metricreports'] = (array) $screen->view;
        $this->request('GET', null, ['report' => (string) $id, 'at' => '2026-09-10']);
        $screen->show();
        $pages['report'] = (array) $screen->view;
        $csv = $screen->csv();
        $this->request('GET', null, ['metric' => 'test.sales', 'grain' => 'month', 'period' => '2026-09-01', 'f' => ['region' => 'north']]);
        $screen->rows();
        $pages['rows'] = (array) $screen->view;
        $this->request('GET', null, ['metric' => 'test.buyers']);
        $screen->rows();

        // Assert
        $this->assertSame(['Test screen', 'Test theirs'], array_column($pages['metricreports']['reports'], 'name'));
        $this->assertSame('2026-09', $pages['report']['period']);
        $this->assertSame(['2026-08-01', '2026-10-01'], [$pages['report']['previous'], $pages['report']['next']]);
        $this->assertTrue($pages['report']['mine']);
        $this->assertStringContainsString('MetricReports/rows?metric=test.sales', $pages['report']['body']);
        $this->assertSame("\xEF\xBB\xBFsection,row,2026-08,2026-09\nS,Sales,1,6\n", (string) $csv->getBody());
        $this->assertStringContainsString('Test-screen-2026-09.csv', $csv->getHeaderLine('Content-Disposition'));
        $this->assertSame([['order' => 1, 'region' => 'north']], $pages['rows']['rows']);
        $this->assertContains(['error', 'There is no such list.'], $screen->flash, 'buyers list no records');

        // Act — the form: new, own, copy, theirs
        $this->request('GET');
        $screen->edit();
        $new = (array) $screen->view;
        $this->request('GET', null, ['report' => (string) $id]);
        $screen->edit();
        $own = (array) $screen->view;
        $pages['edit'] = $own;
        $this->request('GET', null, ['copy' => (string) $theirs]);
        $screen->edit();
        $copy = (array) $screen->view;
        $screen->flash = [];
        $this->request('GET', null, ['report' => (string) $theirs]);
        $screen->edit();
        $this->request('GET', null, ['report' => '999999']);
        $screen->edit();

        // Assert
        $this->assertSame(0, $new['id']);
        $this->assertSame("periods=12\n", $new['columns']);
        $this->assertSame($id, $own['id']);
        $this->assertSame("periods=2\n", $own['columns']);
        $this->assertSame("# S\ntest.sales\n", $own['sectionsText']);
        $this->assertSame('Copy of Test theirs', $copy['reportName']);
        $this->assertSame(0, $copy['id']);
        $this->assertSame([['error', 'Only its owner changes a report. Copy it to make one of your own.'], ['error', 'There is no such report.']], $screen->flash);

        // Act — save, refuse, delete
        $screen->flash     = [];
        $screen->redirects = [];
        $this->request('POST', null, [], ['reportid' => (string) $id, 'name' => 'Test screen 2', 'grain' => 'month', 'compare' => 'year', 'columns' => 'dimension=region total', 'sections' => "# T\nB | test.buyers", 'visibility' => 'private']);
        $screen->save();
        $this->request('POST', null, [], ['name' => '', 'sections' => '']);
        $screen->save();
        $this->request('POST', null, [], ['reportid' => (string) $theirs]);
        $screen->delete();   // an administrator deletes anybody's
        $this->request('POST', null, [], ['reportid' => (string) $id]);
        $screen->delete();

        // Assert
        $this->assertSame([['message', 'Saved.'], ['error', 'A report needs a name of up to 120 characters.'], ['message', 'Deleted.'], ['message', 'Deleted.']], $screen->flash);
        $this->assertStringEndsWith('MetricReports/show?report=' . $id, $screen->redirects[0]);
        $this->assertStringEndsWith('MetricReports/edit', $screen->redirects[1]);

        // Act — every template, every theme, through the engine
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            foreach ($pages as $file => $vars) {
                $html = $this->renderTemplate($theme, 'metricreports/' . $file . '.tpl.php', $vars);

                // Assert
                $this->assertStringNotContainsString('{{', $html, $theme . '/' . $file . ': compiled');
                $this->assertStringContainsString('pf-ui', $html, $theme . '/' . $file);
            }
            // The form shows the report's own name and sections — not the view's name, nor "Array"
            // from the view's own $sections property.
            $form  = $this->renderTemplate($theme, 'metricreports/edit.tpl.php', $own);
            $blank = $this->renderTemplate($theme, 'metricreports/edit.tpl.php', $new);
            $this->assertStringContainsString('name="name" value="Test screen"', $form, $theme . ': own name');
            $this->assertMatchesRegularExpression('~name="sections"[^>]*>\# S\ntest\.sales\n</textarea>~', $form, $theme . ': own sections');
            $this->assertStringContainsString('name="name" value=""', $blank, $theme . ': a new report has no name');
            $this->assertStringNotContainsString('Array', $blank, $theme . ': no array printed');
        }

        // Act — below the floor, every action stops; a delete of nothing says so
        $this->signIn(98, 2);
        $screen->flash = [];
        $this->request('POST', null, [], ['reportid' => '0']);
        $screen->delete();
        $this->signIn(1, 5);
        $low = $this->screen(MetricReports::class);
        foreach (['display', 'show', 'csv', 'rows', 'edit', 'save', 'delete'] as $action) {
            $this->assertNull($low->{$action}(), $action . ' stops below the floor');
        }

        // Assert
        $this->assertSame([['error', 'There is no such report of yours.']], $screen->flash);
        $this->assertNull($low->view, 'no view filled');

        // Act — no metrics, no screen
        $this->signIn(98, 2);
        Metrics::reset();
        Application::getInstance()->applicationInfo['metrics'] = [];
        $screen->flash = [];
        $this->request('GET');
        $screen->display();

        // Assert
        $this->assertSame([['error', 'No metrics are declared here, so there are no reports.']], $screen->flash);
    }

    /**
     * A `.tpl.php` template drawn as the view engine draws it: compiled, then run inside a real
     * view with the variables a controller set on it — so a variable named like one of the view's
     * own properties reads the property here too, as it does on a live page.
     *
     * @param array<string, mixed> $vars
     */
    private function renderTemplate(string $theme, string $file, array $vars): string
    {
        $source   = (string) file_get_contents(ROOT . '/scaffolding/themes/' . $theme . '/views/' . $file);
        $compiled = (new \Pramnos\Application\Template\TemplateCompiler())->compile($source);
        $path     = tempnam(sys_get_temp_dir(), 'tpl') . '.php';
        file_put_contents($path, $compiled);
        $view = (new \ReflectionClass(\Pramnos\Application\View::class))->newInstanceWithoutConstructor();
        foreach ($vars as $key => $value) {
            $view->{$key} = $value;
        }
        try {
            return (function (string $path): string {
                ob_start();
                try {
                    include $path;
                } finally {
                    $html = (string) ob_get_clean();
                }

                return $html;
            })->call($view, $path);
        } finally {
            unlink($path);
        }
    }
}
