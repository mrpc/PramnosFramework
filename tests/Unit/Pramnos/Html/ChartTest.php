<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Chart;

/**
 * The markup half of a chart: what `pf-utils.js` reads to draw it, and the table that stands in
 * for it.
 *
 * The drawing is tested in tests/js/pf-chart.test.js. Here the contract is the markup: the data in
 * a `<script type="application/json">` and nowhere in an inline script, a JSON a label cannot break
 * out of, the same numbers in a table, and Chart.js on the page when the document has it.
 */
#[CoversClass(Chart::class)]
class ChartTest extends TestCase
{
    /**
     * The figure carries what the script reads — the hook, the hidden canvas box, the JSON — and
     * no script a CSP without 'unsafe-inline' would block.
     */
    public function testTheFigureCarriesItsDataAsJson(): void
    {
        // Act
        $html = Chart::render('bar', ['Jan', 'Feb'], [
            ['label' => 'Revenue', 'data' => [1200, '1850.5']],
            ['label' => 'Target', 'data' => [1500, 1500], 'color' => 'warning', 'type' => 'line'],
        ], ['title' => 'Revenue by month', 'height' => 240, 'stacked' => true, 'id' => 'rev']);
        $config = self::config($html);

        // Assert — the hooks pf-utils.js looks up by name
        $this->assertStringContainsString('<figure class="pf-chart-figure" data-pf-chart-figure id="rev">', $html);
        $this->assertStringContainsString('<div class="pf-chart-canvas" hidden><canvas aria-hidden="true"></canvas></div>', $html);
        $this->assertStringContainsString('<figcaption class="pf-chart-title">Revenue by month</figcaption>', $html);
        $this->assertSame(0, preg_match('/<script(?![^>]*application\/json)/', $html), 'no executable script');
        // The JSON, with numbers as numbers and the series' own colour and type
        $this->assertSame('bar', $config['type']);
        $this->assertSame(['Jan', 'Feb'], $config['labels']);
        // Equal, not identical: a whole float leaves json_encode() as an integer
        $this->assertEquals([1200, 1850.5], $config['datasets'][0]['data']);
        $this->assertEquals(['label' => 'Target', 'data' => [1500, 1500], 'color' => 'warning', 'type' => 'line'], $config['datasets'][1]);
        $this->assertSame(['decimals' => 2], $config['format']);
        $this->assertTrue($config['stacked']);
        $this->assertSame(240, $config['height']);
    }

    /**
     * A label holding `</script>` cannot end the JSON element early: the markup a chart of
     * user-entered names would otherwise let through.
     */
    public function testALabelCannotCloseTheScript(): void
    {
        // Act
        $html = Chart::render('pie', ['</script><script>alert(1)</script>'], [['label' => 'x', 'data' => [1]]]);

        // Assert
        $this->assertSame(1, substr_count($html, '</script>'), 'only the element\'s own end tag');
        $this->assertSame('</script><script>alert(1)</script>', self::config($html)['labels'][0]);
        $this->assertStringContainsString('&lt;/script&gt;', $html, 'escaped in the table too');
    }

    /**
     * The table holds the same numbers, a row per label and a column per series, in the chosen
     * format — what a screen reader reads and a page without Chart.js shows. A missing value is
     * an empty cell.
     */
    public function testTheTableHoldsTheSameNumbers(): void
    {
        // Act
        $money   = Chart::render('line', ['Q1', 'Q2'], [['label' => 'Sales', 'data' => [1234.5, null]]], ['format' => 'currency', 'currency' => 'usd', 'title' => 'Sales']);
        $percent = Chart::render('bar', ['A'], [['label' => 'Share', 'data' => [0.125]]], ['format' => 'percent', 'decimals' => 1]);
        $whole   = Chart::render('bar', ['A'], [['label' => 'Count', 'data' => [50]]], ['decimals' => 0]);

        // Assert
        $this->assertStringContainsString('<caption>Sales</caption>', $money);
        $this->assertStringContainsString('<thead><tr><td></td><th scope="col">Sales</th></tr></thead>', $money);
        $this->assertStringContainsString('<tr><th scope="row">Q1</th><td>1,234.5 USD</td></tr>', $money);
        $this->assertStringContainsString('<tr><th scope="row">Q2</th><td></td></tr>', $money);
        $this->assertSame(['style' => 'currency', 'currency' => 'USD', 'decimals' => 2], self::config($money)['format']);
        $this->assertStringContainsString('<td>12.5%</td>', $percent);
        // No decimals: 50 stays 50 — trailing zeros are trimmed only after a decimal point
        $this->assertStringContainsString('<td>50</td>', $whole);
    }

    /** A type or a format Chart.js would not draw is refused, naming the ones that are. */
    public function testAnUnknownTypeOrFormatIsRefused(): void
    {
        // Act & Assert
        try {
            Chart::render('scatter3d', [], []);
            $this->fail('an unknown type was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('line, bar, pie', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('number, currency, percent');
        Chart::render('bar', [], [], ['format' => 'roman']);
    }

    /** The page's own Chart.js options travel in the JSON, to be merged over the drawn ones. */
    public function testThePagesOwnOptionsTravel(): void
    {
        // Act
        $config = self::config(Chart::render('bar', ['A'], [['label' => 'x', 'data' => [1]]], ['chart' => ['indexAxis' => 'y']]));
        $none   = Chart::render('bar', ['A'], [['label' => 'x', 'data' => [1]]]);

        // Assert
        $this->assertSame(['indexAxis' => 'y'], $config['options']);
        $this->assertStringContainsString('"options":{}', $none, 'an object, never a list, so the script can merge it');
    }

    /** Rendering puts Chart.js on the page when the document knows it, and asks for nothing when not. */
    public function testChartJsIsEnqueuedWhereRegistered(): void
    {
        // Arrange
        $doc   = \Pramnos\Framework\Factory::getDocument('html');
        $queue = new \ReflectionProperty(\Pramnos\Document\Document::class, '_queue');
        $before = $queue->getValue($doc);
        $queue->setValue($doc, []);

        try {
            // Act — unregistered, then registered
            Chart::render('bar', ['A'], [['label' => 'x', 'data' => [1]]]);
            $without = $queue->getValue($doc)['js'] ?? [];
            $doc->registerScript('chartjs', '/assets/vendor/chartjs/chart.umd.min.js');
            Chart::render('bar', ['A'], [['label' => 'x', 'data' => [1]]]);
            $with = $queue->getValue($doc)['js'] ?? [];

            // Assert
            $this->assertArrayNotHasKey('chartjs', $without);
            $this->assertArrayHasKey('chartjs', $with);
        } finally {
            $queue->setValue($doc, $before);
        }
    }

    /**
     * The JSON in a rendered figure.
     *
     * @return array<string, mixed>
     */
    private static function config(string $html): array
    {
        preg_match('#<script type="application/json" data-pf-chart-config>(.*?)</script>#s', $html, $m);

        return (array) json_decode($m[1] ?? 'null', true);
    }
}
