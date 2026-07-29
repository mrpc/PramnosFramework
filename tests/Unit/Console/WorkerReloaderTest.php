<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\WorkerReloader;

/**
 * Unit tests for the generalised worker reloader.
 *
 * Both of its inputs are parameters — the watched paths and the settings-version
 * resolver — so the tests point it at a scratch directory they mutate, and pass a
 * closure whose return value they control, without touching any real settings.
 */
class WorkerReloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wr_' . getmypid();
        @mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/A.php', '<?php // a');
        file_put_contents($this->root . '/composer.lock', '{}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/src/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/src');
        @unlink($this->root . '/composer.lock');
        @rmdir($this->root);
        parent::tearDown();
    }

    /**
     * codeChanged() returns false on the first call (it establishes the baseline)
     * and true once a watched file's size/mtime changes.
     */
    public function testCodeChangedDetectsAWatchedEdit(): void
    {
        $reloader = new WorkerReloader($this->root, ['src', 'composer.lock']);

        $this->assertFalse($reloader->codeChanged(), 'first call sets the baseline');

        // Grow the file so both size and mtime move.
        file_put_contents($this->root . '/src/A.php', '<?php // a much longer body');
        clearstatcache();

        $this->assertTrue($reloader->codeChanged());
    }

    /**
     * An unwatched path outside the configured list does not trip codeChanged().
     */
    public function testUnwatchedPathIsIgnored(): void
    {
        $reloader = new WorkerReloader($this->root, ['src']);
        $reloader->baseline();

        // composer.lock is not in the watched list this time.
        file_put_contents($this->root . '/composer.lock', '{"changed":true}');
        clearstatcache();

        $this->assertFalse($reloader->codeChanged());
    }

    /**
     * With no resolver, settings tracking is disabled: the version is a constant
     * and settingsChanged() never fires.
     */
    public function testNoResolverDisablesSettingsTracking(): void
    {
        $reloader = new WorkerReloader($this->root, ['src']);
        $this->assertSame('none', $reloader->settingsVersion());

        $reloader->baseline();
        $this->assertFalse($reloader->settingsChanged());
    }

    /**
     * settingsChanged() fires exactly once when the resolver's stamp moves, then
     * reports false again (the change is consumed).
     */
    public function testSettingsChangedConsumesTheChange(): void
    {
        $stamp = 'v1';
        $reloader = new WorkerReloader($this->root, ['src'], function () use (&$stamp) {
            return $stamp;
        });

        $reloader->baseline();
        $this->assertFalse($reloader->settingsChanged());

        $stamp = 'v2';
        $this->assertTrue($reloader->settingsChanged(), 'stamp moved');
        $this->assertFalse($reloader->settingsChanged(), 'change already consumed');
    }

    /**
     * A resolver that throws is contained: the version degrades to 'unknown'
     * rather than crashing the worker loop.
     */
    public function testResolverThrowIsContained(): void
    {
        $reloader = new WorkerReloader($this->root, ['src'], function () {
            throw new \RuntimeException('boom');
        });

        $this->assertSame('unknown', $reloader->settingsVersion());
    }

    /**
     * isSupervised() detects a supervising parent from the environment markers
     * (systemd, supervisord, or the explicit override) and is false otherwise.
     */
    public function testIsSupervisedReadsEnvironmentMarkers(): void
    {
        $this->assertFalse(WorkerReloader::isSupervised([]));
        $this->assertTrue(WorkerReloader::isSupervised(['INVOCATION_ID' => 'abc']));
        $this->assertTrue(WorkerReloader::isSupervised(['SUPERVISOR_ENABLED' => '1']));
        $this->assertTrue(WorkerReloader::isSupervised(['WORKER_SUPERVISED' => 'yes']));
        $this->assertFalse(WorkerReloader::isSupervised(['INVOCATION_ID' => '  ']));
    }
}
