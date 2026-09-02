<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Make\MakeService;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `create:service`, the thin command around `createService()`.
 *
 * Seven statements, never executed. Six of them are wiring and one is a refusal:
 *
 * ```php
 * if (!$name) {
 *     throw new \InvalidArgumentException('Name is required for: service');
 * }
 * ```
 *
 * Which is the whole of what this command decides. `createService('')` would derive a class name
 * from nothing — the base command turns an empty name into an empty class, and the file it writes
 * is `src/Services/.php`: a dotfile PHP will never autoload, reported as created.
 */
#[CoversClass(MakeService::class)]
class MakeServiceCommandTest extends TestCase
{
    /** @var list<string> Paths to remove after each test */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $this->created = [];
        parent::tearDown();
    }

    /** The command, with a console application around it so options resolve. */
    private function command(): MakeService
    {
        $command = new MakeService();

        $console = new \Pramnos\Console\Application();
        $console->internalApplication = new class extends \Pramnos\Application\Application {
            public function init($settingsFile = '') {}
        };
        $console->add($command);

        return $command;
    }

    /**
     * A name is required, and the message says what for.
     *
     * `create:service` with no argument is a typo, not a request for a default — and the message
     * names the thing, because this base is shared by half a dozen `create:*` commands and
     * "Name is required" alone would not say which one refused.
     */
    public function testANameIsRequired(): void
    {
        // Arrange
        $tester = new CommandTester($this->command());

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required for: service');

        // Act
        $tester->execute(['name' => '']);
    }

    /**
     * With a name, it writes the service and reports where.
     *
     * The summary is the command's whole output, so a run that created a file and said nothing
     * would leave somebody looking for it.
     */
    public function testWithANameItWritesTheServiceAndSaysWhere(): void
    {
        // Arrange
        $root = defined('ROOT') ? ROOT : getcwd();
        $this->created[] = $root . '/src/Services/ReportingService.php';
        $this->created[] = $root . '/tests/Unit/ReportingServiceTest.php';

        $tester = new CommandTester($this->command());

        // Act
        $status = $tester->execute(['name' => 'ReportingService']);

        // Assert
        $this->assertSame(0, $status);
        $this->assertStringContainsString('ReportingService', $tester->getDisplay());
        $this->assertFileExists($root . '/src/Services/ReportingService.php');
    }
}
