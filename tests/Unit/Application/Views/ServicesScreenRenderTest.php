<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The services screen, rendered in every scaffold theme with pools, batches and stops.
 *
 * Rendered rather than read as text, because what can go wrong here is in the rendering: an
 * undefined index on a pool the supervisor has not seen, an unescaped name, a form without
 * the session's token that every POST action would refuse.
 */
class ServicesScreenRenderTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['bootstrap' => ['bootstrap'], 'tailwind' => ['tailwind'], 'plain-css' => ['plain-css']];
    }

    /**
     * Render one theme's services view with $vars as its view properties.
     *
     * @param array<string, mixed> $vars
     */
    private function render(string $theme, array $vars): string
    {
        $file = dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/views/services/services.html.php';
        $view = new \stdClass();
        foreach ($vars as $key => $value) {
            $view->$key = $value;
        }

        $run = \Closure::bind(function () use ($file): void {
            include $file;
        }, $view, null);

        ob_start();
        try {
            $run();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Everything the screen can show, at once.
     *
     * @return array<string, mixed>
     */
    private function everything(): array
    {
        return [
            'orchestrator' => ['running' => true, 'pid' => 42, 'heartbeat_age_seconds' => 3],
            'services' => [
                ['id' => 'queue-passes-1', 'daemon' => 'queue', 'profile' => 'passes pool', 'workerId' => 'queue-passes-1', 'pid' => 100, 'status' => 'running', 'updatedAt' => 'now'],
                ['id' => 'schedule', 'daemon' => 'schedule', 'workerId' => 'schedule-1', 'pid' => 0, 'status' => 'stopped'],
            ],
            'stopped' => ['schedule' => ['id' => 'schedule', 'stopped_at' => time(), 'stopped_by' => 'alice']],
            'pools' => [
                ['name' => 'passes', 'source' => 'code', 'enabled' => true, 'types' => ['pass_unit'], 'size' => 3,
                 'backlog' => 120, 'load' => 0.25, 'decision' => 'running=3 target=3', 'config' => ['floor' => 1, 'ceiling' => 8, 'load_ceiling' => 0.75],
                 'row' => null, 'waiting' => false],
                ['name' => '<script>x</script>', 'source' => 'screen', 'enabled' => false, 'types' => [], 'size' => null,
                 'backlog' => null, 'load' => null, 'decision' => '', 'config' => [],
                 'row' => ['types' => null, 'floor' => null, 'ceiling' => 2, 'grow_above' => null, 'shrink_below' => null, 'load_percent' => null, 'cooldown' => null, 'enabled' => false],
                 'waiting' => true],
            ],
            'batches' => [
                ['id' => str_repeat('a', 32), 'name' => 'channels.collect', 'queued_at' => '2026-10-02 10:00:00',
                 'pending' => 4, 'processing' => 2, 'completed' => 10, 'warning' => 1, 'failed' => 1, 'total' => 18, 'finished' => false],
            ],
        ];
    }

    /**
     * Every theme renders pools, services and batches, with the numbers the scaling decision
     * is made from and the controls for each.
     */
    #[DataProvider('themes')]
    public function testEveryThemeRendersPoolsServicesAndBatches(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->everything());

        // Assert — the pool and what the supervisor saw of it
        $this->assertStringContainsString('data-pool="passes"', $html);
        $this->assertStringContainsString('running=3 target=3', $html);
        $this->assertMatchesRegularExpression('/data-field="backlog">\s*120/', $html);
        $this->assertStringContainsString('waiting for supervisor', $html);
        // A code pool's edit form shows the declared value as the placeholder
        $this->assertStringContainsString('placeholder="75"', $html);

        // The controls, each a POST to its action
        foreach (['poolstop/passes', 'poolstart/', 'pooldelete/', 'poolsave', 'restartall', 'restart/queue-passes-1'] as $action) {
            $this->assertStringContainsString('Services/' . $action, $html, $action);
        }

        // An operator's stop is shown as such, and offers Start rather than Stop
        $this->assertStringContainsString('Stopped by operator', $html);
        $this->assertStringContainsString('Services/start/schedule', $html);

        // The batch, with done = completed + warning
        $this->assertStringContainsString('channels.collect', $html);
        $this->assertMatchesRegularExpression('/data-field="done">\s*11/', $html);

        // The refresh script, pointed at the status endpoint
        $this->assertStringContainsString('data-status-url=', $html);
        $this->assertStringContainsString("setInterval(refresh, 10000)", $html);
    }

    /**
     * A name that is markup is shown as text, in every place it appears.
     */
    #[DataProvider('themes')]
    public function testNamesAreEscaped(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->everything());

        // Assert
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
    }

    /**
     * Every form carries the session's token: a POST without it is refused before the
     * action runs, which would make every control a button that does nothing.
     */
    #[DataProvider('themes')]
    public function testEveryFormCarriesTheToken(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->everything());

        // Assert
        $forms  = substr_count($html, '<form method="post"');
        $tokens = substr_count($html, \Pramnos\Http\Session::getInstance()->getTokenField());
        $this->assertGreaterThan(5, $forms);
        $this->assertSame($forms, $tokens);
    }

    /**
     * With nothing at all — no supervisor, no pools, no services — the screen still renders
     * and says what to do.
     */
    #[DataProvider('themes')]
    public function testAnEmptyScreenRenders(string $theme): void
    {
        // Act
        $html = $this->render($theme, ['orchestrator' => ['running' => false, 'pid' => null, 'heartbeat_age_seconds' => null]]);

        // Assert
        $this->assertStringContainsString('The orchestrator is not running', $html);
        $this->assertStringContainsString('No pools', $html);
        $this->assertStringContainsString('No services registered', $html);
        $this->assertStringNotContainsString('Batches', $html, 'no batch table without batches');
    }
}
