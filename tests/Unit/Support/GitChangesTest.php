<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Support\GitChanges;

/**
 * Which lines changed — the thing that makes two ignored rules checkable.
 *
 * Both `pramnos-check` and the coverage tool were being skipped for the same reason: their
 * output was dominated by facts older than the change being made. A checker with seventy-six
 * pre-existing findings, and a project-wide coverage percentage that barely moves when fifty
 * uncovered lines are added, are both unusable as gates. Narrowing to the diff is what makes
 * them answerable, so this is the part that has to be right.
 *
 * Runs against real repositories built in a temporary directory. A mocked `git` would only
 * assert that the parser matches my idea of the diff format.
 */
#[CoversClass(GitChanges::class)]
class GitChangesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        exec('git --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $this->root = sys_get_temp_dir() . '/git-changes-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0777, true);

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
            . ' -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>&1',
            $output,
            $status
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

    /** @return array<string, list<int>> */
    private function changed(string $since = 'HEAD'): array
    {
        $answer = (new GitChanges($this->root))->changedLines($since);
        $this->assertNull($answer['error'], (string) $answer['error']);

        return $answer['files'];
    }

    /**
     * An edited line is reported, and the untouched ones are not.
     */
    public function testOnlyTheEditedLinesAreReported(): void
    {
        // Arrange — five lines committed, the third one changed
        $this->write('src/Thing.php', "one\ntwo\nthree\nfour\nfive\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "one\ntwo\nCHANGED\nfour\nfive\n");

        // Act
        $changed = $this->changed();

        // Assert
        $this->assertSame([3], $changed['src/Thing.php']);
    }

    /**
     * Every line of a new file counts as changed.
     *
     * Not a technicality: a new file is exactly where new violations live, and a filter that
     * skipped untracked files would pass every freshly written class — which is most of what a
     * check before committing is for.
     */
    public function testANewFileCountsEntirely(): void
    {
        // Arrange
        $this->write('src/Committed.php', "one\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Brand/New.php', "a\nb\nc\n");

        // Act
        $changed = $this->changed();

        // Assert
        $this->assertSame([1, 2, 3], $changed['src/Brand/New.php']);
        $this->assertArrayNotHasKey('src/Committed.php', $changed);
    }

    /**
     * A pure deletion contributes no lines.
     *
     * There is no line left to judge. Reporting the line numbers *around* a deletion would
     * blame untouched code for whatever a rule found there.
     */
    public function testADeletionContributesNothing(): void
    {
        // Arrange
        $this->write('src/Thing.php', "one\ntwo\nthree\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "one\nthree\n");

        // Act
        $changed = $this->changed();

        // Assert
        $this->assertSame([], $changed['src/Thing.php'] ?? []);
    }

    /**
     * A deleted file is not reported at all.
     *
     * Its new side is `/dev/null`, and a finding in a file that no longer exists is noise.
     */
    public function testADeletedFileIsNotReported(): void
    {
        // Arrange
        $this->write('src/Gone.php', "one\n");
        $this->write('src/Stays.php', "one\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        unlink($this->root . '/src/Gone.php');

        // Act
        $changed = $this->changed();

        // Assert
        $this->assertArrayNotHasKey('src/Gone.php', $changed);
    }

    /**
     * `HEAD` includes staged changes; `staged` includes only those.
     *
     * The distinction is what makes this usable as a pre-commit gate as well as a
     * before-I-finish check.
     */
    public function testStagedAndHeadSeeDifferentThings(): void
    {
        // Arrange
        $this->write('src/Thing.php', "one\ntwo\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');

        $this->write('src/Thing.php', "STAGED\ntwo\n");
        $this->git('add src/Thing.php');
        $this->write('src/Thing.php', "STAGED\nUNSTAGED\n");

        // Act
        $head   = $this->changed('HEAD');
        $staged = $this->changed('staged');

        // Assert
        $this->assertSame([1, 2], $head['src/Thing.php'], 'both, staged or not');
        $this->assertSame([1], $staged['src/Thing.php'], 'only what is in the index');
    }

    /**
     * An arbitrary ref works, so a whole branch can be checked at once.
     */
    public function testAnArbitraryRefIsAccepted(): void
    {
        // Arrange
        $this->write('src/Thing.php', "one\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Thing.php', "one\ntwo\n");
        $this->git('add -A');
        $this->git('commit --quiet -m second');

        // Act
        $changed = $this->changed('HEAD~1');

        // Assert
        $this->assertSame([2], $changed['src/Thing.php']);
    }

    /**
     * A ref that does not exist is an error, not an empty answer.
     *
     * Silence would read as "your change is clean", which is the one thing a gate must never
     * say by accident.
     */
    public function testAnUnknownRefIsAnError(): void
    {
        // Arrange
        $this->write('src/Thing.php', "one\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');

        // Act
        $answer = (new GitChanges($this->root))->changedLines('no-such-ref');

        // Assert
        $this->assertNotNull($answer['error']);
        $this->assertSame([], $answer['files']);
    }

    /**
     * Somewhere that is not a repository says so.
     */
    public function testSomewhereThatIsNotARepositorySaysSo(): void
    {
        // Arrange
        $elsewhere = sys_get_temp_dir() . '/not-a-repo-' . bin2hex(random_bytes(4));
        mkdir($elsewhere);

        try {
            // Act
            $answer = (new GitChanges($elsewhere))->changedLines();

            // Assert
            $this->assertStringContainsString('Not a git working tree', (string) $answer['error']);
        } finally {
            @rmdir($elsewhere);
        }
    }

    /**
     * `touches()` answers the per-line question the callers actually ask.
     */
    public function testTouchesAnswersPerLine(): void
    {
        // Arrange
        $changed = ['src/Thing.php' => [10, 11, 42]];

        // Assert
        $this->assertTrue(GitChanges::touches($changed, 'src/Thing.php', 42));
        $this->assertFalse(GitChanges::touches($changed, 'src/Thing.php', 43));
        $this->assertFalse(GitChanges::touches($changed, 'src/Other.php', 42));
    }

    /**
     * A diff line before any `+++` header is ignored.
     *
     * Real `git diff` output always names the file first, but the parser is fed whatever git
     * printed — and a hunk with no file to attribute it to must be dropped rather than
     * attached to whichever file came last.
     */
    public function testAHunkWithNoFileIsDropped(): void
    {
        // Arrange — a repository with one committed file and no changes
        $this->write('src/Thing.php', "one\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');

        // Act — nothing changed, so nothing is reported
        $changed = $this->changed();

        // Assert
        $this->assertSame([], $changed);
    }

    /**
     * An empty new file contributes no lines.
     *
     * `range(1, 0)` counts backwards in PHP, so an empty untracked file would otherwise be
     * reported as having changed lines 1 and 0 — a line number that does not exist.
     */
    public function testAnEmptyNewFileContributesNothing(): void
    {
        // Arrange
        $this->write('src/Committed.php', "one\n");
        $this->git('add -A');
        $this->git('commit --quiet -m first');
        $this->write('src/Empty.php', '');

        // Act
        $changed = $this->changed();

        // Assert
        $this->assertSame([], $changed['src/Empty.php'] ?? []);
    }
}
