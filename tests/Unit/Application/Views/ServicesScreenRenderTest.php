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
    private function render(string $theme, array $vars, string $template = 'services'): string
    {
        $file = dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/views/services/' . $template . '.html.php';
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
     * Every theme renders services, pools and batches, with the numbers the scaling decision
     * is made from and the controls for each — and nothing to configure: the services come
     * first, and a pool's limits are a link away, on Services/pools.
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

        // The controls, each a POST to its action
        foreach (['poolstop/passes', 'poolstart/', 'restartall', 'restart/queue-passes-1'] as $action) {
            $this->assertStringContainsString('Services/' . $action, $html, $action);
        }

        // A status screen: no pool form, a link to the page that has them, the services first
        $this->assertStringNotContainsString('Services/poolsave', $html, 'a pool form on the status screen');
        $this->assertStringNotContainsString('Services/pooldelete', $html);
        $this->assertStringContainsString('href="' . adminUrl('Services/pools') . '"', $html);
        $this->assertLessThan(strpos($html, 'data-pool="passes"'), strpos($html, 'Services/restart/queue-passes-1'), 'the services come before the pools');

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

    /**
     * What the pools page is given: a code pool with declared limits, a screen pool with its
     * saved types, and the task types to tick.
     *
     * @return array<string, mixed>
     */
    private function poolsPage(): array
    {
        $everything = $this->everything();
        $everything['pools'][1]['row']['types'] = 'mail.send';

        return ['pools' => $everything['pools'], 'taskTypes' => ['mail.send', 'pass_unit', 'report.build']];
    }

    /**
     * The pools page draws a card per pool and one to add a pool, each with every task type as
     * a box to tick — the saved ones ticked — and every limit with its help under it. A code
     * pool's declared value is the placeholder, and its unsaved types are not ticked, so saving
     * it does not copy them into a row.
     */
    #[DataProvider('themes')]
    public function testThePoolsPageHasACardPerPoolWithTheTypesToTick(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->poolsPage(), 'pools');

        // Assert — two pools and the card that adds one
        $this->assertStringContainsString('data-pool-card="passes"', $html);
        $this->assertStringContainsString('data-pool-card="&lt;script&gt;x&lt;/script&gt;"', $html);
        $this->assertSame(1, substr_count($html, 'data-pool-new'));
        // Every known type is a box on each of the three cards
        $this->assertSame(3, substr_count($html, 'name="types[]" value="report.build"'));
        // Ticked where saved: the screen pool's mail.send, and nothing on the code pool
        $this->assertSame(1, preg_match_all('/value="mail\.send" checked/', $html));
        $this->assertStringNotContainsString('value="pass_unit" checked', $html, 'a code pool\'s declared types copied into the form');
        $this->assertStringContainsString('None ticked keeps the types declared in code: pass_unit.', $html);
        // The declared limits as placeholders, the saved one as the value
        $this->assertStringContainsString('placeholder="75"', $html);
        $this->assertStringContainsString('placeholder="8"', $html);
        $this->assertMatchesRegularExpression('/name="ceiling" inputmode="numeric"\s*value="2"/', $html);
        // Each limit says what it does
        $this->assertStringContainsString('Always keep at least this many workers running.', $html);
        $this->assertStringContainsString('Start another worker when more than this many tasks are waiting.', $html);
    }

    /**
     * The pools page's controls: Start and Stop come back to the page (`back=pools`), Remove and
     * Reset ask first through `data-confirm` rather than an inline handler, and every form
     * carries the session's token.
     */
    #[DataProvider('themes')]
    public function testThePoolsPageControlsReturnHereAndCarryTheToken(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->poolsPage(), 'pools');

        // Assert
        $this->assertStringContainsString('Services/poolstop/passes', $html);
        $this->assertStringContainsString('Services/poolstart/' . urlencode('<script>x</script>'), $html);
        $this->assertSame(2, substr_count($html, 'name="back" value="pools"'));
        $this->assertStringContainsString('data-confirm="Remove this pool? Its workers are stopped."', $html);
        $this->assertStringNotContainsString('onsubmit=', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $forms  = substr_count($html, '<form method="post"');
        $tokens = substr_count($html, \Pramnos\Http\Session::getInstance()->getTokenField());
        $this->assertGreaterThan(4, $forms);
        $this->assertSame($forms, $tokens);
    }

    /**
     * With no pools the page is the card that adds one, and the types still to tick.
     */
    #[DataProvider('themes')]
    public function testThePoolsPageWithNoPoolsOffersToAddOne(string $theme): void
    {
        // Act
        $html = $this->render($theme, ['pools' => [], 'taskTypes' => ['mail.send']], 'pools');

        // Assert
        $this->assertStringContainsString('data-pool-new', $html);
        $this->assertStringNotContainsString('data-pool-card=', $html);
        $this->assertStringContainsString('None ticked: the pool takes every type.', $html);
        $this->assertStringContainsString('name="types[]" value="mail.send"', $html);
    }

    /**
     * The pools are settings, so Settings links to them: one click from where an administrator
     * looks for a limit to change.
     */
    #[DataProvider('themes')]
    public function testSettingsLinksToThePools(string $theme): void
    {
        // Arrange
        $view = (string) file_get_contents(dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/views/settings/settings.html.php');

        // Act
        // The line carrying the link's marker — the markup holds PHP, so it is read by line.
        $line = (string) current(array_filter(explode("\n", $view), static fn (string $l): bool => str_contains($l, 'data-settings-pools')));

        // Assert
        $this->assertStringContainsString("<a href=\"<?php echo adminUrl('Services/pools'); ?>\"", $line, $theme . ': Settings does not link to the worker pools');
    }
}
