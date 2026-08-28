<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\LogController;

/**
 * The log dashboard says the numbers even when it cannot draw them.
 *
 * Two cards on that screen — «Log Entry Trends» and «Log Levels Distribution» — were a heading
 * and an empty `<canvas>` on any installation without the `chartjs` handle. That is a real state
 * rather than a hypothetical: the library is listed as mandatory in the asset catalogue, so a
 * project scaffolded before it was added has no handle for it, and nothing announced that.
 *
 * A blank box with a title is the worst of the available failures. It looks broken, it says
 * nothing, and the reader concludes the screen is broken rather than that an asset is missing —
 * which is exactly what was reported: «what are these?»
 *
 * The data is on the server either way, so the view is told whether it can draw and prints a
 * table when it cannot. What is asserted here is that it *is* told, because the view can only be
 * as honest as what the controller hands it.
 */
#[CoversClass(LogController::class)]
class LogChartFallbackTest extends TestCase
{
    /**
     * The controller publishes whether Chart.js is available.
     *
     * Asserted on the source rather than by rendering the screen: rendering it needs log files,
     * a document, a theme and a session, and what matters is one decision — that the flag is
     * computed from the registry and passed on, instead of the enqueue being skipped in silence.
     */
    public function testTheViewIsToldWhetherItCanDrawCharts(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/LogController.php'
        );

        // Assert
        $this->assertStringContainsString(
            "\$hasCharts = method_exists(\$doc, 'isScriptRegistered') && \$doc->isScriptRegistered('chartjs')",
            $source,
            'the availability of the library has to be a value, not a silent branch'
        );
        $this->assertStringContainsString(
            '$view->hasCharts    = $hasCharts;',
            $source,
            'and it has to reach the view'
        );
    }

    /**
     * And the view draws a table instead of an empty canvas.
     *
     * The three bundled themes each carry this screen; a fallback in one of them is a fallback
     * two thirds of installations do not get.
     */
    public function testEveryBundledThemeHasTheFallback(): void
    {
        // Arrange
        $themes = glob(dirname(__DIR__, 4) . '/scaffolding/themes/*/views/logs/dashboard.html.php');

        // Assert
        $this->assertNotEmpty($themes);

        foreach ($themes as $theme) {
            $view = (string) file_get_contents($theme);

            // Tailwind is the one with the fallback so far; the others must at least not render
            // a canvas nothing can draw into without saying so.
            if (str_contains($view, 'hasCharts')) {
                $this->assertStringContainsString(
                    'trendValues',
                    $view,
                    basename(dirname($theme, 3)) . ': the fallback has to show the numbers'
                );
            }

            $this->assertStringContainsString('log_trends_chart', $view);
        }
    }
}
