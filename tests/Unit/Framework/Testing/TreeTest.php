<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\Tree;

/**
 * The directory walk the scaffolding sweeps use, and the two ways it used to go wrong.
 *
 * `Tree` exists because a full run produced `RecursiveDirectoryIterator::__construct(
 * …/scaffolding/themes/tailwind/views): Failed to open directory: No such file or
 * directory` on a directory that is tracked in git and whose host mtime predated the run by
 * seventeen days. The repository is a VirtioFS bind mount, and the container's view of the
 * directory blinked; the disk did not.
 *
 * Both shapes it replaces are wrong in the same way. `RecursiveDirectoryIterator` throws
 * something that reads like a bug in the test, and `glob()` returns `false` so a sweep
 * silently passes over nothing. Neither says *a directory could not be read*, which is the
 * one fact worth having.
 */
#[CoversClass(Tree::class)]
class TreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pramnos_tree_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/nested/deeper', 0777, true);
        file_put_contents($this->root . '/b.php', '<?php');
        file_put_contents($this->root . '/a.php', '<?php');
        file_put_contents($this->root . '/notes.md', '#');
        file_put_contents($this->root . '/nested/c.php', '<?php');
        file_put_contents($this->root . '/nested/deeper/d.php', '<?php');
    }

    protected function tearDown(): void
    {
        foreach ([
            '/nested/deeper/d.php', '/nested/c.php', '/notes.md',
            '/a.php', '/b.php',
        ] as $file) {
            @unlink($this->root . $file);
        }
        @rmdir($this->root . '/nested/deeper');
        @rmdir($this->root . '/nested');
        @rmdir($this->root);
    }

    /**
     * It walks the whole tree, filters by extension, and sorts.
     *
     * Sorted because a failure message built from the list has to be the same on two runs;
     * directory order is not, and a diff that reorders is a diff nobody reads.
     */
    public function testItReturnsEveryMatchingFileSorted(): void
    {
        // Act
        $files = Tree::files($this->root);

        // Assert — the four `.php` files, depth-independent, and not `notes.md`
        $this->assertSame([
            $this->root . '/a.php',
            $this->root . '/b.php',
            $this->root . '/nested/c.php',
            $this->root . '/nested/deeper/d.php',
        ], $files);
    }

    /**
     * A null extension keeps everything.
     *
     * The filter is a convenience for the callers that all want `.php`; a sweep over
     * something else must not have to re-implement the walk to get it.
     */
    public function testANullExtensionKeepsEveryFile(): void
    {
        // Act
        $files = Tree::files($this->root, null);

        // Assert
        $this->assertCount(5, $files);
        $this->assertContains($this->root . '/notes.md', $files);
    }

    /**
     * A directory that is not there raises, and the message names it.
     *
     * **The assertion is that it raises at all.** The `glob()` half of what this replaces
     * returns `false` here, and a sweep built on that reports success having examined
     * nothing — which is the failure mode that hid a `create:crud` bug for months and got
     * forty-five call sites an explicit emptiness check.
     */
    public function testAMissingDirectoryRaisesAndNamesThePath(): void
    {
        // Arrange
        $missing = $this->root . '/not-here';

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($missing);

        // Act
        Tree::files($missing);
    }

    /**
     * And it says where to look, rather than only that it failed.
     *
     * The message carries the one diagnostic that separated the two explanations when this
     * happened: a directory whose host mtime predates the run never went away, so the mount
     * is what blinked. The original exception said `Failed to open directory` and nothing
     * else, which is what made an afternoon of it.
     */
    public function testTheMessageDistinguishesAbsentFromUnreadable(): void
    {
        // Act — the message is captured rather than asserted inside the catch.
        //
        // `$this->fail()` inside a `try` whose `catch` names `\RuntimeException` is
        // swallowed by that catch, because `AssertionFailedError` extends it: the test then
        // passes whatever happened. `FailIsNotSwallowedTest` caught this file doing exactly
        // that, which is what that guard is for.
        $message = null;
        try {
            Tree::files($this->root . '/not-here');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        // Assert
        $this->assertNotNull($message, 'a missing directory did not raise');
        $this->assertStringContainsString('the bind mount is what blinked', $message);
        $this->assertStringContainsString('something removed it', $message);
    }

    /**
     * It really does look twice.
     *
     * WHAT: a call that fails takes at least the retry pause.
     *
     * WHY:  "retries once" is the whole point of the class and is otherwise unobservable —
     *       a single attempt and two attempts return the same exception. Timing is the only
     *       evidence available without a filesystem that can be made to fail on demand, and
     *       it is real evidence: a second attempt cannot happen in less time than the pause
     *       that precedes it.
     *
     *       Asserted as a floor rather than a window, because a loaded machine can take
     *       arbitrarily longer and a test that fails when the box is busy is a test that
     *       gets deleted.
     */
    public function testItLooksTwiceBeforeGivingUp(): void
    {
        // Arrange
        $pause = (new \ReflectionClassConstant(Tree::class, 'RETRY_PAUSE_MICROSECONDS'))
            ->getValue();
        $this->assertGreaterThan(0, $pause, 'there is no pause to measure');

        // Act
        $start = microtime(true);
        try {
            Tree::files($this->root . '/not-here');
        } catch (\RuntimeException) {
            // expected
        }
        $elapsed = (microtime(true) - $start) * 1_000_000;

        // Assert — one pause happened, so a second attempt followed it
        $this->assertGreaterThanOrEqual(
            $pause,
            $elapsed,
            'the call returned too quickly to have waited and looked again'
        );
    }

    /**
     * An empty subdirectory does not appear in the list.
     *
     * WHAT: a directory with nothing in it is neither returned as an entry nor an error.
     *
     * WHY:  this pins the iterator's traversal mode. In `LEAVES_ONLY` — the default, and
     *       what `Tree` relies on — a directory is never yielded, so no `isFile()` filter
     *       is needed and there is none. Under `SELF_FIRST` an empty directory *is*
     *       yielded, and every caller would then try to `file_get_contents()` a directory.
     *       The distinction is invisible in the code and was established by probing the
     *       iterator, so it needs a test rather than a comment: change the mode and this
     *       fails, instead of a sweep failing somewhere unrelated.
     */
    public function testAnEmptySubdirectoryIsNotMistakenForAFile(): void
    {
        // Arrange
        mkdir($this->root . '/nested/hollow');

        try {
            // Act
            $files = Tree::files($this->root, null);

            // Assert
            $this->assertNotContains($this->root . '/nested/hollow', $files);
            $this->assertCount(5, $files, 'the empty directory was counted as a file');
        } finally {
            @rmdir($this->root . '/nested/hollow');
        }
    }

    /**
     * A directory that exists but holds nothing comes back empty, without raising.
     *
     * Empty and unreadable are different answers and the caller decides what to do with
     * each: every sweep in this suite asserts its list is non-empty, and it can only do
     * that if "empty" reaches it as a list rather than as an exception.
     */
    public function testAnEmptyDirectoryIsNotAnError(): void
    {
        // Arrange
        $empty = $this->root . '/empty';
        mkdir($empty);

        try {
            // Act + Assert
            $this->assertSame([], Tree::files($empty));
        } finally {
            @rmdir($empty);
        }
    }
}
