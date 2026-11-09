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
        $this->assertMatchesRegularExpression('/<td class="pf-muted" style="text-align:right" title="Metric test.buyers is not broken down by customer.">—<\/td>/', $html);
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
     * A report with Greek names is stored whole, and one the store cannot encode is refused with
     * an error instead of being saved with an empty definition — which is what a copied report
     * with every «υ» split in half used to become.
     */
    public function testAGreekReportIsStoredWholeAndABrokenOneIsRefused(): void
    {
        // Arrange
        $store = new Reports();
        $owner = self::reader(90, 2);
        $text  = "# Πληρωμές ανά πακέτο\nΑιτήματα που στάλθηκαν | test.sales\n";

        // Act
        $id = $store->save($owner, ['name' => 'Test αντίγραφο', 'sections' => Report::sectionsFromText($text)]);
        try {
            $store->save($owner, ['name' => "Test \xcf", 'sections' => []]);
            $this->fail('a definition that does not encode was saved');
        } catch (\JsonException $e) {
            $refused = $e->getMessage();
        }

        // Assert
        $found = $store->find((string) $id, $owner)['report']->definition;
        $this->assertSame('Test αντίγραφο', $found['name']);
        $this->assertSame('Πληρωμές ανά πακέτο', $found['sections'][0]['title']);
        $this->assertSame('Αιτήματα που στάλθηκαν', $found['sections'][0]['rows'][0]['label']);
        $this->assertNotSame('', $refused);
        $this->assertCount(1, array_filter(array_column($store->visibleTo($owner), 'name'), static fn ($n): bool => str_starts_with((string) $n, 'Test ')), 'the broken one is not stored');
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
        $blankId = (new Reports())->save(self::reader(98, 2), ['name' => 'Test blank', 'sections' => []]);
        $this->request('GET', null, ['report' => (string) $blankId]);
        $screen->show();
        $pages['blank'] = (array) $screen->view;
        (new Reports())->delete($blankId, self::reader(98, 2));

        // Assert
        $this->assertSame(['Test screen', 'Test theirs'], array_column($pages['metricreports']['reports'], 'name'));
        $this->assertSame('2026-09', $pages['report']['period']);
        $this->assertSame(['2026-08-01', '2026-10-01'], [$pages['report']['previous'], $pages['report']['next']]);
        $this->assertTrue($pages['report']['mine']);
        $this->assertFalse($pages['report']['customise'], 'a report with cards opens to be read');
        $this->assertTrue($pages['blank']['customise'], 'a new report of one\'s own, with no cards, opens in Customise');
        unset($pages['blank']);
        $this->assertStringContainsString('MetricReports/rows?metric=test.sales', $pages['report']['body']);
        // The report's cards: a section is a line, not a card; a card is headed by nothing rather
        // than by its kind's name.
        $this->assertStringContainsString('pf-report-section-card', $pages['report']['body']);
        $this->assertStringContainsString('data-pf-report-section', $pages['report']['body']);
        $this->assertStringNotContainsString('<h3 class="h6 mb-0">Table of metrics', $pages['report']['body']);
        $this->assertStringNotContainsString('data-pf-dashboard-edit', $pages['report']['body'], 'Customise is in the report\'s own bar');
        // The catalogue names the kind of card — not the heading of the last section drawn.
        $this->assertMatchesRegularExpression('~data-pf-dashboard-option="report\\.section".{0,400}?>Section<~s', $pages['report']['body']);
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
            // The text is for the advanced: folded away on a new report, open on one written as text.
            $this->assertStringContainsString('<details class="pf-field">', $blank, $theme . ': the syntax folded on a new report');
            $this->assertStringContainsString('<details class="pf-field" open>', $form, $theme . ': open where the report is text');
            // A report with no cards opens in Customise; one with cards does not.
            $empty = $this->renderTemplate($theme, 'metricreports/report.tpl.php', ['customise' => true] + $pages['report']);
            $this->assertStringContainsString('data-pf-dashboard-open', $empty, $theme . ': an empty report opens to be arranged');
            $this->assertStringNotContainsString('data-pf-dashboard-open', $this->renderTemplate($theme, 'metricreports/report.tpl.php', $pages['report']), $theme);
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
     * A report of cards, drawn for a mail: a section heading, a table (regions and their total, two
     * months), a chart with the year before (an image carried inline, or its numbers without a
     * converter), a figure with its change, words, the records behind a metric, and a figure of a
     * metric the reader may not see — which says so instead of a number. The report's CSV is its
     * table, under its section's heading.
     */
    public function testAReportOfCardsIsDrawnForItsPeriod(): void
    {
        // Arrange
        $layout = [
            ['id' => 'report.section~s1', 'settings' => ['title' => 'Selling']],
            ['id' => 'report.table~t1', 'settings' => ['rows' => [['metric' => 'test.sales', 'breakdown' => 'region', 'total' => true]], 'columns' => 'periods', 'periods' => 2]],
            ['id' => 'report.chart~c1', 'settings' => ['title' => 'Sales chart', 'series' => [['metric' => 'test.sales', 'label' => 'Sales']], 'periods' => 2, 'year' => '1']],
            ['id' => 'report.kpi~k1', 'size' => 's', 'settings' => ['metric' => 'test.sales', 'compare' => 'previous']],
            ['id' => 'report.text~x1', 'settings' => ['text' => "**Note** <script>x</script>"]],
            ['id' => 'report.rows~r1', 'settings' => ['metric' => 'test.sales', 'filters' => ['region' => 'north']]],
            ['id' => 'report.kpi~k2', 'settings' => ['metric' => 'test.secret']],
        ];
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test cards', 'layout' => $layout]);
        $entry = (new Reports())->find((string) $id, self::reader());
        \Pramnos\Metrics\ChartImage::convertWith(static fn (string $svg): string => "\x89PNG" . strlen($svg));

        // Act
        try {
            $mail   = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader());
            $images = \Pramnos\Metrics\ReportBoard::images();
            \Pramnos\Metrics\ReportBoard::leave();
            \Pramnos\Metrics\ChartImage::convertWith(static fn (): ?string => null);
            $plain = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader());
            \Pramnos\Metrics\ReportBoard::leave();
            $live = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), '/rows', false);
            \Pramnos\Metrics\ReportBoard::leave();
        } finally {
            \Pramnos\Metrics\ChartImage::convertWith(null);
        }
        $csv = \Pramnos\Metrics\ReportBoard::csv($entry, '2026-09-10', self::reader());

        // Assert — the cards in order, each for September
        $this->assertStringContainsString('<h2 style="margin:24px 0 8px">Selling</h2>', $mail);
        $this->assertStringContainsString('<th scope="col" style="text-align:right">2026-08</th><th scope="col" style="text-align:right">2026-09</th>', $mail, 'two months');
        $this->assertStringContainsString('src="cid:chart-1.png"', $mail, 'the chart as an image');
        $this->assertSame(['chart-1.png'], array_keys($images));
        $this->assertStringStartsWith("\x89PNG", $images['chart-1.png']);
        $this->assertStringContainsString('+500.0%', $mail, 'the figure: September (6) against August (1)');
        $this->assertStringContainsString('<strong>Note</strong>', $mail);
        $this->assertStringNotContainsString('<script>x', $mail, 'words are escaped');
        $this->assertStringContainsString('<td>north</td>', $mail, 'the records, filtered');
        $this->assertStringNotContainsString('>9<', $mail, 'a metric the reader may not see is not even offered');
        $this->assertSame(1, substr_count($mail, 'Choose a metric'), '…so that figure has none');
        // Without a converter: the chart's numbers, and no image
        $this->assertStringNotContainsString('cid:', $plain);
        $this->assertStringContainsString('Sales (2025-09)', $plain, 'the year before beside it');
        // On the page: a chart Chart.js draws, a section that folds, cells linked to their records
        $this->assertStringContainsString('data-pf-chart-config', $live);
        $this->assertStringContainsString('data-pf-report-section', $live);
        $this->assertStringContainsString('/rows?metric=test.sales', $live);
        $this->assertStringContainsString("Selling,North,1,5\n", $csv, 'the table under its section');
    }

    /**
     * The edges of a report's cards: a table's columns by a dimension's values (with its total) and
     * of its own; a figure compared with nothing; records of a metric that lists none, of one with
     * no records in the period, and of one whose list fails; a card of the application's own that
     * throws is left empty, not a broken report; and without a converter of its own the server
     * either makes a PNG or answers none.
     */
    public function testTheEdgesOfAReportsCards(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.broken', 'Broken')->source(static fn (): int => 0)
            ->rows(static fn (): array => throw new \RuntimeException('no list')));
        \Pramnos\Dashboard\WidgetRegistry::register(new class extends \Pramnos\Metrics\Widgets\ReportWidget {
            public function id(): string { return 'test.throws'; }
            protected function name(): string { return 'Throws'; }
            public function render(): string { throw new \RuntimeException('a card that fails'); }
        });
        $layout = [
            ['id' => 'report.table~d1', 'settings' => ['rows' => [['metric' => 'test.sales']], 'columns' => 'dimension', 'dimension' => 'region', 'values' => 'north', 'column_total' => '1']],
            ['id' => 'report.table~u1', 'settings' => ['title' => 'Own', 'rows' => [['metric' => 'test.sales']], 'columns' => 'custom', 'custom' => [['label' => 'South', 'filters' => ['region' => 'south']], ['label' => 'Before', 'offset' => 1]]]],
            ['id' => 'report.table~e1', 'settings' => []],
            ['id' => 'report.chart~e2', 'settings' => []],
            ['id' => 'report.kpi~n1', 'settings' => ['metric' => 'test.sales', 'compare' => 'none', 'title' => 'Plain']],
            ['id' => 'report.rows~n2', 'settings' => ['metric' => 'test.buyers']],
            ['id' => 'report.rows~n3', 'settings' => ['metric' => 'test.sales', 'filters' => ['region' => 'nowhere']]],
            ['id' => 'report.rows~n4', 'settings' => ['metric' => 'test.broken']],
            ['id' => 'report.text~n5', 'settings' => []],
            ['id' => 'test.throws~z1', 'settings' => []],
        ];
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test edges', 'layout' => $layout]);
        $entry = (new Reports())->find((string) $id, self::reader());

        // Act
        try {
            $html = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), '/rows', false);
        } finally {
            \Pramnos\Metrics\ReportBoard::leave();
            \Pramnos\Dashboard\WidgetRegistry::reset();
        }
        $png = \Pramnos\Metrics\ChartImage::png('line', ['a'], [['label' => 'A', 'data' => [1.0]]]);

        // Assert
        $this->assertStringContainsString('<th scope="col" style="text-align:right">North</th><th scope="col" style="text-align:right">Total</th>', $html, 'a dimension\'s value, and the total');
        $this->assertStringContainsString('<th scope="col" style="text-align:right">South</th><th scope="col" style="text-align:right">Before</th>', $html, 'columns of its own');
        $this->assertMatchesRegularExpression('~region%5D=south">1</a></td><td style="text-align:right"><a href="[^"]*period=2026-08-01">1</a>~', $html, 'September\'s south (1), August\'s total (1)');
        $this->assertStringContainsString('Add a row: choose a metric', $html);
        $this->assertStringContainsString('Add a series: choose a metric', $html);
        $this->assertStringContainsString('Plain', $html);
        $this->assertStringNotContainsString('against', $html, 'compared with nothing');
        $this->assertSame(1, substr_count($html, 'Choose a metric in the card'), 'a metric without records is not offered for them');
        $this->assertStringContainsString('<td>nowhere</td>', $html, 'the records filtered');
        $this->assertStringContainsString('The records could not be read', $html);
        $this->assertStringContainsString('Write the text in the card', $html);
        $this->assertStringNotContainsString('a card that fails', $html);
        $this->assertTrue($png === null || str_starts_with($png, "\x89PNG"), 'a PNG, or none without a converter');
    }

    /**
     * A report written as sections is read as cards: its heading, its chart, its table. Its board's
     * layout is kept in the report by its owner alone — another reader sees it and cannot change
     * it — and the dashboard serves a report's board: a card drawn for the period asked, a save by
     * somebody else refused.
     */
    public function testAReportsBoardIsItsOwners(): void
    {
        // Arrange
        $id    = (new Reports())->save(self::reader(98, 2), ['name' => 'Test board', 'sections' => [['title' => 'S', 'chart' => 'bar', 'rows' => [['metric' => 'test.sales']]]]], Presets::EVERYONE);
        $board = \Pramnos\Metrics\ReportBoard::board((string) $id);
        $read  = \Pramnos\Metrics\ReportBoard::layout((new Reports())->find((string) $id, self::reader())['report']);
        $mine  = [['id' => 'report.kpi~a1', 'size' => 's', 'settings' => ['metric' => 'test.sales']], ['id' => 'report.text~b2', 'settings' => ['text' => 'hi']]];

        // Act
        $kept = (new \Pramnos\Dashboard\Layouts())->save(self::reader(98, 2), $mine, $board);
        $seen = (new \Pramnos\Dashboard\Layouts())->for(self::reader(90, 7), $board);
        $refused = null;
        try {
            (new \Pramnos\Dashboard\Layouts())->save(self::reader(98, 7), [], $board);
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }
        $this->signIn(98, 7);
        $this->request('POST', null, ['board' => $board], ['layout' => '[]']);
        $theirs = (new \Pramnos\Application\Controllers\DashboardController(Application::getInstance()))->savelayout();
        $this->signIn(98, 2);
        $this->request('GET', null, ['board' => $board, 'id' => 'report.kpi~a1', 'part' => 'body', 'at' => '2026-09-10']);
        $card = (new \Pramnos\Application\Controllers\DashboardController(Application::getInstance()))->card();
        \Pramnos\Metrics\ReportBoard::leave();

        // Assert
        $this->assertSame(['report.section~s0', 'report.chart~c0', 'report.table~t0'], array_column($read, 'id'));
        $this->assertArrayNotHasKey('title', $read[1]['settings'], 'the chart is not headed again by its section\'s heading');
        $this->assertSame(['report.kpi~a1', 'report.text~b2'], array_column($kept, 'id'));
        $this->assertSame(['report.kpi~a1', 'report.text~b2'], array_column($seen, 'id'), 'the same for everybody');
        $this->assertSame([], (new Reports())->find((string) $id, self::reader())['report']->definition['sections'], 'the cards replace the sections');
        $this->assertSame('There is no such report of yours.', $refused);
        $this->assertSame(403, $theirs->getStatusCode());
        $this->assertSame(200, $card->getStatusCode());
        $this->assertStringContainsString('2026-09', (string) $card->getBody(), 'drawn for the period asked');
        $this->assertTrue((new Reports())->owns((string) $id, self::reader(98, 2)));
        $this->assertFalse((new Reports())->owns('app:x', self::reader(98, 2)));
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
