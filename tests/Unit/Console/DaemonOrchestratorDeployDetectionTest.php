<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * The supervisor still notices a deploy after `git gc` packs the refs.
 *
 * `getCurrentGitHash()` read `.git/HEAD`, followed the `ref:` to
 * `.git/refs/heads/<branch>`, and returned `''` when that file was not there. Which is
 * right until `git gc` packs the refs into `.git/packed-refs` and deletes the loose files —
 * and `git gc --auto` does that unattended, after enough loose objects accumulate, on a
 * schedule nobody chose.
 *
 * **The failure shape is why this has a test rather than a one-line fix and a shrug.**
 * Nothing errors. An unreadable hash is correctly treated as *cannot tell*, the loop keeps
 * the last known value, and deploys simply stop restarting anything: no exception, no log
 * line, no failed deploy — new code sitting on disk while the old process runs. Reported
 * from an installation where the feature had been working for months, and it is the same
 * ninety-minute silence the self-restart exists to end, arriving by a second route.
 *
 * The fix is to ask `Framework\GitInfo`, which already handles both layouts. This test is
 * here so the reader is never reintroduced.
 */
#[CoversClass(DaemonOrchestrator::class)]
class DaemonOrchestratorDeployDetectionTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/pramnos-repo-' . bin2hex(random_bytes(4));
        @mkdir($this->repo . '/.git/refs/heads', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (array(
            '/.git/refs/heads/main', '/.git/packed-refs', '/.git/HEAD',
        ) as $file) {
            @unlink($this->repo . $file);
        }

        @rmdir($this->repo . '/.git/refs/heads');
        @rmdir($this->repo . '/.git/refs');
        @rmdir($this->repo . '/.git');
        @rmdir($this->repo);

        parent::tearDown();
    }

    /**
     * The orchestrator's hash reader, pointed at a repository this test builds.
     */
    private function orchestrator(): object
    {
        $repo = $this->repo;

        return new class ($repo) extends DaemonOrchestrator {
            public function __construct(private readonly string $repo)
            {
                // The real constructor wants an application; this reads a file.
            }

            public function hash(): string
            {
                return $this->getCurrentGitHash();
            }

            /** Where the repository is, since `ROOT` is the suite's own. */
            protected function repoRootForGit(): string
            {
                return $this->repo;
            }

            protected function buildDesiredProcesses(): array
            {
                return [];
            }

            protected function getDashboardTitle(): string
            {
                return 'probe';
            }

            protected function getEntryPoint(): string
            {
                return 'pramnos';
            }

            protected function getJobName(): string
            {
                return 'probe';
            }
        };
    }

    /**
     * A loose ref is read, which is the layout that already worked.
     *
     * The control. A fix that only understood `packed-refs` would break every checkout that
     * has not been packed — which is every fresh clone and every development machine.
     */
    public function testALooseRefIsRead(): void
    {
        // Arrange
        $sha = str_repeat('a', 40);
        file_put_contents($this->repo . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->repo . '/.git/refs/heads/main', $sha . "\n");

        // Assert
        $this->assertSame($sha, $this->orchestrator()->hash());
    }

    /**
     * A packed ref is read, which is the case that silently stopped deploys.
     *
     * `git gc` deletes the loose file, so this is the state a long-lived repository is in.
     */
    public function testAPackedRefIsRead(): void
    {
        // Arrange — exactly what `git gc` leaves: no loose file, a packed-refs with a header
        $sha = str_repeat('b', 40);
        file_put_contents($this->repo . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->repo . '/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n"
            . $sha . " refs/heads/main\n"
            . str_repeat('c', 40) . " refs/tags/v1.0\n"
            . '^' . str_repeat('d', 40) . "\n"
        );

        // Assert
        $this->assertSame($sha, $this->orchestrator()->hash(), 'a packed ref was not read');
    }

    /**
     * A detached HEAD is its own hash.
     *
     * What a deploy that checks out a tag or a commit produces, and a shape with no ref to
     * follow at all.
     */
    public function testADetachedHeadIsItsOwnHash(): void
    {
        // Arrange
        $sha = str_repeat('e', 40);
        file_put_contents($this->repo . '/.git/HEAD', $sha . "\n");

        // Assert
        $this->assertSame($sha, $this->orchestrator()->hash());
    }

    /**
     * With nothing readable the answer is empty, and that is what "cannot tell" looks like.
     *
     * The loop keeps its last known hash on an empty answer rather than treating it as a
     * change — so a repository that cannot be read does not trigger a restart loop. Which is
     * correct, and is also exactly why the packed-refs hole was silent: the safe behaviour on
     * *cannot tell* is indistinguishable from *nothing changed*.
     */
    public function testAnUnreadableRepositoryAnswersEmpty(): void
    {
        // Arrange — a HEAD pointing at a ref that exists in neither layout
        file_put_contents($this->repo . '/.git/HEAD', "ref: refs/heads/gone\n");

        // Assert
        $this->assertSame('', $this->orchestrator()->hash());
    }

    /**
     * And no repository at all is empty rather than an error.
     *
     * A deployment from an archive or a composer package has no `.git`, and a supervisor must
     * start there — it simply never detects a deploy, which is the honest answer.
     */
    public function testNoRepositoryAnswersEmpty(): void
    {
        // Arrange
        @unlink($this->repo . '/.git/HEAD');

        // Assert
        $this->assertSame('', $this->orchestrator()->hash());
    }
}
