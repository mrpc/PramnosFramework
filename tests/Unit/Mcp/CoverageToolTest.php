<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\CoverageTool;

/**
 * `coverage` — which lines of *this change* no test touches.
 *
 * The rule in these projects is coverage above 95% on changed code, and it was unverifiable: a
 * coverage run produces a project-wide percentage, which barely moves when fifty uncovered lines
 * are added to twenty thousand covered ones. So the rule was followed by assumption, which is to
 * say not followed — two thousand lines were written in one day without it being checked once.
 *
 * The first thing this tool did when pointed at its own author's work was report 7.5%. That is
 * the behaviour under test: a number that can fail.
 */
#[CoversClass(CoverageTool::class)]
class CoverageToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        exec('git --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $this->root = sys_get_temp_dir() . '/coverage-tool-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/coverage', 0777, true);

        $this->git('init --quiet');
        $this->git('config user.email test@example.com');
        $this->git('config user.name Test');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));

        parent::tearDown();
    }

    private function git(string $arguments): void
    {
        exec(
            'git -c ' . escapeshellarg('safe.directory=' . $this->root)
            . ' -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>&1'
        );
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * A clover report covering one file, with the given hit counts per line.
     *
     * @param array<string, array<int, int>> $files
     */
    private function report(array $files, ?int $mtime = null, string $prefix = ''): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<coverage generated="1"><project timestamp="1" name="Clover">';

        foreach ($files as $file => $lines) {
            $xml .= '<package name="P"><file name="' . $prefix . $this->root . '/' . $file . '">';

            foreach ($lines as $number => $count) {
                $xml .= '<line num="' . $number . '" type="stmt" count="' . $count . '"/>';
            }

            $xml .= '</file></package>';
        }

        $xml .= '</project></coverage>';

        $this->write('coverage/clover.xml', $xml);

        if ($mtime !== null) {
            touch($this->root . '/coverage/clover.xml', $mtime);
        }
    }

    /** @return array<string, mixed> */
    private function ask(array $input = []): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new CoverageTool($this->root))->execute($input);

        return $answer;
    }

    /**
     * Only the changed lines are judged, and the uncovered ones are named.
     *
     * A list of line numbers is short enough to act on, which a percentage is not.
     */
    public function testItReportsTheUncoveredLinesOfTheChange(): void
    {
        // Arrange — four lines committed and covered, then two changed, one of them untested
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\nCHANGED_COVERED;\nCHANGED_NOT;\n");
        $this->report(['src/Thing.php' => [2 => 5, 3 => 1, 4 => 0]], time() + 60);

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(2, $answer['changed_executable_lines']);
        $this->assertSame(1, $answer['covered']);
        $this->assertSame(1, $answer['uncovered']);
        $this->assertSame(50.0, $answer['percent']);
        $this->assertSame([4], $answer['files'][0]['uncovered_lines']);
        $this->assertStringContainsString('above 95%', $answer['verdict']);
    }

    /**
     * A line that cannot be executed is not counted as uncovered.
     *
     * Blank lines, closing braces, comments and property declarations are absent from the
     * report entirely. Counting them as uncovered would turn every honest change into a
     * failure, and a gate that always fails is a gate nobody uses.
     */
    public function testUnmeasurableLinesAreNotCountedAgainstYou(): void
    {
        // Arrange — the changed lines are a comment and a brace; neither is in the report
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n// a comment\n}\n");
        $this->report(['src/Thing.php' => [2 => 5]], time() + 60);

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(0, $answer['changed_executable_lines']);
        $this->assertStringContainsString('not untested', $answer['verdict']);
        $this->assertArrayNotHasKey('percent', $answer);
    }

    /**
     * A brand-new class that no test ever loaded is named, not silently passed.
     *
     * This is the worst answer a coverage gate can give, and it was the one it gave. A file
     * absent from the report was skipped as unmeasurable — which is right for a guide or a
     * stub, and exactly wrong for a new class under `src/`: PHPUnit never saw a line of it
     * because nothing loaded it, so it is not "no executable lines changed", it is 0%.
     *
     * Found by the tool reporting 100% on a change that added an entire untested package.
     */
    public function testANewClassNoTestEverLoadedIsNotSilentlyPassed(): void
    {
        // Arrange — an existing covered file, plus a new one the report knows nothing about
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\nCHANGED;\n");
        $this->write('src/Untested.php', "<?php\nclass Untested { public function go() { return 1; } }\n");
        $this->report(['src/Thing.php' => [2 => 5, 3 => 2]], time() + 60);

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(['src/Untested.php'], $answer['unmeasured']);
        $this->assertStringContainsString(
            'not covered by any test at all',
            $answer['verdict'],
            'a change that adds an untested class must not read as a pass'
        );
    }

    /**
     * A guide, a stub and a test are not "untested classes".
     *
     * The whitelist is `src/`, so nothing else was ever going to be in the report. Naming
     * every markdown file as uncovered would make the warning noise, and noise is ignored.
     */
    public function testFilesOutsideTheMeasuredRootsAreNotNamed(): void
    {
        // Arrange
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\nCHANGED;\n");
        $this->write('docs/Guide.md', "# A guide\n");
        $this->write('tests/Unit/ThingTest.php', "<?php\nclass ThingTest {}\n");
        $this->report(['src/Thing.php' => [2 => 5, 3 => 2]], time() + 60);

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertArrayNotHasKey('unmeasured', $answer);
        $this->assertStringContainsString('is covered', $answer['verdict']);
    }

    /**
     * Fully covered says so plainly.
     */
    public function testAFullyCoveredChangeSaysSo(): void
    {
        // Arrange
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $this->report(['src/Thing.php' => [2 => 5, 3 => 2]], time() + 60);

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(0, $answer['uncovered']);
        $this->assertSame(100.0, $answer['percent']);
        $this->assertStringContainsString('Every executable line', $answer['verdict']);
    }

    /**
     * A report older than the code is called stale.
     *
     * Reading a stale coverage report is worse than reading none: it reports the previous
     * version of a file as covered, and the line numbers have moved underneath it.
     */
    public function testAReportOlderThanTheCodeIsCalledStale(): void
    {
        // Arrange
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->report(['src/Thing.php' => [2 => 1]], time() - 3600);
        touch($this->root . '/src/Thing.php', time());
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n\$b = 2;\n");

        // Act
        $stale = $this->ask()['report']['stale'];

        // Assert
        $this->assertIsString($stale);
        $this->assertStringContainsString('newer than the report', $stale);
        $this->assertStringContainsString('--coverage', $stale);
    }

    /**
     * A report from inside a container is joined to project-relative paths.
     *
     * Clover records the path the *test run* saw, which is `/var/www/html/src/…` in a
     * container while the caller thinks in project-relative paths. Getting the join wrong
     * reports every line as unmeasurable — a silent pass — rather than failing loudly.
     */
    public function testAContainerPathIsStillMatched(): void
    {
        // Arrange — the report's paths carry a foreign prefix
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $this->report(['src/Thing.php' => [2 => 1, 3 => 0]], time() + 60, '/var/www/html');

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(1, $answer['uncovered'], 'the file was recognised despite the prefix');
        $this->assertSame([3], $answer['files'][0]['uncovered_lines']);
    }

    /**
     * `path` restricts the answer to a subtree.
     */
    public function testPathRestrictsTheAnswer(): void
    {
        // Arrange
        $this->write('src/Kept.php', "<?php\n\$a = 1;\n");
        $this->write('src/Other/Skipped.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Kept.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $this->write('src/Other/Skipped.php', "<?php\n\$a = 1;\n\$c = 3;\n");
        $this->report([
            'src/Kept.php'          => [2 => 1, 3 => 0],
            'src/Other/Skipped.php' => [2 => 1, 3 => 0],
        ], time() + 60);

        // Act
        $answer = $this->ask(['path' => 'src/Other']);

        // Assert
        $this->assertCount(1, $answer['files']);
        $this->assertSame('src/Other/Skipped.php', $answer['files'][0]['file']);
    }

    /**
     * The project-wide figure is available, and says why it is not the rule.
     */
    public function testTheProjectFigureIsAvailableAndQualified(): void
    {
        // Arrange
        $this->report(['src/Thing.php' => [2 => 1, 3 => 1, 4 => 0]], time() + 60);

        // Act
        $answer = $this->ask(['project' => true]);

        // Assert
        $this->assertSame(3, $answer['lines']);
        $this->assertSame(2, $answer['covered']);
        $this->assertSame(66.7, $answer['percent']);
        $this->assertStringContainsString('not the rule', $answer['note']);
    }

    /**
     * No report says how to make one, and does not pretend to a verdict.
     */
    public function testAMissingReportSaysHowToMakeOne(): void
    {
        // Act
        $answer = $this->ask();

        // Assert
        $this->assertStringContainsString('No clover coverage report', $answer['error']);
        $this->assertStringContainsString('--coverage', $answer['note']);
        $this->assertArrayNotHasKey('percent', $answer);
    }

    /**
     * A report that is not clover XML is reported as unparseable, not as zero coverage.
     */
    public function testAnUnparseableReportIsNotZeroCoverage(): void
    {
        // Arrange
        $this->write('coverage/clover.xml', 'this is not xml at all <<<');

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertStringContainsString('could not be parsed', $answer['error']);
        $this->assertArrayNotHasKey('uncovered', $answer);
    }

    /**
     * Outside a repository it says so and offers the project figure instead.
     */
    public function testOutsideARepositoryItOffersTheProjectFigure(): void
    {
        // Arrange — a report, but no git
        $elsewhere = sys_get_temp_dir() . '/no-repo-' . bin2hex(random_bytes(4));
        mkdir($elsewhere . '/coverage', 0777, true);
        file_put_contents(
            $elsewhere . '/coverage/clover.xml',
            '<?xml version="1.0"?><coverage><project name="p"></project></coverage>'
        );

        try {
            // Act
            $answer = (new CoverageTool($elsewhere))->execute([]);

            // Assert
            $this->assertStringContainsString('Not a git working tree', $answer['error']);
            $this->assertStringContainsString('project: true', $answer['note']);
        } finally {
            exec('rm -rf ' . escapeshellarg($elsewhere));
        }
    }

    /**
     * The description says what it is for and that it runs nothing.
     */
    public function testTheDescriptionSaysWhatItIsFor(): void
    {
        // Arrange
        $tool = new CoverageTool($this->root);

        // Assert
        $this->assertSame('coverage', $tool->name());
        $this->assertStringContainsString('runs nothing', $tool->description());
        $this->assertStringContainsString('percentage cannot answer', $tool->description());
    }

    /**
     * The schema names every option, including the one that is not the default.
     *
     * The schema *is* the interface — a caller cannot pass an argument it does not describe,
     * so an option missing from here is an option that does not exist. And this file's own
     * coverage report is what put the method in the test: it was the largest uncovered block
     * in the change, which is the sort of thing a percentage never tells you.
     */
    public function testTheSchemaDescribesEveryOption(): void
    {
        // Act
        $properties = (new CoverageTool($this->root))->inputSchema()['properties'];

        // Assert
        $this->assertSame(['since', 'path', 'project'], array_keys($properties));
        $this->assertSame('boolean', $properties['project']['type']);
        $this->assertStringContainsString('HEAD', $properties['since']['description']);
    }

    /**
     * A file entry with no name is skipped rather than becoming a nameless row.
     *
     * Clover is written by a tool and is normally well-formed, but a truncated report — a run
     * killed part-way, which is how one is usually produced — has entries with nothing in them.
     */
    public function testAnUnnamedFileEntryIsSkipped(): void
    {
        // Arrange — one real file and one with an empty name
        $this->write('coverage/clover.xml', '<?xml version="1.0"?><coverage><project name="p">'
            . '<package name="P"><file name=""><line num="2" type="stmt" count="0"/></file>'
            . '<file name="' . $this->root . '/src/Thing.php">'
            . '<line num="2" type="stmt" count="3"/></file></package>'
            . '</project></coverage>');

        // Act
        $answer = $this->ask(['project' => true]);

        // Assert
        $this->assertSame(1, $answer['files'], 'the nameless entry did not become a file');
        $this->assertSame(1, $answer['lines']);
    }

    /**
     * A report path with no recognisable source directory is used as it stands.
     *
     * The last resort of the path join. It cannot match anything in the diff, and that is the
     * honest outcome — better than mapping it onto a project file it is not.
     */
    public function testAnUnrecognisablePathIsLeftAsItIs(): void
    {
        // Arrange — a path with no /src/, /app/, /tests/ or project root in it
        $this->write('coverage/clover.xml', '<?xml version="1.0"?><coverage><project name="p">'
            . '<package name="P"><file name="/somewhere/else/Thing.php">'
            . '<line num="2" type="stmt" count="0"/></file></package>'
            . '</project></coverage>');

        // Act — the project figure counts it; the diff cannot match it
        $project = $this->ask(['project' => true]);

        $this->write('src/Thing.php', "<?php\n\$a = 1;\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $diff = $this->ask();

        // Assert
        $this->assertSame(1, $project['lines']);
        $this->assertSame(0, $diff['changed_executable_lines'],
            'an unmatched report path measures nothing rather than the wrong thing');
    }

    /**
     * Passing reads as passing.
     *
     * The verdict said "the rule is above 95%" at 99% too, which reads like a failure — and a
     * tool that cries wolf gets its next reading ignored. Which side of the threshold we are on
     * is the part that has to be unambiguous.
     */
    public function testAPassingChangeDoesNotReadAsAFailure(): void
    {
        // Arrange — 19 of 20 changed lines covered
        $this->write('src/Thing.php', "<?php\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');

        $body  = "<?php\n";
        $lines = [];

        for ($line = 2; $line <= 21; $line++) {
            $body .= '$x' . $line . " = 1;\n";
            $lines[$line] = $line === 21 ? 0 : 1;
        }

        $this->write('src/Thing.php', $body);
        $this->report(['src/Thing.php' => $lines], time() + 60);

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(95.0, $answer['percent']);
        $this->assertStringContainsString('Above the 95%', $answer['verdict']);
        $this->assertStringNotContainsString('The rule in these projects', $answer['verdict']);
    }
}
