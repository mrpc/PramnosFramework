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
        \Pramnos\Metrics\Store::forget($this->db, 'test.%');
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
        $this->assertSame(['metric' => 'test.sales', 'grain' => 'month', 'period' => '2026-09-01', 'filters' => ['region' => 'north', 'customer' => 'new'], 'url' => null], $rows['Sales']['cells'][1]['link']);
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
            'bad' => ['grain' => 'era'],
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
        $xlsx = $screen->xlsx();
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
        $this->assertSame([], $pages['report']['pickers'], 'FW-428: a report that reads no dimension offers no picker');
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
        $this->assertStringStartsWith('PK', (string) $xlsx->getBody(), 'a spreadsheet: a zip');
        $this->assertStringContainsString('Test-screen-2026-09.xlsx', $xlsx->getHeaderLine('Content-Disposition'));
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

        // Act — every template, every theme, through the engine; a record's value opens where the application says (FW-457)
        Application::getInstance()->applicationInfo['metrics']['links'] = ['order' => static fn ($value): string => '/admin/Orders/' . $value];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $records = $this->renderTemplate($theme, 'metricreports/rows.tpl.php', $pages['rows']);
            $this->assertStringContainsString('<a href="/admin/Orders/1">1</a>', $records, $theme . ': the order opens');
            $this->assertStringContainsString('<td>north</td>', $records, $theme . ': a column without a link is text');
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
            $this->assertStringContainsString('name="v[region]"', $this->renderTemplate($theme, 'metricreports/report.tpl.php', ['variables' => ['region' => 'north'], 'pickers' => ['region' => ['label' => 'Region', 'values' => ['north' => 'North'], 'search' => false]]] + $pages['report']), $theme . ': the board\'s pickers');
        }

        // Act — below the floor, every action stops; a delete of nothing says so
        $this->signIn(98, 2);
        $screen->flash = [];
        $this->request('POST', null, [], ['reportid' => '0']);
        $screen->delete();
        $this->signIn(1, 5);
        $low = $this->screen(MetricReports::class);
        foreach (['display', 'show', 'csv', 'xlsx', 'rows', 'edit', 'save', 'delete'] as $action) {
            $this->assertNull($low->{$action}(), $action . ' stops below the floor');
        }

        // Assert
        $this->assertSame([['error', 'There is no such report of yours.']], $screen->flash);
        $this->assertNull($low->view, 'no view filled');

        // Act — no metrics, no screen
        $this->signIn(98, 2);
        Metrics::reset();
        // None declared, and not the audience's either, which its tables would bring.
        Application::getInstance()->applicationInfo['metrics'] = ['audience' => false];
        $screen->flash = [];
        $this->request('GET');
        $screen->display();

        // Assert
        $this->assertSame([['error', 'No metrics are declared here, so there are no reports.']], $screen->flash);
    }

    /**
     * FW-428: the pickers reach the page through a real View — whose own property answers a copy,
     * so a picker written into it one element at a time was lost — and only for the dimensions
     * the report reads, or the address sets. A closure's values are read once a page, not once a
     * card. A long list is a search field, in every theme.
     */
    public function testThePickersReachThePageForTheDimensionsTheReportReads(): void
    {
        // Arrange
        $this->signIn(98, 2);
        $read = 0;
        Metrics::dimension(Dimension::of('coupon', 'Coupon')->values(static function () use (&$read): array {
            $read++;

            return array_combine(array_map(static fn (int $i): string => 'c' . $i, range(1, 60)), array_map(static fn (int $i): string => 'Coupon ' . $i, range(1, 60)));
        }));
        $id     = (new Reports())->save(self::reader(98, 2), ['name' => 'Test pickers', 'sections' => [['title' => 'S', 'rows' => [['metric' => 'test.sales', 'breakdown' => 'region']]]]]);
        $screen = eval('return new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Application\Controllers\MetricReports {
            public ?object $view = null;
            public function &getView($name = "", $type = "", $args = []) {
                $this->view = new class (new \Pramnos\Application\Controller(), ROOT . "/scaffolding/themes/plain-css/views/metricreports", "metricreports") extends \Pramnos\Application\View {
                    public function display($tpl = "", $render = false) { return ""; }
                };
                $view = $this->view;
                return $view;
            }
        };');

        // Act — the report alone, then with a coupon set from the address
        $this->request('GET', null, ['report' => (string) $id, 'at' => '2026-09-10']);
        $screen->show();
        $first = $screen->view->pickers;
        $readFirst = $read;
        $this->request('GET', null, ['report' => (string) $id, 'at' => '2026-09-10', 'v' => ['coupon' => 'c7']]);
        $screen->show();
        $second = $screen->view->pickers;
        (new Reports())->delete($id, self::reader(98, 2));

        // Assert
        $this->assertSame(['region'], array_keys($first), 'the dimension the report breaks down by');
        $this->assertSame(['North', 'South'], array_values($first['region']['values']));
        $this->assertFalse($first['region']['search'], 'a short list: a list');
        $this->assertLessThanOrEqual(1, $readFirst, 'a closure\'s values read once a page at most — the cards\' editor reads them too — not once a card');
        $this->assertSame(['region', 'coupon'], array_keys($second), 'set from the address: offered too');
        $this->assertTrue($second['coupon']['search'], 'more than ' . MetricReports::PICKER_LIST . ' values: a search field');
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $html = $this->renderTemplate($theme, 'metricreports/report.tpl.php', ['entry' => ['id' => 'x', 'name' => 'R', 'app' => false], 'period' => 'p',
                'previous' => 'a', 'next' => 'b', 'at' => '2026-09-10', 'body' => '', 'mine' => false, 'customise' => false,
                'variables' => ['coupon' => 'c7'], 'pickers' => $second, 'urls' => $screen->view->urls]);
            $this->assertStringContainsString('<input type="search" name="v[coupon]" list="pf-dimension-coupon" value="c7"', $html, $theme);
            $this->assertStringContainsString('<select name="v[region]"', $html, $theme);
        }
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
     * The application decides who opens which report (FW-459): `reports_access` keeps one from a
     * person, or gives them one the framework's rule would not, and null keeps the rule — for the
     * list, opening and mailing alike. A report is shown in the menu where somebody who may turned
     * it on, an `app:` one by its own `menu` until then. A person customises a report they may
     * not change: their own copy, bound to it, opened in its place and never listed beside it,
     * until they go back to the original.
     */
    public function testAccessMenuAndACustomisedCopy(): void
    {
        // Arrange
        $theirs = (new Reports())->save(self::reader(98, 7), ['name' => 'Test shared', 'sections' => [['rows' => [['metric' => 'test.sales']]]]], Presets::EVERYONE);
        $hidden = (new Reports())->save(self::reader(98, 7), ['name' => 'Test private', 'sections' => []]);
        Application::getInstance()->applicationInfo['metrics']['reports'] = ['monthly' => ['name' => 'Monthly', 'menu' => true, 'sections' => []],
            'other' => ['name' => 'Other', 'sections' => []]];
        Application::getInstance()->applicationInfo['metrics']['reports_access'] = static fn (object $user, array $entry): ?bool => match ($entry['id']) {
            'app:other' => false,
            (string) $hidden => (int) $user->userid === 2 ? true : null,
            default => null,
        };
        $me      = self::reader(90, 2);
        $reports = new Reports();
        \Pramnos\Application\Settings::setSetting(Reports::MENU_SETTING, '{}', false);

        // Act
        $listed      = array_column($reports->visibleTo($me), 'id');
        $menuBefore  = array_column($reports->inMenu($me), 'id');
        $refused     = $reports->setMenu((string) $theirs, true, $me);
        $root        = self::reader(99, 9);
        $turned      = [$reports->setMenu((string) $theirs, true, $root), $reports->setMenu('app:monthly', false, $root)];
        $menuAfter   = array_column($reports->inMenu($me), 'id');
        $copy        = $reports->customise((string) $theirs, $me);
        $again       = $reports->customise((string) $theirs, $me);
        $opened      = $reports->find((string) $theirs, $me);
        $listedAfter = array_column($reports->visibleTo($me), 'id');
        $back        = $reports->uncustomise((string) $theirs, $me);
        $original    = $reports->find((string) $theirs, $me);
        try {
            $reports->customise('app:other', $me);
            $nope = false;
        } catch (\InvalidArgumentException $e) {
            $nope = true;
        }
        unset(Application::getInstance()->applicationInfo['metrics']['reports'], Application::getInstance()->applicationInfo['metrics']['reports_access']);
        \Pramnos\Application\Settings::setSetting(Reports::MENU_SETTING, '{}', false);
        (new Reports())->delete($theirs, $root, true);
        (new Reports())->delete($hidden, $root, true);

        // Assert
        $this->assertContains('app:monthly', $listed);
        $this->assertNotContains('app:other', $listed, 'kept from them by the application');
        $this->assertContains((string) $hidden, $listed, 'given to them by the application, though private');
        $this->assertContains((string) $theirs, $listed, 'null: the framework\'s rule');
        $this->assertSame(['app:monthly'], $menuBefore, 'an app report by its own menu');
        $this->assertFalse($refused, 'only who may edit the menu');
        $this->assertSame([true, true], $turned);
        $this->assertSame([(string) $theirs], $menuAfter, 'turned on, and the app one turned off');
        $this->assertSame($copy, $again, 'one copy for each person');
        $this->assertSame((string) $copy, $opened['id'], 'their copy, opened in its place');
        $this->assertSame((string) $theirs, $opened['customises']);
        $this->assertNotContains((string) $copy, $listedAfter, 'never listed beside the original');
        $this->assertTrue($back);
        $this->assertSame((string) $theirs, $original['id'], 'back to the original');
        $this->assertTrue($nope, 'nothing to customise that they may not open');
    }

    /**
     * The page's side of FW-459: Customise makes the copy and opens it to be arranged under the
     * original's address, Reset to the original takes it away, the menu toggle is for who may and
     * refused to anybody else; the ⋯ menu offers each where it applies. The analyst's records carry
     * each linked value as a markdown link.
     */
    public function testCustomiseAndTheMenuFromThePage(): void
    {
        // Arrange
        $this->signIn(98, 2);
        $theirs = (new Reports())->save(self::reader(98, 7), ['name' => 'Test page shared', 'sections' => [['rows' => [['metric' => 'test.sales']]]]], Presets::EVERYONE);
        $screen = $this->screen(MetricReports::class);
        Application::getInstance()->applicationInfo['metrics']['links'] = ['order' => static fn ($value): string => '/admin/Orders/' . $value];

        // Act
        $this->request('POST', null, [], ['report' => (string) $theirs]);
        $screen->customise();
        $this->request('GET', null, ['report' => (string) $theirs, 'customise' => '1', 'at' => '2026-09-10']);
        $screen->show();
        $page = (array) $screen->view;
        $this->request('POST', null, [], ['report' => (string) $theirs, 'on' => '1']);
        $screen->menu();
        $this->request('POST', null, [], ['report' => (string) $theirs]);
        $screen->uncustomise();
        $this->request('POST', null, [], ['report' => 'no-such']);
        $screen->customise();
        $this->request('GET', null, ['report' => (string) $theirs, 'at' => '2026-09-10']);
        $screen->show();
        $original = (array) $screen->view;
        // The tool's own reading of a metric's records, past whose account it asks as.
        $records  = (new \ReflectionMethod(\Pramnos\Llm\Analyst\Tools\MetricsTool::class, 'rows'))
            ->invoke(new \Pramnos\Llm\Analyst\Tools\MetricsTool(2), Metrics::get('test.sales'), 'month', '2026-09-10', ['region' => 'north']);
        $html     = $this->renderTemplate('tailwind', 'metricreports/report.tpl.php', $page);
        $plain    = $this->renderTemplate('plain-css', 'metricreports/report.tpl.php', $original);
        unset(Application::getInstance()->applicationInfo['metrics']['links']);
        (new Reports())->delete($theirs, self::reader(99, 9), true);

        // Assert
        $this->assertStringEndsWith('/show?report=' . $theirs . '&customise=1', $screen->redirects[0], 'the copy, opened to be arranged');
        $this->assertSame((string) $theirs, $page['reportId'], 'under the original\'s address');
        $this->assertSame((string) $theirs, $page['original']);
        $this->assertTrue($page['mine'] && $page['customise'], 'their own, to arrange');
        $this->assertContains(['error', 'You may not change the menu.'], $screen->flash);
        $this->assertContains(['message', 'Back to the original.'], $screen->flash);
        $this->assertContains(['error', 'There is no such report.'], $screen->flash);
        $this->assertNull($original['original'], 'the original again');
        $this->assertStringContainsString('Reset to the original', $html);
        $this->assertStringContainsString('Your own arrangement of this report', $html);
        $this->assertStringContainsString('value="' . $theirs . '"', $html);
        $this->assertStringNotContainsString('Show in the menu', $html, 'not for who may not');
        $this->assertStringContainsString('>Copy<', $plain);
        $this->assertStringNotContainsString('/customise"', $plain, 'customising is the Tailwind page\'s');
        $this->assertSame('[1](/admin/Orders/1)', $records['rows'][0]['order'], 'a linked value, as a markdown link');
        $this->assertArrayHasKey('links', $records);
    }

    /**
     * A table of measures (FW-473): a row for each value of a dimension and the total, a column
     * for each measure — a metric with filters of its own, a formula, a share «n (x%)» of another
     * measure, a scale in words — on the report's page, its CSV, and each metric's cell linked to
     * its records.
     */
    public function testATableOfMeasures(): void
    {
        // Arrange
        $section = ['title' => 'Customers', 'by' => 'region', 'values' => ['north', 'south'], 'total' => true, 'measures' => [
            ['label' => 'Sales', 'metric' => 'test.sales', 'format' => 'int'],
            ['label' => 'New', 'metric' => 'test.sales', 'filters' => ['customer' => 'new'], 'format' => 'share', 'of' => 'Sales'],
            ['label' => 'New share', 'formula' => '100 * n / t', 'format' => 'percent', 'decimals' => 0,
                'operands' => ['n' => ['metric' => 'test.sales', 'filters' => ['customer' => 'new']], 't' => ['metric' => 'test.sales']]],
            ['label' => 'Verdict', 'formula' => 'n / t', 'scale' => [[0.7, 'Some'], [1, 'All']],
                'operands' => ['n' => ['metric' => 'test.sales', 'filters' => ['customer' => 'new']], 't' => ['metric' => 'test.sales']]],
        ]];
        $report = Report::fromArray(['sections' => [$section]]);
        $id     = (new Reports())->save(self::reader(), ['name' => 'Test measures', 'sections' => [$section]]);
        $entry  = (new Reports())->find((string) $id, self::reader());

        // Act
        $built = $report->build('2026-09-10', self::reader());
        try {
            $html = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), '/rows', false);
        } finally {
            \Pramnos\Metrics\ReportBoard::leave();
        }
        $csv = \Pramnos\Metrics\ReportBoard::csv($entry, '2026-09-10', self::reader());
        (new Reports())->delete($id, self::reader(99, 9), true);

        // Assert
        $rows = array_column($built['sections'][0]['rows'], null, 'label');
        $text = static fn (array $row): array => array_column($row['cells'], 'text');
        $this->assertSame(['Sales', 'New', 'New share', 'Verdict'], $built['sections'][0]['columns']);
        $this->assertSame(['5', '3 (60%)', '60%', 'Some'], $text($rows['North']));
        $this->assertSame(['1', '1 (100%)', '100%', 'All'], $text($rows['South']));
        $this->assertSame(['6', '4 (67%)', '67%', 'Some'], $text($rows['Total']), 'the total: every value');
        $this->assertTrue($rows['Total']['subtotal']);
        $this->assertNotNull($rows['North']['cells'][0]['link'], 'a metric\'s cell opens its records');
        $this->assertStringContainsString('<th scope="col" style="text-align:right">New share</th>', $html, 'its own columns on the page');
        $this->assertStringContainsString('3 (60%)', $html);
        $this->assertStringContainsString("section,row,Sales,New,\"New share\",Verdict\n", $csv, 'and in the CSV');
        $this->assertContains('region', $report->dimensions(), 'its dimension offered as a filter');
    }

    /**
     * A formula row over filtered metrics (FW-473): each operand read with its own filters and the
     * column's, the formula worked out per column — never added into the section's total — and
     * a formula that names an operand it is not given refused.
     */
    public function testAFormulaRowOverFilteredMetrics(): void
    {
        // Arrange
        $report = Report::fromArray(['columns' => ['dimension' => 'region', 'values' => ['north', 'south'], 'total' => true], 'sections' => [['total' => true, 'rows' => [
            ['label' => 'Sales', 'metric' => 'test.sales'],
            ['label' => 'New, %', 'formula' => '100 * a / b', 'unit' => '%', 'operands' => [
                'a' => ['metric' => 'test.sales', 'filters' => ['customer' => 'new']],
                'b' => ['metric' => 'test.sales'],
            ]],
        ]]]]);
        try {
            Report::fromArray(['sections' => [['rows' => [['formula' => 'a / c', 'operands' => ['a' => ['metric' => 'test.sales']]]]]]]);
            $refused = null;
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }

        // Act
        $built = $report->build('2026-09-10', self::reader());
        $rows  = array_column($built['sections'][0]['rows'], null, 'label');

        // Assert
        $this->assertSame([60.0, 100.0, round(4 / 6 * 100, 10)], array_map(static fn (array $c): ?float => $c['value'] === null ? null : round($c['value'], 10), $rows['New, %']['cells']),
            'north 3 of 5, south 1 of 1, all 4 of 6');
        $this->assertSame([5.0, 1.0, 6.0], array_column($rows['Sales']['cells'], 'value'));
        $this->assertSame([5.0, 1.0, 6.0], $built['sections'][0]['total'], 'the formula is not added into the total');
        $this->assertSame('%', $rows['New, %']['unit']);
        $this->assertStringContainsString('c', (string) $refused, 'an operand it is not given');
    }

    /**
     * A table whose columns are a dimension's values shows only the value the page is filtered to
     * (FW-460) — `?v[region]=north` — and no total beside it; without the filter, every value and
     * the total. The page keeps the pinned filters in sight, the rest behind Filters with a count,
     * each chosen one a chip that takes it off, and its actions behind ⋯.
     */
    public function testAFilteredDimensionKeepsItsOwnColumns(): void
    {
        // Arrange
        $layout = [['id' => 'report.table~f1', 'settings' => ['rows' => [['metric' => 'test.sales']], 'columns' => 'dimension', 'dimension' => 'region', 'column_total' => '1']]];
        $id     = (new Reports())->save(self::reader(), ['name' => 'Test filtered', 'layout' => $layout]);
        $entry  = (new Reports())->find((string) $id, self::reader());
        $draw   = static function (array $v) use ($entry): string {
            $_GET['v'] = $v;
            try {
                return \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), '/rows', false);
            } finally {
                \Pramnos\Metrics\ReportBoard::leave();
                unset($_GET['v']);
            }
        };
        $page = ['entry' => $entry + ['id' => (string) $id], 'at' => '2026-09-10', 'period' => '2026-09', 'previous' => '2026-08-01', 'next' => '2026-10-01', 'body' => '',
            'mine' => true, 'customise' => false, 'variables' => ['region' => 'north', 'customer' => 'new'], 'pinned' => ['region'],
            'pickers' => ['region' => ['label' => 'Region', 'values' => ['north' => 'North', 'south' => 'South'], 'search' => false],
                'customer' => ['label' => 'Customer', 'values' => ['new' => 'New', 'returning' => 'Returning'], 'search' => false]],
            'urls' => ['show' => '/r/show', 'csv' => '/r/csv', 'xlsx' => '/r/xlsx', 'edit' => '/r/edit', 'list' => '/r']];

        $parts = Report::fromArray(['columns' => ['periods' => 1], 'sections' => [['rows' => [['metric' => 'test.sales', 'breakdown' => 'region']]]]]);
        $day   = new \ReflectionMethod(MetricReports::class, 'day');

        // Act
        $all      = $draw([]);
        $north    = $draw(['region' => 'north']);
        $grouped  = Report::html($parts->build('2026-09-01', self::reader()));
        $days     = array_map(static fn (string $given): string => $day->invoke(null, $given), ['2026-09-10', '2026-09', '2026-W37', '2026', 'nonsense']);
        $rendered = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $rendered[$theme] = $this->renderTemplate($theme, 'metricreports/report.tpl.php', $page);
        }

        // Assert
        $this->assertStringContainsString('>North</th><th scope="col" style="text-align:right">South</th><th scope="col" style="text-align:right">Total</th>', $all);
        $this->assertStringContainsString('>North</th>', $north);
        $this->assertStringNotContainsString('>South</th>', $north, 'only the chosen value');
        $this->assertStringNotContainsString('>Total</th>', $north, 'and no total beside one column');
        $this->assertMatchesRegularExpression('~<tr class="pf-report-group"><th scope="rowgroup" colspan="2">Sales</th></tr><tr class="pf-report-part"><th scope="row" style="padding-left:1.5em[^"]*">North</th>~', $grouped,
            'a breakdown\'s values under their row\'s name, set in');
        $this->assertStringContainsString('style="width:auto;min-width:50%"', $grouped, 'one column: the table as wide as it needs');
        $this->assertStringNotContainsString('width:auto', $all, 'three columns: the full width');
        $this->assertSame(['2026-09-10', '2026-09-01', '2026-09-07', '2026-01-01', date('Y-m-d')], $days, 'a day, a month, an ISO week, a year; anything else today');
        foreach ($rendered as $theme => $html) {
            $this->assertStringContainsString('<input type="month" name="at" value="2026-09"', $html, $theme . ': one period control, a month for a month');
            $this->assertStringContainsString('All values', $html, $theme . ': a neutral word for no filter');
            $this->assertStringContainsString('.pf-report .pf-report-part>th{padding-left:1.5em!important}', $html, $theme . ': the page\'s own cell padding does not undo a part\'s indent');
            $this->assertMatchesRegularExpression('~<label class="pf-report-field"><span>Region</span>\s*<select name="v\[region\]"~', $html, $theme . ': the pinned one in sight, labelled');
            $this->assertMatchesRegularExpression('~<details class="pf-report-pop pf-report-filters">\s*<summary[^>]*>Filters<span class="pf-report-count"><span>1</span>~', $html, $theme . ': the rest behind Filters, counted');
            $this->assertStringContainsString('<span>Customer</span>', $html, $theme . ': each labelled in the panel');
            $this->assertStringContainsString('href="/r/show?report=' . $id . '&amp;at=2026-09-10&amp;v%5Bcustomer%5D=new" aria-label="Remove the filter Region">Region: North', $html, $theme . ': a chip takes its filter off');
            $this->assertStringContainsString('aria-label="More actions"', $html, $theme . ': the actions behind ⋯');
            // FW-473: the panel a grid as wide as it needs, Clear keeping the pinned filters.
            $this->assertStringContainsString('grid-template-columns:repeat(auto-fill,minmax(12.5rem,1fr))', $html, $theme . ': a grid, not a column');
            $this->assertStringContainsString('href="/r/show?report=' . $id . '&amp;at=2026-09-10&amp;v%5Bregion%5D=north">Clear</a>', $html, $theme . ': Clear keeps the pinned');
            $this->assertStringContainsString('Export CSV', $html, $theme);
        }
    }

    /**
     * The cards a report gains: the top values of a breakdown, ranked with bars; a goal — which
     * says so where there is no curve to judge by, or no target; and a cohort grid, each period's
     * size and the share that reached the event within each span.
     */
    public function testTheTopAGoalAndACohortGrid(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.cohort', 'Paid within')->aggregate(Metric::DISTINCT)->dimensions(['within'])->keeps([['within']])
            ->source(static fn (Window $w, array $by): array|int => $by === [] ? 10 : [['within' => '7', 'value' => 3], ['within' => '30', 'value' => 6]]));
        Metrics::compute(['test.cohort'], ['month'], '2026-08-01', '2026-09-30', false, (int) strtotime('2026-11-06 12:00 UTC'));
        $layout = [
            ['id' => 'report.top~a', 'settings' => ['metric' => 'test.sales', 'dimension' => 'region', 'count' => '5']],
            ['id' => 'report.goal~b', 'settings' => ['metric' => 'test.sales', 'target' => 10]],
            ['id' => 'report.goal~c', 'settings' => ['metric' => 'test.sales']],
            ['id' => 'report.cohorts~d', 'settings' => ['metric' => 'test.cohort', 'periods' => 2]],
            ['id' => 'report.top~e', 'settings' => ['metric' => 'test.sales']],
        ];
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test new cards', 'layout' => $layout]);
        $entry = (new Reports())->find((string) $id, self::reader());

        // Act
        $html = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), null, false);
        \Pramnos\Metrics\ReportBoard::leave();

        // Assert
        $this->assertMatchesRegularExpression('~<th scope="row">North</th>.*?width:100%.*?>5</td>.*?<th scope="row">South</th>.*?width:20%~s', $html, 'north first, its bar the longest; south a fifth of it');
        $this->assertStringContainsString('No curve to judge by', $html, 'last year\'s days were never computed');
        $this->assertStringContainsString('Choose a metric and a target', $html);
        $this->assertStringContainsString('<th scope="col" style="text-align:right">7 days</th><th scope="col" style="text-align:right">30 days</th>', $html);
        $this->assertStringContainsString('<td style="text-align:right">10</td><td style="text-align:right">30.0%</td><td style="text-align:right">60.0%</td>', $html);
        $this->assertStringContainsString('Choose a metric and what to rank by', $html);
    }

    /**
     * A metric on a map: each value of a dimension with places a point, at its place, with its
     * figure — a value with no place left out — for the page to draw, and the figures as a table;
     * a dimension without places, or no metric, says what to choose. A dimension says what it is
     * a part of.
     */
    public function testAMetricOnAMap(): void
    {
        // Arrange
        Metrics::dimension(Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South'])->parent('country')
            ->geo(static fn (string $value): ?array => ['north' => [40.64, 22.94], 'south' => null][$value] ?? null));
        $layout = [
            ['id' => 'report.map~a', 'settings' => ['metric' => 'test.sales', 'dimension' => 'region']],
            ['id' => 'report.map~b', 'settings' => ['metric' => 'test.sales', 'dimension' => 'customer']],
        ];
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test map', 'layout' => $layout]);
        $entry = (new Reports())->find((string) $id, self::reader());

        // Act
        $html = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), null, false);
        \Pramnos\Metrics\ReportBoard::leave();

        // Assert
        $this->assertStringContainsString('data-pf-map="[{&quot;lat&quot;:40.64,&quot;lng&quot;:22.94,&quot;label&quot;:&quot;North&quot;,&quot;value&quot;:5', $html, 'north at its place, its figure');
        $this->assertStringNotContainsString('&quot;label&quot;:&quot;South&quot;', $html, 'south has no place');
        $this->assertStringContainsString('<th scope="row">North</th><td style="text-align:right">5</td>', $html, 'the figures as a table');
        $this->assertStringContainsString('Choose a metric and a dimension with places', $html);
        $this->assertSame('country', Metrics::dimensionOf('region')->getParent());
        $this->assertNull(Metrics::dimensionOf('customer')->geoOf('new'));
    }

    /**
     * The board's variables, from the address: a region chosen narrows every card — a figure, a
     * table, the top values — while a card's own filter on the same dimension keeps its own; what
     * is not a declared dimension is ignored.
     */
    public function testTheBoardsVariablesNarrowEveryCard(): void
    {
        // Arrange
        $layout = [
            ['id' => 'report.kpi~a', 'size' => 's', 'settings' => ['metric' => 'test.sales', 'compare' => 'none']],
            ['id' => 'report.table~b', 'settings' => ['rows' => [['metric' => 'test.sales', 'label' => 'All sales']]]],
            ['id' => 'report.kpi~c', 'size' => 's', 'settings' => ['metric' => 'test.sales', 'compare' => 'none', 'filters' => ['region' => 'south'], 'title' => 'South']],
        ];
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test variables', 'layout' => $layout]);
        $entry = (new Reports())->find((string) $id, self::reader());
        $_GET['v'] = ['region' => 'north', 'nothing' => 'x'];

        try {
            // Act
            $variables = \Pramnos\Metrics\ReportBoard::variables();
            $html      = \Pramnos\Metrics\ReportBoard::html($entry, '2026-09-10', self::reader(), null, false);
            \Pramnos\Metrics\ReportBoard::leave();
        } finally {
            unset($_GET['v']);
        }

        // Assert
        $this->assertSame(['filters' => ['region' => 'north'], 'tenant' => null], $variables, 'a declared dimension only');
        $this->assertMatchesRegularExpression('~Sales</[^>]+>\s*<[^>]+>5<~', $html, 'the figure: north only (5 of 6)');
        $this->assertStringContainsString('<th scope="row">All sales</th><td style="text-align:right">5</td>', $html, 'the table too');
        $this->assertMatchesRegularExpression('~South</[^>]+>\s*<[^>]+>1<~', $html, 'a card\'s own filter keeps its own');
    }

    /**
     * The assistant's reading of a report: written from the report's numbers when it is drawn
     * for sending — the numbers in what it is asked — and kept for the period, so a second send
     * and the page do not ask again; a period not sent yet says when it is written; an answer the
     * model could not give leaves the note, not an error.
     */
    public function testTheAssistantsReadingOfAReport(): void
    {
        // Arrange
        $cache = new \Pramnos\Cache\Cache();
        $cache->caching = true;
        (new \ReflectionProperty(\Pramnos\Cache\Cache::class, 'adapter'))->setValue($cache, new \Pramnos\Cache\Adapter\ArrayAdapter());
        \Pramnos\Metrics\Widgets\AnalysisWidget::useCache($cache);
        $asked  = [];
        $answer = ['ok' => true, 'reply' => 'Sales **rose** sixfold.'];
        \Pramnos\Metrics\Widgets\AnalysisWidget::useClient(static function () use (&$asked, &$answer): \Pramnos\Llm\Client {
            return new class ($asked, $answer) extends \Pramnos\Llm\Client {
                public function __construct(private array &$asked, private array &$answer)
                {
                }

                public function askCheaply(string $systemPrompt, array $messages): array
                {
                    $this->asked[] = (string) $messages[0]['content'];

                    return $this->answer;
                }
            };
        });
        $layout = [
            ['id' => 'report.table~a', 'settings' => ['rows' => [['metric' => 'test.sales', 'label' => 'Sales']]]],
            ['id' => 'report.analysis~b', 'settings' => ['instructions' => 'The regions']],
        ];
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test analysis', 'layout' => $layout]);
        $entry = (new Reports())->find((string) $id, self::reader());
        $draw  = static function (string $at, bool $static) use ($entry): string {
            $html = \Pramnos\Metrics\ReportBoard::html($entry, $at, self::reader(), null, $static);
            \Pramnos\Metrics\ReportBoard::leave();

            return $html;
        };

        try {
            // Act
            $sent   = $draw('2026-09-10', true);
            $again  = $draw('2026-09-10', true);
            $page   = $draw('2026-09-10', false);
            $notYet = $draw('2026-08-10', false);
            $answer = ['ok' => false, 'reply' => ''];
            $failed = $draw('2026-07-10', true);

            // Assert
            $this->assertStringContainsString('Sales <strong>rose</strong> sixfold.', $sent);
            $this->assertCount(2, $asked, 'once for September — not again — and once for July');
            $this->assertStringContainsString('Look at: The regions', $asked[0]);
            $this->assertStringContainsString("Sales,6", $asked[0], 'the report\'s own numbers');
            $this->assertStringContainsString('rose', $again);
            $this->assertStringContainsString('rose', $page, 'the page shows what was written');
            $this->assertStringContainsString('writes its reading when the report is sent', $notYet);
            $this->assertStringContainsString('writes its reading when the report is sent', $failed, 'no answer: the note');
        } finally {
            \Pramnos\Metrics\Widgets\AnalysisWidget::useClient(null);
            \Pramnos\Metrics\Widgets\AnalysisWidget::useCache(null);
        }
    }

    /**
     * A viewer scoped to one region (`metrics.scope`) reads only that region, everywhere a figure
     * is read: a total is the region's total, a breakdown has only its key, a filter on another
     * region is refused, records are listed for it, a metric with no region is not theirs unless
     * it is declared nobody's, a goal for every region is not theirs, and the report's pickers
     * offer nothing to choose. A dimension behind an ability is not broken down by somebody
     * without it, though its totals stay theirs. Somebody unscoped reads everything as before.
     */
    public function testAScopedViewerReadsOnlyTheirRegion(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['metrics']['scope'] = static fn (?object $u): ?array => match ((int) ($u->userid ?? 0)) {
            5 => ['region' => ['north']], 6 => ['region' => ['north', 'south']], default => null };
        Metrics::register(Metric::flow('test.visits', 'Visits')->source(static fn (): int => 40),
            Metric::flow('test.site', 'Site')->unscoped()->source(static fn (): int => 7));
        Metrics::dimension(Dimension::of('customer', 'Customer')->ability('reports.customers')->values(['new' => 'New', 'returning' => 'Returning']));
        $north = self::reader(90, 5);
        $query = static fn (?object $u): \Pramnos\Metrics\Query => Metrics::query('test.sales')->grain('month')->at('2026-09-10')->for($u);
        $refused = [];
        foreach (['south' => static fn () => $query($north)->where(['region' => 'south'])->value(),
            'customers' => static fn () => $query($north)->by('customer')->series(),
            'visits' => static fn () => Metrics::query('test.visits')->for($north)] as $why => $read) {
            try {
                $read();
            } catch (\DomainException $e) {
                $refused[$why] = $e->getMessage();
            }
        }

        // Act
        $total   = $query($north)->value();
        $byArea  = array_keys($query($north)->by('region')->series()['values']);
        $all     = $query(self::reader())->value();
        $records = Metrics::scoped('test.sales', [], $north, null, true);
        $visible = array_keys(Metrics::all($north, false));
        $this->signIn(98, 5);
        $id = (new Reports())->save($north, ['name' => 'Test north', 'sections' => [['title' => 'S', 'rows' => [['metric' => 'test.sales']]]]]);
        $screen = $this->screen(MetricReports::class);
        $this->request('GET', null, ['report' => (string) $id, 'at' => '2026-09-01']);
        $screen->show();
        $page = (array) $screen->view;
        $screen->flash = [];
        $this->request('GET', null, ['metric' => 'test.sales', 'grain' => 'month', 'period' => '2026-09-01', 'f' => ['region' => 'south']]);
        $screen->rows();
        $twoRegions = null;
        try {
            Metrics::scoped('test.sales', [], self::reader(90, 6), null, true);
        } catch (\DomainException $e) {
            $twoRegions = $e->getMessage();
        }

        // Assert
        $this->assertSame([['error', 'Those figures are not yours to read.']], $screen->flash, 'another region\'s records');
        $this->assertSame('Choose one Region to list its records.', $twoRegions, 'records are one region at a time');
        $this->assertFalse(Metrics::withinScope('test.sales', ['region' => 'south'], $north));
        $this->assertSame(5.0, $total, 'north\'s total, not the whole 6');
        $this->assertSame(6.0, $all, 'unscoped: the whole');
        $this->assertSame(['north'], $byArea);
        $this->assertSame(['south', 'customers', 'visits'], array_keys($refused));
        $this->assertSame('Those figures are not yours to read.', $refused['south']);
        $this->assertSame('You may not break down or filter by Customer.', $refused['customers'], 'an ability the viewer lacks');
        $this->assertSame(['region' => 'north'], $records, 'the records of their region');
        $this->assertContains('test.site', $visible, 'nobody\'s metric: whole for everybody');
        $this->assertNotContains('test.visits', $visible, 'no region to narrow it: not theirs');
        $this->assertFalse(Metrics::withinScope('test.sales', [], $north), 'a goal for every region');
        $this->assertTrue(Metrics::withinScope('test.sales', ['region' => 'north'], $north));
        $this->assertArrayNotHasKey('region', $page['pickers'], 'one region: nothing to choose');
        $this->assertMatchesRegularExpression('~<th scope="row">Sales</th><td[^>]*>(<a [^>]*>)?5<~', $page['body'], 'the report is north\'s: 5, not 6');
    }

    /**
     * A report built from words: the assistant is given the catalogue the person may read, and
     * its answer — the editor's text — is kept only where it names what they may read: a secret
     * metric, an unknown one, a breakdown or filter on a dimension the metric does not keep, all
     * left out; columns by a dimension no row keeps fall back to one period. The editor shows the
     * report filled in with its numbers, unsaved; an answer with nothing usable says so.
     */
    public function testAReportIsBuiltFromWords(): void
    {
        // Arrange
        $this->signIn(98, 2);
        $asked = [];
        $reply = json_encode(['name' => 'Πωλήσεις ανά περιοχή', 'grain' => 'month', 'compare' => 'year', 'columns' => 'dimension=region north,south total',
            'sections' => "# Sales [chart=bar]\nAll | test.sales\nBy region | test.sales | | by=region total\nSecret | test.secret\nGhost | test.ghost\n"
                . "Buyers by customer | test.buyers | | by=customer\n# Only secrets\nS | test.secret"]);
        $answer = ['ok' => true, 'reply' => "Here it is:\n" . $reply];
        \Pramnos\Metrics\ReportProposal::useClient(static function () use (&$asked, &$answer): \Pramnos\Llm\Client {
            return new class ($asked, $answer) extends \Pramnos\Llm\Client {
                public function __construct(private array &$asked, private array &$answer)
                {
                }

                public function askCheaply(string $systemPrompt, array $messages): array
                {
                    $this->asked[] = (string) $messages[0]['content'];

                    return $this->answer;
                }
            };
        });

        try {
            // Act
            $report  = \Pramnos\Metrics\ReportProposal::fromWords('sales by region against last year', self::reader());
            $screen  = $this->screen(MetricReports::class);
            $this->request('GET', null, ['words' => 'sales by region']);
            $screen->edit();
            $form    = (array) $screen->view;
            $answer  = ['ok' => true, 'reply' => json_encode(['name' => 'X', 'sections' => 'S | test.secret'])];
            $refused = null;
            try {
                \Pramnos\Metrics\ReportProposal::fromWords('the secret', self::reader());
            } catch (\InvalidArgumentException $e) {
                $refused = $e->getMessage();
            }
            $answer = ['ok' => true, 'reply' => 'I cannot.'];
            $screen->flash = [];
            $this->request('GET', null, ['words' => 'anything']);
            $screen->edit();

            // Assert
            $this->assertStringContainsString('"metric":"test.sales"', $asked[0], 'the catalogue');
            $this->assertStringNotContainsString('test.secret', $asked[0], 'only what the person may read');
            $this->assertStringContainsString('Request: sales by region against last year', $asked[0]);
            $this->assertSame('Πωλήσεις ανά περιοχή', $report->definition['name']);
            $this->assertSame('year', $report->definition['compare']);
            $this->assertSame("# Sales [chart=bar]\nAll | test.sales\nBy region | test.sales |  | by=region total\n", $report->sectionsAsText(), 'secret, unknown and a dimension not kept: left out');
            $this->assertSame("dimension=region north,south total\n", $report->columnsAsText());
            $this->assertSame('Πωλήσεις ανά περιοχή', $form['reportName'], 'the form filled in');
            $this->assertSame(0, $form['id'], 'nothing saved');
            $this->assertStringContainsString('<th scope="row">All</th>', $form['preview'], 'with its numbers');
            $this->assertSame('The assistant found nothing you may read for that.', $refused);
            $this->assertSame([['error', 'The assistant could not build a report from that.']], $screen->flash);
            $this->assertSame([], (new Reports())->visibleTo(self::reader()), 'nothing saved');
        } finally {
            \Pramnos\Metrics\ReportProposal::useClient(null);
        }
    }

    /**
     * "Ask about this": a card that reads a metric — a figure, a table by its first row — asks the
     * assistant about it with its context (the metric, its period, the board's variable); a card
     * that reads none asks nothing; the card's page links to Ask with the question to send.
     */
    public function testACardAsksTheAssistantAboutWhatItShows(): void
    {
        // Arrange
        $id    = (new Reports())->save(self::reader(), ['name' => 'Test ask', 'layout' => []]);
        $entry = (new Reports())->find((string) $id, self::reader());
        $_GET['v'] = ['region' => 'north'];
        \Pramnos\Metrics\ReportBoard::enter($entry, '2026-09-10', self::reader());
        unset($_GET['v']);

        try {
            // Act
            $kpi   = (new \Pramnos\Metrics\Widgets\KpiWidget())->configure(['metric' => 'test.sales'])->askAbout();
            $table = (new \Pramnos\Metrics\Widgets\TableWidget())->configure(['rows' => [['metric' => 'test.buyers']]])->askAbout();
            $text  = (new \Pramnos\Metrics\Widgets\TextWidget())->configure(['text' => 'x'])->askAbout();
            $page  = $this->renderTemplate('bootstrap', 'dashboard/card.tpl.php', ['widget' => ['id' => 'report.kpi~a', 'size' => 's', 'title' => 'Sales', 'link' => null,
                'settings' => [], 'fields' => [], 'html' => '1', 'ask' => '/admin/Ai?q=About%20sales']]);
        } finally {
            \Pramnos\Metrics\ReportBoard::leave();
        }

        // Assert
        $this->assertSame('About Sales (test.sales) in 2026-09, region = north: what explains it, and what moved most?', $kpi);
        $this->assertStringContainsString('Buyers (test.buyers)', (string) $table, 'a table by its first row');
        $this->assertNull($text, 'words read no metric');
        $this->assertStringContainsString('href="/admin/Ai?q=About%20sales"', $page);
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
