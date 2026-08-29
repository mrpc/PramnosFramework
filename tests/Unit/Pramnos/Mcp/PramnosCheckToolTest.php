<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Mcp;

use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\PramnosCheckTool;

/**
 * The check tool finds the six documented defects, and does not cry wolf.
 *
 * Precision is the whole product here. A check that reports the guide teaching the right
 * thing, or a driver-specific statement the rule itself exempts, gets muted — and then the
 * real finding it makes next month is muted with it. So this class carries a
 * `testNoFalsePositives…` case for every rule, and those are the ones that matter.
 *
 * The numbers are not hypothetical. The first run of this tool against the framework's own
 * `src/` reported 29 raw-SQL findings; **sixteen were noise** — `SELECT version()`,
 * `SELECT NOW()`, `select @@global.long_query_time`, TimescaleDB catalogs, and one example
 * inside a docblock, because the first version did not strip comments. Tightening it to nine
 * defensible findings is what the negative cases below preserve.
 */
class PramnosCheckToolTest extends TestCase
{
    /** @var string A throwaway project tree */
    private string $project = '';

    /**
     * Builds an empty project.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/pramnos-check-' . getmypid() . '-' . uniqid();
        mkdir($this->project, 0777, true);
    }

    /**
     * Removes it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->project);
    }

    /**
     * Recursively deletes a directory.
     *
     * @param  string $path The directory
     * @return void
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Writes a file into the fixture project.
     *
     * @param  string $relative Path within the project
     * @param  string $contents The file body
     * @return void
     */
    private function write(string $relative, string $contents): void
    {
        $full = $this->project . '/' . $relative;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }

    /**
     * Runs the tool over the fixture project.
     *
     * @param  array<string, mixed> $input Tool input
     * @return array<string, mixed>
     */
    private function check(array $input = []): array
    {
        return (new PramnosCheckTool($this->project))->execute($input);
    }

    /**
     * The rules that fired, as a list of names.
     *
     * @param  array<string, mixed> $result A tool result
     * @return list<string>
     */
    private function rulesFired(array $result): array
    {
        return array_values(array_unique(array_column($result['findings'] ?? [], 'rule')));
    }

    /**
     * A clean project produces no findings, and says how much it checked.
     *
     * The verdict names the file count on purpose: "no findings" from a scan of nothing is the
     * failure mode this whole tool exists to avoid reproducing.
     *
     * @return void
     */
    public function testACleanProjectPassesAndSaysWhatItChecked(): void
    {
        // Arrange
        $this->write('src/Models/Station.php', <<<'PHP'
<?php
class Station
{
    public function active(): array
    {
        return $this->db->queryBuilder()->table('stations')->where('active', 1)->get();
    }
}
PHP);

        // Act
        $result = $this->check();

        // Assert
        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['checked']);
        $this->assertStringContainsString('1 files checked', $result['verdict']);
    }

    /**
     * An empty scan is an error, not a pass.
     *
     * Two answers that look identical to a caller and mean opposite things: nothing is wrong,
     * versus nothing was examined. A structural check in this repository once passed by
     * scanning zero files.
     *
     * @return void
     */
    public function testAnEmptyScanIsReportedRatherThanPassing(): void
    {
        // Act — nothing was written to the project
        $result = $this->check();

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('this is not a pass', $result['error']);
    }

    /**
     * Raw DML is reported.
     *
     * @return void
     */
    public function testRawSqlIsReported(): void
    {
        // Arrange
        $this->write('src/Repo.php', <<<'PHP'
<?php
$rows = $db->query('SELECT * FROM stations WHERE active = 1');
PHP);

        // Act
        $result = $this->check(['rules' => ['raw-sql']]);

        // Assert
        $this->assertCount(1, $result['findings']);
        $this->assertSame('src/Repo.php', $result['findings'][0]['file']);
        $this->assertSame(2, $result['findings'][0]['line']);
        $this->assertStringContainsString('queryBuilder()', $result['findings'][0]['fix']);
    }

    /**
     * What raw-sql must **not** report.
     *
     * Every entry here was a real finding from the first run against this framework, and every
     * one of them was noise. Rule 12 exempts driver-specific features and introspection in its
     * own text; a statement with no table cannot be expressed by a builder at all; migrations
     * must emit exact SQL; a fixture in test code is clearer literal; and a docblock showing
     * the API is documentation.
     *
     * @return void
     */
    public function testRawSqlDoesNotReportWhatTheRuleExempts(): void
    {
        // Arrange
        $this->write('src/Introspection.php', <<<'PHP'
<?php
$v  = $db->query('SELECT version() AS ver');
$n  = $db->query('SELECT NOW()');
$d  = $db->query('SELECT DATABASE() AS d');
$p  = $db->query('SELECT 1');
$l  = $db->query('SELECT LASTVAL()');
$g  = $db->query('select @@global.long_query_time');
$i  = $db->query('SELECT * FROM information_schema.columns');
$h  = $db->query('SELECT * FROM timescaledb_information.hypertables');
$e  = $db->query("SELECT 1 FROM pg_extension WHERE extname = 'timescaledb'");
$c  = $db->query('CREATE TABLE things (id INT)');
PHP);
        $this->write('src/Documented.php', <<<'PHP'
<?php
/**
 * Usage:
 *   >>> $db->query('SELECT * FROM stations');
 */
class Documented {}
PHP);
        $this->write('database/migrations/2026_08_17_000001_thing.php', <<<'PHP'
<?php
$db->query('DELETE FROM legacy_rows WHERE stale = 1');
PHP);
        $this->write('tests/Unit/FixtureTest.php', <<<'PHP'
<?php
$db->query('INSERT INTO users (userid) VALUES (1)');
PHP);

        // Act
        $result = $this->check(['rules' => ['raw-sql']]);

        // Assert
        $this->assertSame(
            [],
            $result['findings'],
            'None of these is a rule-12 violation; reporting them is how a check gets muted.'
        );
    }

    /**
     * An authserver table named without its schema is reported.
     *
     * The table list comes from the framework's own migrations, so this fixture uses a real
     * one — `user_activity_log` — rather than inventing a name the tool could not know.
     *
     * @return void
     */
    public function testAnUnqualifiedAuthserverTableIsReported(): void
    {
        // Arrange
        $this->write('src/Audit.php', <<<'PHP'
<?php
$rows = $db->queryBuilder()->table('user_activity_log')->get();
PHP);

        // Act
        $result = $this->check(['rules' => ['unqualified-authserver']]);

        // Assert
        $this->assertCount(1, $result['findings']);
        $this->assertStringContainsString(
            'authserver.user_activity_log',
            $result['findings'][0]['fix']
        );
        $this->assertStringContainsString('search_path', $result['findings'][0]['why']);
    }

    /**
     * The qualified form is not reported, nor is the word in prose.
     *
     * The negative case that keeps the rule usable: the correct spelling contains the
     * incorrect one as a substring, so a naive matcher flags every corrected call site.
     *
     * @return void
     */
    public function testTheQualifiedFormIsNotReported(): void
    {
        // Arrange
        $this->write('src/Audit.php', <<<'PHP'
<?php
$rows  = $db->queryBuilder()->table('authserver.user_activity_log')->get();
$other = $db->queryBuilder()->table('authserver_user_activity_log')->get();
$label = 'user_activity_log';
PHP);

        // Act
        $result = $this->check(['rules' => ['unqualified-authserver']]);

        // Assert
        $this->assertSame([], $result['findings']);
    }

    /**
     * A message passed as a query parameter is reported.
     *
     * @return void
     */
    public function testFlashQueryParamsAreReported(): void
    {
        // Arrange
        $this->write('src/Controllers/Things.php', <<<'PHP'
<?php
$this->redirect(sURL . 'things?error=not_found');
PHP);

        // Act
        $result = $this->check(['rules' => ['flash-query-params']]);

        // Assert
        $this->assertCount(1, $result['findings']);
        $this->assertStringContainsString('addMessage', $result['findings'][0]['fix']);
    }

    /**
     * Reading an inbound parameter is not the same as writing one.
     *
     * An application does not control every link pointing at it, so consuming `?message=` is
     * legitimate; producing one is the defect. Without this distinction the rule fires on the
     * code that handles the problem.
     *
     * @return void
     */
    public function testReadingAnInboundParameterIsNotReported(): void
    {
        // Arrange
        $this->write('src/Controllers/Things.php', <<<'PHP'
<?php
$incoming = $request->getParam('message', '', 'get');
$legacy   = $_GET['error'] ?? '';
PHP);

        // Act
        $result = $this->check(['rules' => ['flash-query-params']]);

        // Assert
        $this->assertSame([], $result['findings']);
    }

    /**
     * A view variable that collides with the View engine is reported.
     *
     * @return void
     */
    public function testViewReservedPropsAreReported(): void
    {
        // Arrange
        $this->write('src/Controllers/Pages.php', <<<'PHP'
<?php
$view = $this->getView();
$view->sections = $sections;
$view->model    = $model;
PHP);

        // Act
        $result = $this->check(['rules' => ['view-reserved-props']]);

        // Assert
        $this->assertCount(2, $result['findings']);
        $names = array_column($result['findings'], 'summary');
        $this->assertStringContainsString('$sections', $names[0]);
        $this->assertStringContainsString('$model', $names[1]);
    }

    /**
     * The same words on something that is not a view are not reported.
     *
     * **The lesson this tool was built around.** A check in this framework's history flagged
     * `var rows` in six unrelated functions because it matched an identifier rather than a
     * construction, and was deleted for it. `$config->path = …` is not a view variable, and a
     * comparison is not an assignment.
     *
     * @return void
     */
    public function testTheSameNamesElsewhereAreNotReported(): void
    {
        // Arrange
        $this->write('src/Service.php', <<<'PHP'
<?php
$config->path   = '/tmp';
$this->model    = $model;
$report->model  = $model;
if ($view->model == $other) { }
PHP);

        // Act
        $result = $this->check(['rules' => ['view-reserved-props']]);

        // Assert
        $this->assertSame([], $result['findings']);
    }

    /**
     * A migration on the baseline epoch is reported, by filename.
     *
     * @return void
     */
    public function testABaselineMigrationTimestampIsReported(): void
    {
        // Arrange
        $this->write('database/migrations/2020_01_01_000009_add_thing.php', "<?php\n");
        $this->write('database/migrations/2026_08_17_000001_ok.php', "<?php\n");

        // Act
        $result = $this->check(['rules' => ['baseline-migration-timestamp']]);

        // Assert
        $this->assertCount(1, $result['findings']);
        $this->assertStringContainsString('2020_01_01', $result['findings'][0]['excerpt']);
        $this->assertStringContainsString('migration_cutoff', $result['findings'][0]['why']);
    }

    /**
     * The suppression the finding tells you to write actually suppresses it.
     *
     * This rule is file-level — it has no line to point at, so it checks the whole file for the
     * comment. The pattern anchored `$` without the multiline modifier, which anchors to the end
     * of the *string*: the comment counted only if it happened to be the last line of the file,
     * and the escape hatch the finding's own `fix` text tells you to use did nothing.
     *
     * Asserted with the comment in the middle of the file, which is where anybody would put it.
     *
     * @return void
     */
    public function testTheSuppressionTheFindingRecommendsWorks(): void
    {
        // Arrange — the comment above the class, not at the end of the file
        $this->write(
            'database/migrations/2020_01_01_000009_add_thing.php',
            "<?php\n\n"
            . "// pramnos-check: ignore baseline-migration-timestamp — original baseline migration\n"
            . "class AddThing extends Migration\n{\n    public function up(): void {}\n}\n"
        );

        // Act
        $result = $this->check(['rules' => ['baseline-migration-timestamp']]);

        // Assert
        $this->assertSame([], $result['findings']);
    }

    /**
     * A suppression with no reason is not a suppression.
     *
     * The comment has to say why, because the reason is the whole difference between a rule
     * somebody thought about and a rule somebody silenced.
     *
     * @return void
     */
    public function testASuppressionWithoutAReasonIsNotOne(): void
    {
        // Arrange
        $this->write(
            'database/migrations/2020_01_01_000009_add_thing.php',
            "<?php\n// pramnos-check: ignore baseline-migration-timestamp\nclass AddThing {}\n"
        );

        // Act
        $result = $this->check(['rules' => ['baseline-migration-timestamp']]);

        // Assert
        $this->assertCount(1, $result['findings']);
    }

    /**
     * A second reader of the `_debug` payload is reported, when the shipped panel is present.
     *
     * Identified by the construction — consuming `_debug` — rather than by a filename that
     * looks debug-ish, because only a panel has a reason to read that key.
     *
     * @return void
     */
    public function testASecondDebugPanelIsReported(): void
    {
        // Arrange
        $this->write('frontend/lib/debug.js', "export function panel(r) { return r._debug; }\n");
        $this->write('frontend/MyPanel.svelte', "<script>const d = res._debug;</script>\n");

        // Act
        $result = $this->check(['rules' => ['duplicate-debug-panel']]);

        // Assert
        $this->assertCount(1, $result['findings']);
        $this->assertSame('frontend/MyPanel.svelte', $result['findings'][0]['file']);
    }

    /**
     * With no shipped panel present, reading `_debug` is not a duplicate of anything.
     *
     * A project scaffolded before `lib/debug.js` existed has to read the payload itself, and
     * telling it off for that is telling it to stop doing the only thing available.
     *
     * @return void
     */
    public function testReadingTheDebugPayloadAloneIsNotReported(): void
    {
        // Arrange
        $this->write('frontend/MyPanel.svelte', "<script>const d = res._debug;</script>\n");

        // Act
        $result = $this->check(['rules' => ['duplicate-debug-panel']]);

        // Assert
        $this->assertSame([], $result['findings']);
    }

    /**
     * A suppression with a reason silences the finding.
     *
     * @return void
     */
    public function testASuppressionWithAReasonWorksOnBothLines(): void
    {
        // Arrange
        $this->write('src/Repo.php', <<<'PHP'
<?php
// pramnos-check: ignore raw-sql — recursive CTE the builder cannot express
$a = $db->query('SELECT * FROM tree');
$b = $db->query('SELECT * FROM tree'); // pramnos-check: ignore raw-sql — same query, same reason
PHP);

        // Act
        $result = $this->check(['rules' => ['raw-sql', 'unexplained-suppression']]);

        // Assert
        $this->assertSame([], $result['findings']);
    }

    /**
     * A suppression with no reason suppresses nothing, and is itself reported.
     *
     * The reason is the mechanism's entire value: rule 12 asks for "a one-line comment saying
     * why" precisely so the next reader can tell a considered exception from an oversight. A
     * bare `ignore` would turn this tool into a way of hiding findings.
     *
     * @return void
     */
    public function testASuppressionWithNoReasonIsItselfReported(): void
    {
        // Arrange
        $this->write('src/Repo.php', <<<'PHP'
<?php
// pramnos-check: ignore raw-sql
$a = $db->query('SELECT * FROM tree');
PHP);

        // Act
        $result = $this->check(['rules' => ['raw-sql', 'unexplained-suppression']]);

        // Assert — both: the unexplained suppression, and the finding it failed to silence
        $rules = $this->rulesFired($result);
        sort($rules);
        $this->assertSame(['raw-sql', 'unexplained-suppression'], $rules);
    }

    /**
     * A subtree can be checked on its own.
     *
     * @return void
     */
    public function testASubtreeCanBeCheckedAlone(): void
    {
        // Arrange
        $this->write('src/A/Bad.php', "<?php\n\$db->query('SELECT * FROM t');\n");
        $this->write('src/B/AlsoBad.php', "<?php\n\$db->query('SELECT * FROM t');\n");

        // Act
        $result = $this->check(['path' => 'src/A', 'rules' => ['raw-sql']]);

        // Assert
        $this->assertCount(1, $result['findings']);
        $this->assertSame('src/A/Bad.php', $result['findings'][0]['file']);
    }

    /**
     * A path outside the project is refused.
     *
     * It arrives from a model, and a rule checker is not a way to read arbitrary files.
     *
     * @return void
     */
    public function testAPathOutsideTheProjectIsRefused(): void
    {
        // Act
        $result = $this->check(['path' => '../../../etc']);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('findings', $result);
    }

    /**
     * `vendor/` is never scanned.
     *
     * The framework's own code is not the caller's to fix, and scanning it would have this
     * tool report the framework to itself — at length, since the framework has its own backlog
     * against these rules.
     *
     * @return void
     */
    public function testVendorIsNeverScanned(): void
    {
        // Arrange
        $this->write('vendor/pramnos/framework/src/Thing.php', "<?php\n\$db->query('SELECT * FROM t');\n");
        $this->write('node_modules/pkg/index.js', "const d = res._debug;\n");
        $this->write('src/Fine.php', "<?php\nclass Fine {}\n");

        // Act
        $result = $this->check();

        // Assert
        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['checked'], 'Only src/Fine.php is the project.');
    }

    /**
     * An unknown rule name is refused with the list of real ones.
     *
     * @return void
     */
    public function testAnUnknownRuleIsRefusedWithTheAvailableOnes(): void
    {
        // Arrange
        $this->write('src/Fine.php', "<?php\nclass Fine {}\n");

        // Act
        $result = $this->check(['rules' => ['no-such-rule']]);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('raw-sql', $result['available']);
    }

    /**
     * Findings are ordered by file and line, so two runs read the same.
     *
     * @return void
     */
    public function testFindingsAreOrdered(): void
    {
        // Arrange
        $this->write('src/Z.php', "<?php\n\$db->query('SELECT * FROM t');\n");
        $this->write('src/A.php', "<?php\n\n\n\$db->query('SELECT * FROM t');\n\$db->query('SELECT * FROM u');\n");

        // Act
        $findings = $this->check(['rules' => ['raw-sql']])['findings'];

        // Assert
        $this->assertSame(
            [['src/A.php', 4], ['src/A.php', 5], ['src/Z.php', 2]],
            array_map(fn(array $f): array => [$f['file'], $f['line']], $findings)
        );
    }

    /**
     * The tool describes itself as something to run before finishing.
     *
     * The description is all a model reads when deciding whether to call this, so it is part
     * of the feature rather than decoration on it.
     *
     * @return void
     */
    public function testTheDescriptionSaysWhenToRunIt(): void
    {
        // Act
        $tool = new PramnosCheckTool($this->project);

        // Assert
        $this->assertSame('pramnos-check', $tool->name());
        $this->assertStringContainsString('before calling a change finished', $tool->description());
        $this->assertArrayHasKey('path', $tool->inputSchema()['properties']);
        $this->assertArrayHasKey('rules', $tool->inputSchema()['properties']);
    }

    // ── narrowing to the diff ────────────────────────────────────────────────

    /**
     * Runs git in the fixture project.
     */
    private function git(string $arguments): void
    {
        exec(
            'git -c ' . escapeshellarg('safe.directory=' . $this->project)
            . ' -C ' . escapeshellarg($this->project) . ' ' . $arguments . ' 2>&1'
        );
    }

    /**
     * A fixture project that is a git repository, with one committed violation.
     *
     * The committed one stands for the seventy-six findings this tool reports on the real
     * codebase: all older than whatever is being worked on, and the reason nobody ran it.
     */
    private function repositoryWithAnOldViolation(): void
    {
        exec('git --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $this->git('init --quiet');
        $this->git('config user.email test@example.com');
        $this->git('config user.name Test');

        $this->write('src/Old.php', "<?php\n\$db->query('SELECT * FROM users');\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
    }

    /**
     * `since` hides the findings that are not from this change.
     *
     * The whole reason the option exists. Run over `src/`, this tool reports nine raw-SQL
     * findings and sixty-seven flash-query-parameter ones — its own guide says so — and with
     * seventy-six pre-existing findings there is no way to see your own three. So it was never
     * run, including by the assistant instructed to run it before calling a change finished.
     */
    public function testSinceHidesFindingsThatAreNotFromThisChange(): void
    {
        // Arrange
        $this->repositoryWithAnOldViolation();

        // Act
        $everything = $this->check(['rules' => ['raw-sql']]);
        $mine       = $this->check(['rules' => ['raw-sql'], 'since' => 'HEAD']);

        // Assert
        $this->assertCount(1, $everything['findings'], 'the old violation is still there');
        $this->assertSame([], $mine['findings']);
        $this->assertSame(1, $mine['suppressed']);
        $this->assertStringContainsString('not from this change', (string) $mine['note']);
        $this->assertStringContainsString('lines you changed', $mine['verdict']);
    }

    /**
     * A violation on a line you wrote is reported.
     *
     * The other half, and the one that makes the option a gate rather than a way to see
     * nothing: it has to be able to fail.
     */
    public function testAViolationOnAChangedLineIsReported(): void
    {
        // Arrange
        $this->repositoryWithAnOldViolation();
        $this->write('src/New.php', "<?php\n\$db->query('DELETE FROM sessions');\n");

        // Act
        $answer = $this->check(['rules' => ['raw-sql'], 'since' => 'HEAD']);

        // Assert
        $this->assertCount(1, $answer['findings']);
        $this->assertSame('src/New.php', $answer['findings'][0]['file']);
        $this->assertSame(1, $answer['suppressed'], 'the committed one is still suppressed');
    }

    /**
     * Editing one line of a file does not surface that file's other violations.
     *
     * The precise behaviour that makes the answer trustworthy: a one-line fix in a legacy file
     * must not report the fifty findings that were already there, or the option is only a
     * file-level filter with a misleading name.
     */
    public function testTouchingAFileDoesNotSurfaceItsOlderViolations(): void
    {
        // Arrange — two violations committed, only the second line edited
        $this->repositoryWithAnOldViolation();
        $this->write(
            'src/Old.php',
            "<?php\n\$db->query('SELECT * FROM users');\n\$x = 1;\n"
        );

        // Act
        $answer = $this->check(['rules' => ['raw-sql'], 'since' => 'HEAD']);

        // Assert
        $this->assertSame([], $answer['findings'],
            'the untouched line 2 stays out of it');
        $this->assertSame(1, $answer['changed_files']);
    }

    /**
     * `staged` narrows to the index, so it works as a pre-commit gate.
     */
    public function testStagedNarrowsToTheIndex(): void
    {
        // Arrange
        $this->repositoryWithAnOldViolation();
        $this->write('src/Staged.php', "<?php\n\$db->query('SELECT 1 FROM a');\n");
        $this->git('add src/Staged.php');
        $this->write('src/Unstaged.php', "<?php\n\$db->query('SELECT 1 FROM b');\n");

        // Act
        $answer = $this->check(['rules' => ['raw-sql'], 'since' => 'staged']);
        $files  = array_column($answer['findings'], 'file');

        // Assert
        $this->assertContains('src/Staged.php', $files);
        $this->assertNotContains('src/Unstaged.php', $files);
    }

    /**
     * Outside a repository it refuses rather than reporting a clean change.
     *
     * Silence is the one answer a gate must never give by accident: "no findings on the lines
     * you changed" when nothing was compared would be a pass nobody earned.
     */
    public function testWithoutARepositoryItRefusesRatherThanPassing(): void
    {
        // Arrange — a violation, and no git
        $this->write('src/Thing.php', "<?php\n\$db->query('SELECT * FROM users');\n");

        // Act
        $answer = $this->check(['rules' => ['raw-sql'], 'since' => 'HEAD']);

        // Assert
        $this->assertStringContainsString('Not a git working tree', $answer['error']);
        $this->assertArrayNotHasKey('verdict', $answer);
        $this->assertStringContainsString('baseline', (string) $answer['note']);
    }
}
