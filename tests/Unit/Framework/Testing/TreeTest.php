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
     * `read()` hands back what is in the file.
     *
     * The third place the blink shows up and the easiest to write carelessly: a sweep
     * lists a directory, then reads each path it was given, and the read is the call that
     * answers `false` with a warning PHPUnit turns into an error.
     */
    public function testReadReturnsTheContents(): void
    {
        // Arrange
        $path = $this->root . '/notes.md';

        // Act + Assert
        $this->assertSame('#', Tree::read($path));
    }

    /**
     * An empty file is `''`, not a failure.
     *
     * The distinction the retry depends on: `file_get_contents()` answers `''` for an
     * empty file and `false` for one it could not read, so only the second is worth a
     * second look. Reading them as the same thing would put a 50 ms pause on every empty
     * file in a sweep — and then raise on one.
     */
    public function testAnEmptyFileIsNotAFailure(): void
    {
        // Arrange
        $path = $this->root . '/empty.php';
        file_put_contents($path, '');

        // Act + Assert
        $this->assertSame('', Tree::read($path));

        @unlink($path);
    }

    /**
     * A file that is not there raises, after looking twice, and names itself.
     *
     * The message has to separate the two causes, because the actions are opposite: a
     * blinking mount is something to re-run, a deleted file is something to go and find.
     */
    public function testReadRaisesForAFileThatIsNotThere(): void
    {
        // Arrange
        $path = $this->root . '/no-such-file.php';

        // Act
        $started = microtime(true);
        $message = '';

        try {
            Tree::read($path);
        } catch (\RuntimeException $ex) {
            $message = $ex->getMessage();
        }

        $elapsed = microtime(true) - $started;

        // Assert — captured and checked outside the catch, so a failed expectation here
        // is not swallowed by it
        $this->assertStringContainsString('no-such-file.php', $message);
        $this->assertStringContainsString('times', $message);
        $this->assertGreaterThan(0.04, $elapsed, 'it answered without looking a second time');
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
     *       one attempt and several return the same exception. Timing is the only
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
        $attempts = (new \ReflectionClassConstant(Tree::class, 'ATTEMPTS'))->getValue();
        $this->assertGreaterThan(0, $pause, 'there is no pause to measure');
        $this->assertGreaterThan(1, $attempts, 'a single attempt is not a retry');

        // Act
        $start = microtime(true);
        try {
            Tree::files($this->root . '/not-here');
        } catch (\RuntimeException) {
            // expected
        }
        $elapsed = (microtime(true) - $start) * 1_000_000;

        // Assert — every pause between attempts happened, so every attempt followed it
        $this->assertGreaterThanOrEqual(
            $pause * ($attempts - 1),
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
     * `matching()` answers a glob, and looks twice before believing nothing matched.
     *
     * The other half of the same problem. `glob()` returns `false` on failure, which is
     * indistinguishable from "nothing matched" to any caller writing `glob($p) ?: []` —
     * and that is all of them. The sweeps using it assert their result is non-empty, so
     * a mount blink is a red test rather than a silent pass; that is the right half of
     * the fix and not the whole of it, because the red is a failure nobody can act on.
     *
     * One fired in a full run the day after {@see Tree::files()} landed, over a
     * directory whose mtime had not moved since August.
     */
    public function testMatchingAnswersAGlob(): void
    {
        // Act
        $found = Tree::matching($this->root . '/*.php');

        // Assert
        $this->assertSame([$this->root . '/a.php', $this->root . '/b.php'], $found);
    }

    /**
     * A pattern that matches nothing comes back empty rather than `false`.
     *
     * The caller decides whether empty is allowed — every sweep in this suite asserts it
     * is not — and it can only decide that if it is handed a list. Returning `false`
     * here is how `glob()` made the two indistinguishable in the first place.
     */
    public function testAPatternThatMatchesNothingIsAnEmptyList(): void
    {
        // Act
        $found = Tree::matching($this->root . '/*.nothing');

        // Assert
        $this->assertSame([], $found);
    }

    /**
     * And it waits before answering "nothing", for the same reason `files()` does.
     *
     * Timing again, and a floor rather than a window: a second `glob()` cannot happen in
     * less time than the pause before it, and a test that fails when the machine is busy
     * is a test that gets deleted.
     */
    public function testMatchingLooksTwiceBeforeReportingNothing(): void
    {
        // Arrange
        $pause = (new \ReflectionClassConstant(Tree::class, 'RETRY_PAUSE_MICROSECONDS'))
            ->getValue();

        // Act
        $start = microtime(true);
        Tree::matching($this->root . '/*.nothing');
        $elapsed = (microtime(true) - $start) * 1_000_000;

        // Assert
        $this->assertGreaterThanOrEqual(
            $pause,
            $elapsed,
            'it reported nothing without looking again'
        );
    }

    /**
     * A pattern that does match is answered at once.
     *
     * The other side of the timing assertion, and the one that keeps the retry from
     * becoming a 50ms tax on every sweep in the suite — which, across the number of
     * `glob()` calls here, would be measurable.
     */
    public function testAMatchIsNotDelayed(): void
    {
        // Arrange
        $pause = (new \ReflectionClassConstant(Tree::class, 'RETRY_PAUSE_MICROSECONDS'))
            ->getValue();

        // Act
        $start = microtime(true);
        Tree::matching($this->root . '/*.php');
        $elapsed = (microtime(true) - $start) * 1_000_000;

        // Assert
        $this->assertLessThan($pause, $elapsed, 'a successful match paid the retry pause');
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
