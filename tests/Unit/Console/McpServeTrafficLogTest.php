<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\McpServe;

/**
 * Where `mcp:serve` writes its traffic log.
 *
 * Four statements, never executed. The default is the point: the framework's own log directory,
 * not the working directory — so the file appears in the log viewer beside everything else,
 * and `log-errors --files mcp.log` answers "which call failed" without anybody configuring
 * anything.
 *
 * A default of `./mcp.log` would put it wherever the daemon happened to be started from, which is
 * `/` under most service managers.
 */
#[CoversClass(McpServe::class)]
class McpServeTrafficLogTest extends TestCase
{
    private function pathFor(?string $given): string
    {
        return (new \ReflectionMethod(McpServe::class, 'trafficLogPath'))
            ->invoke(new McpServe(), $given);
    }

    /**
     * With nothing given, it lands in the framework's log directory.
     */
    public function testTheDefaultIsTheFrameworksLogDirectory(): void
    {
        // Act
        $path = $this->pathFor(null);

        // Assert
        $this->assertSame(
            \Pramnos\Logs\LogManager::getLogFilePath('mcp', 'log'),
            $path,
            'the default must be the log directory the viewer reads'
        );
        $this->assertNotSame('mcp.log', $path, 'a bare filename lands wherever the daemon started');
    }

    /**
     * An explicit path is used as given.
     *
     * Somebody debugging one client wants the log where they are looking, and the option is the
     * only way to say so.
     */
    public function testAnExplicitPathIsUsedAsGiven(): void
    {
        // Act + Assert
        $this->assertSame('/tmp/one-client.log', $this->pathFor('/tmp/one-client.log'));
    }

    /**
     * A blank option falls back to the default rather than to the empty string.
     *
     * `--traffic-log=` is what a script produces when the variable it interpolates is unset, and
     * an empty path is a file nothing can open — silently, since the log is opened for writing and
     * a failure there is not the command's job to report.
     *
     * @return array<string, array{0: string}>
     */
    public static function blankOptions(): array
    {
        return ['empty' => [''], 'spaces' => ['   '], 'tab' => ["\t"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blankOptions')]
    public function testABlankOptionFallsBackToTheDefault(string $given): void
    {
        // Act
        $path = $this->pathFor($given);

        // Assert
        $this->assertSame(\Pramnos\Logs\LogManager::getLogFilePath('mcp', 'log'), $path);
    }
}
