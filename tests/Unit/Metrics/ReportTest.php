<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Metrics\Report;

/**
 * A report's definition without a database: what it accepts and cleans, and the text the report
 * screen edits it as — read and written back the same.
 */
#[CoversClass(Report::class)]
class ReportTest extends TestCase
{
    /**
     * The definition is cleaned: an unknown grain or comparison is refused; columns are a
     * shorthand kept as such, or specs with an offset never in the future; rows without a metric
     * are dropped; a breakdown's total only with a breakdown; a chart only of a kind it draws.
     */
    public function testTheDefinitionIsCleaned(): void
    {
        // Arrange
        $refused = 0;
        foreach ([['grain' => 'hour'], ['compare' => 'last']] as $bad) {
            try {
                Report::fromArray($bad);
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }

        // Act
        $specs = Report::fromArray(['columns' => [['label' => 'A', 'offset' => 3, 'filters' => ['region' => ['north', 'south']]], ['metric' => 'x.y', 'offset' => -2]], 'compare' => '', 'sections' => [
            ['title' => ' Sales ', 'chart' => 'pie', 'rows' => [['metric' => ''], ['metric' => 'orders.count', 'total' => true], ['metric' => 'orders.count', 'breakdown' => 'plan', 'total' => 1]]],
        ]])->definition;
        $periods   = Report::fromArray(['columns' => ['periods' => 500]])->definition['columns'];
        $dimension = Report::fromArray(['columns' => ['dimension' => 'region']])->definition['columns'];
        $default   = Report::fromArray([])->definition;

        // Assert
        $this->assertSame(2, $refused);
        $this->assertSame([
            ['label' => 'A', 'filters' => ['region' => ['north', 'south']], 'metric' => null, 'offset' => 0],
            ['label' => '', 'filters' => [], 'metric' => 'x.y', 'offset' => -2],
        ], $specs['columns'], 'an offset is never ahead of the report');
        $this->assertNull($specs['compare']);
        $this->assertSame('Sales', $specs['sections'][0]['title']);
        $this->assertNull($specs['sections'][0]['chart'], 'a pie is not a report chart');
        $this->assertCount(2, $specs['sections'][0]['rows'], 'the row without a metric is dropped');
        $this->assertFalse($specs['sections'][0]['rows'][0]['total'], 'a total needs a breakdown');
        $this->assertTrue($specs['sections'][0]['rows'][1]['total']);
        $this->assertSame(['periods' => Report::MAX_COLUMNS], $periods);
        $this->assertSame(['dimension' => 'region', 'values' => [], 'total' => true], $dimension);
        $this->assertSame(['periods' => 1], $default['columns']);
        $this->assertSame('month', $default['grain']);
    }

    /**
     * Sections as text: a row before any heading gets a section of its own; a row of one word is
     * its metric; filters with several values; a breakdown and its total — and written back the
     * same, so editing a report twice does not change it.
     */
    public function testSectionsAsText(): void
    {
        // Arrange
        $text = "orders.count\n\n# Sales [chart=bar] [total]\nNorth | orders.count | region=north, plan=basic;pro\nBy plan | orders.count | | by=plan total\n# Plain\nVisits | visits.count\n";

        // Act
        $sections = Report::sectionsFromText($text);
        $again    = Report::fromArray(['sections' => $sections])->sectionsAsText();

        // Assert
        $this->assertSame('', $sections[0]['title']);
        $this->assertSame(['label' => '', 'metric' => 'orders.count', 'filters' => [], 'breakdown' => null, 'total' => false], $sections[0]['rows'][0]);
        $this->assertSame(['title' => 'Sales', 'chart' => 'bar', 'total' => true], array_intersect_key($sections[1], ['title' => 1, 'chart' => 1, 'total' => 1]));
        $this->assertSame(['region' => 'north', 'plan' => ['basic', 'pro']], $sections[1]['rows'][0]['filters']);
        $this->assertSame(['plan', true], [$sections[1]['rows'][1]['breakdown'], $sections[1]['rows'][1]['total']]);
        $this->assertSame($sections, Report::sectionsFromText($again), 'written back, read the same');
        $this->assertStringContainsString("# Sales [chart=bar] [total]\nNorth | orders.count | region=north, plan=basic;pro", $again);
    }

    /** Columns as text: empty, the two shorthands, specs — each written back the same. */
    public function testColumnsAsText(): void
    {
        // Arrange
        $cases = [
            ''                                      => ['periods' => 1],
            "periods=12\n"                          => ['periods' => 12],
            "dimension=region north,south total\n"  => ['dimension' => 'region', 'values' => ['north', 'south'], 'total' => true],
            "dimension=region\n"                    => ['dimension' => 'region', 'values' => [], 'total' => false],
            "All\nNorth | region=north\nLast year | | orders.count | -12\n" => [
                ['label' => 'All', 'filters' => [], 'metric' => '', 'offset' => 0],
                ['label' => 'North', 'filters' => ['region' => 'north'], 'metric' => '', 'offset' => 0],
                ['label' => 'Last year', 'filters' => [], 'metric' => 'orders.count', 'offset' => -12],
            ],
        ];

        foreach ($cases as $text => $expected) {
            // Act
            $columns = Report::columnsFromText($text);
            $again   = Report::fromArray(['columns' => $columns])->columnsAsText();

            // Assert
            $this->assertSame($expected, $columns, $text);
            $this->assertSame(Report::fromArray(['columns' => $columns])->definition['columns'], Report::fromArray(['columns' => Report::columnsFromText($again)])->definition['columns'], 'written back: ' . $text);
        }
    }
}
