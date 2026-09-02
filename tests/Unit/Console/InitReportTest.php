<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * What `init` says it did.
 *
 * `report()` is the summary a scaffolding run ends with — what it wrote, and what it found already
 * there and left alone. Six statements, never executed, for the one output somebody reads to decide
 * whether their own edits survived.
 *
 * Both lists are sorted. Not cosmetic: a run over dozens of files reports them in whatever order
 * the scaffolder happened to visit, and an unsorted list is one nobody can diff against the
 * previous run to see what changed.
 */
#[CoversClass(Init::class)]
class InitReportTest extends TestCase
{
    /**
     * A command with a known set of written and kept files.
     *
     * The two properties are private, which is right — they are filled by the run rather than by a
     * caller — so a test that wants a known state sets them directly.
     *
     * @param array<string, mixed> $written
     * @param array<string, mixed> $kept
     */
    private function commandThatDid(array $written, array $kept): Init
    {
        $command = new Init();

        (new \ReflectionProperty(Init::class, 'writtenFiles'))->setValue($command, $written);
        (new \ReflectionProperty(Init::class, 'keptFiles'))->setValue($command, $kept);

        return $command;
    }

    /**
     * The two lists come back separately, keyed by path.
     *
     * "Written" and "kept" are the only two outcomes a scaffolding run has, and conflating them is
     * the failure that matters: somebody reading "written" for a file that was actually left alone
     * would go looking for changes that are not there — or worse, trust that a file *was* updated.
     */
    public function testTheTwoListsComeBackSeparately(): void
    {
        // Arrange
        $command = $this->commandThatDid(
            ['src/Controllers/Home.php' => true, 'app/app.php' => true],
            ['composer.json' => true]
        );

        // Act
        $report = $command->report();

        // Assert
        $this->assertSame(['app/app.php', 'src/Controllers/Home.php'], $report['written']);
        $this->assertSame(['composer.json'], $report['kept']);
    }

    /**
     * Both lists are sorted, whatever order the run visited them in.
     *
     * A scaffolding run writes in dependency order, not alphabetical, so this is the difference
     * between a report you can diff against the last one and a report you cannot.
     */
    public function testBothListsAreSorted(): void
    {
        // Arrange — deliberately out of order
        $command = $this->commandThatDid(
            ['z.php' => true, 'a.php' => true, 'm.php' => true],
            ['zz.json' => true, 'aa.json' => true]
        );

        // Act
        $report = $command->report();

        // Assert
        $this->assertSame(['a.php', 'm.php', 'z.php'], $report['written']);
        $this->assertSame(['aa.json', 'zz.json'], $report['kept']);
    }

    /**
     * A run that did nothing reports two empty lists, not missing keys.
     *
     * The caller indexes both, so a run over an already-scaffolded project — which writes nothing
     * and keeps everything — must not make `$report['written']` an undefined index.
     */
    public function testARunThatWroteNothingStillReportsBothKeys(): void
    {
        // Act
        $report = $this->commandThatDid([], [])->report();

        // Assert
        $this->assertSame(['written' => [], 'kept' => []], $report);
    }

    /**
     * The paths are the keys, not the values.
     *
     * The two properties are maps — path to a flag — because a run needs to ask "did I already
     * touch this". Reporting the *values* would give a list of `true`s, which is the mistake
     * `array_keys()` is there to avoid.
     */
    public function testThePathsAreTheKeys(): void
    {
        // Act
        $report = $this->commandThatDid(['src/one.php' => false], ['src/two.php' => true])->report();

        // Assert
        $this->assertSame(['src/one.php'], $report['written']);
        $this->assertSame(['src/two.php'], $report['kept']);
    }
}
