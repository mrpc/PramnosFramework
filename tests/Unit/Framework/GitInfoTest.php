<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\GitInfo;

/**
 * Unit tests for GitInfo — pure-PHP git reader.
 *
 * Tests use a temporary fake repository to avoid coupling to the real
 * .git directory of the PramnosFramework repo (which changes with every
 * commit).  Each test creates only the subset of .git files it needs,
 * keeping each scenario isolated and deterministic.
 */
class GitInfoTest extends TestCase
{
    /** Absolute path to the temp repo root created for this test class. */
    private string $repoRoot;

    // ─────────────────────────────────────────────────────────────────────────
    // Fixture helpers
    // ─────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        // Create a minimal fake repo in a temp directory for each test.
        $this->repoRoot = sys_get_temp_dir() . '/pramnos_gitinfo_test_' . uniqid();
        mkdir($this->repoRoot . '/.git/refs/heads', 0755, true);
        mkdir($this->repoRoot . '/.git/refs/remotes/origin', 0755, true);
        mkdir($this->repoRoot . '/.git/objects', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->repoRoot);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Branch detection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getBranch() must return the branch name from a normal symbolic HEAD.
     *
     * When HEAD contains "ref: refs/heads/main", the method strips the prefix
     * and returns just "main".
     */
    public function testGetBranchReturnsNameFromSymbolicRef(): void
    {
        // Arrange
        file_put_contents($this->repoRoot . '/.git/HEAD', "ref: refs/heads/main\n");

        // Act
        $branch = (new GitInfo($this->repoRoot))->getBranch();

        // Assert
        $this->assertSame('main', $branch);
    }

    /**
     * getBranch() must return a short hash when HEAD is in detached state.
     *
     * A detached HEAD contains a raw 40-char hash instead of a ref path.
     * The method returns the first 7 characters as a short identifier.
     */
    public function testGetBranchReturnsShortHashForDetachedHead(): void
    {
        // Arrange
        $hash = 'a' . str_repeat('0', 39); // 40-char fake hash
        file_put_contents($this->repoRoot . '/.git/HEAD', $hash . "\n");

        // Act
        $branch = (new GitInfo($this->repoRoot))->getBranch();

        // Assert — first 7 chars of the hash
        $this->assertSame(substr($hash, 0, 7), $branch);
    }

    /**
     * getBranch() must return '(unknown)' when HEAD is missing entirely.
     *
     * Graceful degradation for broken or empty repositories.
     */
    public function testGetBranchReturnsUnknownWhenHeadMissing(): void
    {
        // Arrange — no HEAD file written; setUp only created directories.

        // Act
        $branch = (new GitInfo($this->repoRoot))->getBranch();

        // Assert
        $this->assertSame('(unknown)', $branch);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hash resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getHash() must resolve a loose ref to its 40-char hash.
     *
     * HEAD → refs/heads/feature → .git/refs/heads/feature contains the hash.
     */
    public function testGetHashResolvesLooseRef(): void
    {
        // Arrange
        $hash = str_repeat('a', 40);
        file_put_contents($this->repoRoot . '/.git/HEAD', "ref: refs/heads/feature\n");
        file_put_contents($this->repoRoot . '/.git/refs/heads/feature', $hash . "\n");

        // Act
        $result = (new GitInfo($this->repoRoot))->getHash();

        // Assert
        $this->assertSame($hash, $result);
    }

    /**
     * getHash() must fall back to packed-refs when no loose ref file exists.
     *
     * Packed-refs is the on-disk compaction format git uses for older/remote refs.
     */
    public function testGetHashFallsBackToPackedRefs(): void
    {
        // Arrange
        $hash = str_repeat('b', 40);
        file_put_contents($this->repoRoot . '/.git/HEAD', "ref: refs/heads/packed-branch\n");
        // No loose ref file — only packed-refs
        file_put_contents(
            $this->repoRoot . '/.git/packed-refs',
            "# pack-refs with: peeled\n{$hash} refs/heads/packed-branch\n",
        );

        // Act
        $result = (new GitInfo($this->repoRoot))->getHash();

        // Assert
        $this->assertSame($hash, $result);
    }

    /**
     * getShortHash() must return the first 7 characters of the resolved hash.
     */
    public function testGetShortHashReturnsSeven(): void
    {
        // Arrange
        $hash = str_repeat('c', 40);
        file_put_contents($this->repoRoot . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->repoRoot . '/.git/refs/heads/main', $hash . "\n");

        // Act
        $short = (new GitInfo($this->repoRoot))->getShortHash();

        // Assert
        $this->assertSame(str_repeat('c', 7), $short);
    }

    /**
     * getShortHash() must return '0000000' as a fallback when HEAD is missing.
     */
    public function testGetShortHashReturnsFallbackWhenMissing(): void
    {
        // Arrange — no HEAD
        // Act
        $short = (new GitInfo($this->repoRoot))->getShortHash();
        // Assert
        $this->assertSame('0000000', $short);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commit object parsing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getSubject(), getAuthor(), getDate() must parse data from a real
     * zlib-compressed commit object.
     *
     * We create a syntactically valid git commit object (compressed with
     * gzcompress which produces zlib format), write it to the fake object
     * store, and verify all three fields.
     */
    public function testCommitFieldsParsedFromObject(): void
    {
        // Arrange — build a minimal commit object payload
        $commitBody = implode("\n", [
            'tree ' . str_repeat('0', 40),
            'author Alice Wonderland <alice@example.com> 1700000000 +0200',
            'committer Alice Wonderland <alice@example.com> 1700000000 +0200',
            '',
            'feat(test): add wonderland feature',
            '',
        ]);
        $raw        = "commit " . strlen($commitBody) . "\0" . $commitBody;
        $compressed = gzcompress($raw);

        $hash    = sha1($raw); // Real sha1 — git uses sha1(header + body)
        $objDir  = $this->repoRoot . '/.git/objects/' . substr($hash, 0, 2);
        mkdir($objDir, 0755, true);
        file_put_contents($objDir . '/' . substr($hash, 2), $compressed);

        // Wire up HEAD → ref → hash
        file_put_contents($this->repoRoot . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->repoRoot . '/.git/refs/heads/main', $hash . "\n");

        $git = new GitInfo($this->repoRoot);

        // Act & Assert — subject
        $this->assertSame('feat(test): add wonderland feature', $git->getSubject());

        // Act & Assert — author name (no angle-bracket email)
        $this->assertSame('Alice Wonderland', $git->getAuthor());

        // Act & Assert — Unix timestamp
        $this->assertSame(1700000000, $git->getDate());
    }

    /**
     * getSubject() must return '' gracefully when the object file is absent.
     *
     * The hash resolves but there is no corresponding file under objects/.
     */
    public function testGetSubjectReturnsEmptyWhenObjectMissing(): void
    {
        // Arrange
        $hash = str_repeat('d', 40);
        file_put_contents($this->repoRoot . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->repoRoot . '/.git/refs/heads/main', $hash . "\n");
        // No object file written

        // Act
        $subject = (new GitInfo($this->repoRoot))->getSubject();

        // Assert
        $this->assertSame('', $subject);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Branch and remote listing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getLocalBranches() must return loose ref names sorted alphabetically.
     */
    public function testGetLocalBranchesFromLooseRefs(): void
    {
        // Arrange — create loose ref files for two branches
        file_put_contents($this->repoRoot . '/.git/refs/heads/main', str_repeat('a', 40));
        file_put_contents($this->repoRoot . '/.git/refs/heads/feature-x', str_repeat('b', 40));

        // Act
        $branches = (new GitInfo($this->repoRoot))->getLocalBranches();

        // Assert — sorted, both present
        $this->assertContains('main', $branches);
        $this->assertContains('feature-x', $branches);
        $this->assertSame(['feature-x', 'main'], $branches);
    }

    /**
     * getLocalBranches() must include branches from packed-refs as well.
     *
     * When git packs refs, they disappear from refs/heads/ and appear in
     * packed-refs.  Both sources must be merged.
     */
    public function testGetLocalBranchesIncludesPackedRefs(): void
    {
        // Arrange — only a packed-refs entry, no loose file
        file_put_contents(
            $this->repoRoot . '/.git/packed-refs',
            "# pack-refs with: peeled\n" . str_repeat('a', 40) . " refs/heads/legacy\n",
        );

        // Act
        $branches = (new GitInfo($this->repoRoot))->getLocalBranches();

        // Assert
        $this->assertContains('legacy', $branches);
    }

    /**
     * getRemotes() must parse remote names from .git/config.
     *
     * Standard git config format: [remote "name"] sections.
     */
    public function testGetRemotesParsedFromConfig(): void
    {
        // Arrange
        $config = <<<'INI'
[core]
	repositoryformatversion = 0
[remote "origin"]
	url = git@github.com:example/repo.git
[remote "upstream"]
	url = https://github.com/upstream/repo.git
INI;
        file_put_contents($this->repoRoot . '/.git/config', $config);

        // Act
        $remotes = (new GitInfo($this->repoRoot))->getRemotes();

        // Assert — both remotes returned
        $this->assertContains('origin', $remotes);
        $this->assertContains('upstream', $remotes);
        $this->assertCount(2, $remotes);
    }

    /**
     * getRemotes() must return an empty array when .git/config is absent.
     */
    public function testGetRemotesReturnsEmptyWithoutConfig(): void
    {
        // Arrange — no config file

        // Act
        $remotes = (new GitInfo($this->repoRoot))->getRemotes();

        // Assert
        $this->assertSame([], $remotes);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Smoke test against the real repo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Against the actual PramnosFramework repository, GitInfo must be able to
     * return a non-empty branch name and a 40-char hash.
     *
     * This smoke test intentionally uses the real .git dir to verify the
     * full end-to-end path (object decompression included).  It is skipped
     * if no .git dir is present (e.g. in a production deploy).
     */
    public function testSmokeAgainstRealRepo(): void
    {
        // Arrange
        $realGitDir = dirname(__DIR__, 4) . '/.git';
        if (!is_dir($realGitDir)) {
            $this->markTestSkipped('No .git directory found — skipping real-repo smoke test.');
        }

        $git = new GitInfo(dirname(__DIR__, 4));

        // Act
        $branch    = $git->getBranch();
        $hash      = $git->getHash();
        $shortHash = $git->getShortHash();

        // Assert — non-empty branch, valid-looking hash
        $this->assertNotEmpty($branch);
        $this->assertNotSame('(unknown)', $branch);
        $this->assertNotNull($hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $hash);
        $this->assertSame(7, strlen($shortHash));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
