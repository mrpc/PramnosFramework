<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Status;

/** The status page's controller, its view handed out to the test instead of drawn. */
class TestableStatus extends Status
{
    /** The view display() filled. */
    public ?object $lastView = null;

    /** A view that records what it is given. */
    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new class {
            public mixed $report = null;
            public mixed $checkedAt = null;

            /** Nothing drawn: the test reads what it was given. */
            public function display($view = '')
            {
                return 'status page';
            }
        };
        $this->lastView = $view;

        return $view;
    }
}

/** The page's view, drawn with a report: `$this` is what the view reads. */
class StatusPageProbe
{
    /** @param array<string, mixed> $report */
    public function __construct(public array $report, public string $checkedAt = '2026-10-19 12:00:00 UTC')
    {
    }

    /** The view's HTML. */
    public function draw(string $file): string
    {
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}

/**
 * `/Status`: a page anybody may read — the overall verdict and each check's by name — that never
 * publishes a check's message or details, which describe the host.
 */
#[CoversClass(Status::class)]
final class StatusControllerTest extends TestCase
{
    /** The page needs no sign-in, and is the report as it ran. */
    public function testThePageIsPublic(): void
    {
        // Arrange
        $status = new TestableStatus(\Pramnos\Application\Application::getInstance());

        // Act
        $answer = $status->display();

        // Assert
        $this->assertSame('status page', $answer);
        $this->assertNotContains('display', $status->actions_auth, 'no sign-in for the page');
        $this->assertContains('display', $status->actions);
        $this->assertArrayHasKey('status', $status->lastView->report);
        $this->assertNotSame('', (string) $status->lastView->checkedAt);
    }

    /**
     * Every theme draws the verdict and each check by name and verdict — and not the message or
     * the details a check carries; with no check at all it says that is not "all well".
     */
    public function testNoThemeShowsWhatDescribesTheHost(): void
    {
        // Arrange
        $report = ['status' => 'degraded', 'checks' => [
            'disk_space' => ['status' => 'degraded', 'message' => '194512 MB free (58.7% used)', 'details' => ['driver' => 'pgsql 17.2']],
            'database'   => ['status' => 'ok', 'message' => 'Connected', 'details' => ['version' => '8.0.39']],
        ]];
        $themes = glob(dirname(__DIR__, 5) . '/scaffolding/themes/*/views/status/status.html.php') ?: [];

        // Act + Assert
        $this->assertCount(3, $themes);
        foreach ($themes as $file) {
            $html  = (new StatusPageProbe($report))->draw($file);
            $empty = (new StatusPageProbe(['status' => 'ok', 'checks' => []]))->draw($file);
            $this->assertStringContainsString('Partly available', $html, $file);
            $this->assertStringContainsString('Disk space', $html, $file);
            $this->assertStringContainsString('Degraded', $html, $file);
            $this->assertStringContainsString('The details are available to the administrators.', $html, $file);
            $this->assertStringContainsString('/Status/check', $html, $file);
            foreach (['194512', '58.7', 'pgsql', '8.0.39', 'Connected'] as $leak) {
                $this->assertStringNotContainsString($leak, $html, $file . ' publishes ' . $leak);
            }
            $this->assertStringContainsString('No check ran. That does not mean everything is working.', $empty, $file);
        }
    }
}
