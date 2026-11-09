<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Metrics\ChartImage;
use Pramnos\Metrics\Report;
use Pramnos\Metrics\ReportBoard;
use Pramnos\Metrics\Widgets\ReportWidget;
use Pramnos\Metrics\Widgets\TableWidget;

/**
 * The parts of a report's cards that need no database: the chart drawn as SVG and made a PNG, the
 * fields that keep a structure, how settings are cleaned, the board's name, and the report's
 * catalogue.
 */
#[CoversClass(ChartImage::class)]
#[CoversClass(ReportBoard::class)]
#[CoversClass(ReportWidget::class)]
#[CoversClass(TableWidget::class)]
#[CoversClass(Widget::class)]
class ReportCardsTest extends TestCase
{
    protected function tearDown(): void
    {
        ChartImage::convertWith(null);
        ReportBoard::leave();
        WidgetRegistry::reset();
    }

    /**
     * Lines are a polyline per series, bars a rect per value; the axis starts at a round number
     * below zero for a negative value; words are escaped as XML; a missing value leaves a gap.
     */
    public function testAChartIsDrawnAsSvg(): void
    {
        // Arrange
        $sets = [['label' => 'Sales <a>', 'data' => [1.0, null, 1200.0]], ['label' => 'Refunds', 'data' => [-3.0, 0.0, 2.0]]];

        // Act
        $line = ChartImage::svg('line', ['Jan', 'Feb', 'Mar'], $sets, 'Τάση & πωλήσεις');
        $bar  = ChartImage::svg('bar', ['Jan', 'Feb', 'Mar'], $sets);

        // Assert
        $this->assertSame(2, substr_count($line, '<polyline'), 'a line per series');
        $this->assertSame(2, substr_count(explode('points="', $line)[1], ','), 'the gap: two points of three');
        $this->assertStringContainsString('Τάση &amp; πωλήσεις', $line);
        $this->assertStringContainsString('Sales &lt;a&gt;', $line);
        $this->assertSame(5 + 2, substr_count($bar, '<rect x="'), 'five bars, and two legend keys');
        $this->assertStringContainsString('>-500<', $bar, 'the axis reaches below zero, in round steps');
        $this->assertNotFalse(simplexml_load_string($line), 'well-formed');
    }

    /**
     * A PNG comes from the converter set, and none when it gives none or throws — the caller then
     * shows the numbers.
     */
    public function testAPngComesFromTheConverterOrNot(): void
    {
        // Arrange
        $sets = [['label' => 'A', 'data' => [1.0, 2.0]]];

        // Act
        ChartImage::convertWith(static fn (string $svg): string => 'PNG:' . strlen($svg));
        $made = ChartImage::png('line', ['a', 'b'], $sets);
        ChartImage::convertWith(static fn (): ?string => throw new \RuntimeException('no'));
        $failed = ChartImage::png('line', ['a', 'b'], $sets);

        // Assert
        $this->assertStringStartsWith('PNG:', (string) $made);
        $this->assertNull($failed);
    }

    /**
     * A list or filters field carries what its editor is drawn from; a textarea is a field of its
     * own; an unknown input is text.
     */
    public function testStructuredFieldsCarryWhatTheyAreDrawnFrom(): void
    {
        // Arrange
        $clean = static fn (mixed $v): mixed => $v;

        // Act
        $list  = Widget::field(['input' => 'list', 'fields' => ['a' => ['input' => 'text']], 'clean' => $clean]);
        $text  = Widget::field(['input' => 'textarea', 'clean' => $clean]);
        $other = Widget::field(['input' => 'colour', 'clean' => $clean]);

        // Assert
        $this->assertSame('list', $list['input']);
        $this->assertSame(['fields' => ['a' => ['input' => 'text']]], $list['extra']);
        $this->assertSame('textarea', $text['input']);
        $this->assertSame('text', $other['input']);
    }

    /**
     * Filters are kept from the editor's object, its JSON, or a list of pairs — a dimension's name
     * a word, a value not empty; rows keep only those with a metric, their breakdown a word.
     */
    public function testSettingsAreCleaned(): void
    {
        // Arrange
        $table = new TableWidget();

        // Act
        $object = ReportWidget::cleanFilters(['region' => 'north', 'Bad Name' => 'x', 'plan' => '']);
        $json   = ReportWidget::cleanFilters('{"region":"south"}');
        $pairs  = ReportWidget::cleanFilters([['dimension' => 'region', 'value' => 'west']]);
        $rows   = Widget::cleanSettings($table, ['rows' => '[{"metric":"orders.count","breakdown":"plan","total":true,"filters":{"region":"n"}},{"label":"none"},{"metric":"x; drop","label":"bad"}]', 'columns' => 'nope']);

        // Assert
        $this->assertSame(['region' => 'north'], $object);
        $this->assertSame(['region' => 'south'], $json);
        $this->assertSame(['region' => 'west'], $pairs);
        $this->assertSame([['label' => '', 'metric' => 'orders.count', 'filters' => ['region' => 'n'], 'breakdown' => 'plan', 'total' => true]], $rows['rows']);
        $this->assertSame('report', $rows['columns'], 'an unknown choice is the first');
    }

    /**
     * A report's board is `report:<id>`; its catalogue is the report cards alone, each repeatable,
     * offered on no other board; outside a report a card reads this month.
     */
    public function testAReportsBoardAndItsCatalogue(): void
    {
        // Act
        $ids   = array_keys(WidgetRegistry::all('report:5'));
        $admin = array_keys(WidgetRegistry::all('admin'));

        // Assert
        $this->assertTrue(ReportBoard::isReportBoard('report:app:monthly'));
        $this->assertFalse(ReportBoard::isReportBoard('report:'));
        $this->assertSame('app:monthly', ReportBoard::reportId(ReportBoard::board('app:monthly')));
        $this->assertSame(['report.section', 'report.table', 'report.chart', 'report.kpi', 'report.top', 'report.goal', 'report.cohorts', 'report.text', 'report.rows'], $ids);
        $this->assertSame([], array_intersect($ids, $admin), 'not on a dashboard');
        $this->assertSame('month', ReportBoard::context()['grain']);
        $this->assertNotNull(WidgetRegistry::forCard(WidgetRegistry::all('report:5'), 'report.table~abc'));
    }

    /**
     * A definition keeps its cards; without them its sections are read as cards — a heading, a
     * chart where one was asked for, the table.
     */
    public function testSectionsAreReadAsCards(): void
    {
        // Arrange
        $old  = Report::fromArray(['sections' => [['title' => '', 'rows' => [['metric' => 'a']]], ['title' => 'B', 'chart' => 'line', 'total' => true, 'rows' => [['metric' => 'b', 'breakdown' => 'plan']]]]]);
        $new  = Report::fromArray(['layout' => [['id' => 'report.text~a'], 'junk']]);

        // Act
        $cards = ReportBoard::layout($old);

        // Assert
        $this->assertSame(['report.table~t0', 'report.section~s1', 'report.chart~c1', 'report.table~t1'], array_column($cards, 'id'));
        $this->assertSame('1', $cards[3]['settings']['total']);
        $this->assertSame('plan', $cards[3]['settings']['rows'][0]['breakdown']);
        $this->assertSame('line', $cards[2]['settings']['chart']);
        $this->assertSame([['id' => 'report.text~a']], ReportBoard::layout($new));
    }
}
