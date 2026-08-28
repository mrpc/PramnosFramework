<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\ChangelogAddTool;

/**
 * `changelog-add` — the only tool here that writes.
 *
 * It earned that by being a ritual that kept going wrong. One post per day, every section listed
 * at the top under a count: appending an entry means rebuilding the list from the headings and
 * getting the count and its plural right. Done by hand a dozen times in one day, that produced a
 * regex that threw and a summary list three entries behind the sections it summarised. Nothing
 * noticed either time — the page renders, and the list is simply wrong.
 *
 * So what is asserted here is mostly what it **refuses** to do. A write tool that is merely
 * usually right is worse than doing it by hand, because nobody checks it.
 */
#[CoversClass(ChangelogAddTool::class)]
class ChangelogAddToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/changelog-add-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/docs/version-history/posts', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function add(array $input): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new ChangelogAddTool($this->root))->execute($input);

        return $answer;
    }

    private function post(string $date = '2026-08-28'): string
    {
        $file = $this->root . '/docs/version-history/posts/' . $date . '.md';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * A new post gets its frontmatter, heading, list and fold.
     *
     * `<!-- more -->` is the fold: everything above it is the excerpt the blog index shows, so
     * the summary belongs above and the sections below. A post without it prints itself in full
     * on the index page.
     */
    public function testANewPostIsWellFormed(): void
    {
        // Act
        $answer = $this->add([
            'title' => 'The first thing',
            'body'  => 'What happened.',
            'date'  => '2026-08-28',
        ]);

        // Assert
        $this->assertTrue($answer['created']);

        $post = $this->post();
        $this->assertStringContainsString("date: 2026-08-28", $post);
        $this->assertStringContainsString('  - Changelog', $post);
        $this->assertStringContainsString('# 28 August 2026', $post);
        $this->assertStringContainsString("1 change:\n\n- The first thing", $post);
        $this->assertStringContainsString('<!-- more -->', $post);
        $this->assertStringContainsString("## The first thing\n\nWhat happened.", $post);

        // The fold comes before the sections, or the index page shows everything
        $this->assertLessThan(
            strpos($post, '## The first thing'),
            strpos($post, '<!-- more -->')
        );
    }

    /**
     * The plural is right, which is the sort of thing hand-editing gets wrong.
     */
    public function testTheCountAndItsPluralAreCorrect(): void
    {
        // Act
        $this->add(['title' => 'One', 'body' => 'a', 'date' => '2026-08-28']);
        $onePost = $this->post();

        $this->add(['title' => 'Two', 'body' => 'b', 'date' => '2026-08-28']);
        $twoPost = $this->post();

        // Assert
        $this->assertStringContainsString('1 change:', $onePost);
        $this->assertStringContainsString('2 changes:', $twoPost);
        $this->assertStringNotContainsString('1 change:', $twoPost);
    }

    /**
     * The summary list is derived from the headings, never edited.
     *
     * The whole point. A list somebody maintains by hand drifts from the sections it describes,
     * and the drift is invisible — this one was three entries behind before anybody noticed. So
     * a list that has been damaged is *repaired* on the next append rather than appended to.
     */
    public function testADamagedSummaryListIsRebuiltFromTheHeadings(): void
    {
        // Arrange — a post whose list has lost an entry and whose count is wrong
        file_put_contents(
            $this->root . '/docs/version-history/posts/2026-08-28.md',
            "---\ndate: 2026-08-28\ncategories:\n  - Changelog\n---\n\n"
            . "# 28 August 2026\n\n7 changes:\n\n- Only one is listed\n\n<!-- more -->\n\n"
            . "## Only one is listed\n\na\n\n## This one was forgotten\n\nb\n"
        );

        // Act
        $answer = $this->add(['title' => 'And now a third', 'body' => 'c', 'date' => '2026-08-28']);

        // Assert
        $post = $this->post();
        $this->assertSame(3, $answer['sections']);
        $this->assertStringContainsString('3 changes:', $post);
        $this->assertStringContainsString('- This one was forgotten', $post);
        $this->assertStringNotContainsString('7 changes:', $post);
    }

    /**
     * A duplicate title is refused rather than added.
     *
     * Two sections with one name make a summary entry that points at whichever the reader finds
     * first.
     */
    public function testADuplicateTitleIsRefused(): void
    {
        // Arrange
        $this->add(['title' => 'Same name', 'body' => 'first', 'date' => '2026-08-28']);

        // Act
        $answer = $this->add(['title' => 'Same name', 'body' => 'second', 'date' => '2026-08-28']);

        // Assert
        $this->assertStringContainsString('already has a section', $answer['error']);
        $this->assertStringContainsString('first', $this->post());
        $this->assertStringNotContainsString('second', $this->post());
    }

    /**
     * `replace` rewrites the section instead, and does not leave two.
     */
    public function testReplaceRewritesRatherThanDuplicating(): void
    {
        // Arrange
        $this->add(['title' => 'Same name', 'body' => 'first', 'date' => '2026-08-28']);
        $this->add(['title' => 'Another', 'body' => 'kept', 'date' => '2026-08-28']);

        // Act
        $answer = $this->add([
            'title'   => 'Same name',
            'body'    => 'second',
            'date'    => '2026-08-28',
            'replace' => true,
        ]);

        // Assert
        $post = $this->post();
        $this->assertSame(2, $answer['sections']);
        $this->assertSame(1, substr_count($post, '## Same name'));
        $this->assertStringContainsString('second', $post);
        $this->assertStringNotContainsString('first', $post);
        $this->assertStringContainsString('kept', $post, 'the other section survived');
    }

    /**
     * `preview` writes nothing.
     *
     * A write tool should be able to show its work first, and this one is called by something
     * that cannot see the file afterwards without asking.
     */
    public function testPreviewWritesNothing(): void
    {
        // Act
        $answer = $this->add([
            'title'   => 'Not written',
            'body'    => 'nothing',
            'date'    => '2026-08-28',
            'preview' => true,
        ]);

        // Assert
        $this->assertTrue($answer['preview']);
        $this->assertSame('', $this->post(), 'the file was not created');
        $this->assertContains('Not written', $answer['sections']);
    }

    /**
     * Categories are merged, not replaced.
     */
    public function testCategoriesAreMerged(): void
    {
        // Arrange
        $this->add([
            'title' => 'First',
            'body'  => 'a',
            'date'  => '2026-08-28',
            'categories' => ['Auth'],
        ]);

        // Act
        $this->add([
            'title' => 'Second',
            'body'  => 'b',
            'date'  => '2026-08-28',
            'categories' => ['Testing', 'Auth'],
        ]);

        // Assert
        $post = $this->post();
        $this->assertStringContainsString('  - Changelog', $post);
        $this->assertStringContainsString('  - Auth', $post);
        $this->assertStringContainsString('  - Testing', $post);
        $this->assertSame(1, substr_count($post, '  - Auth'), 'not added twice');
    }

    /**
     * The inline frontmatter form is left alone rather than rewritten.
     *
     * `categories: [Changelog]` is what one existing post uses. Reformatting somebody's
     * frontmatter in order to add one entry is a bigger change than the entry.
     */
    public function testTheInlineCategoryFormIsNotRewritten(): void
    {
        // Arrange
        file_put_contents(
            $this->root . '/docs/version-history/posts/2026-08-28.md',
            "---\ndate: 2026-08-28\ncategories: [Changelog]\n---\n\n"
            . "# 28 August 2026\n\n0 changes:\n\n<!-- more -->\n"
        );

        // Act
        $this->add([
            'title' => 'A thing',
            'body'  => 'a',
            'date'  => '2026-08-28',
            'categories' => ['Auth'],
        ]);

        // Assert
        $this->assertStringContainsString('categories: [Changelog]', $this->post());
    }

    /**
     * Bad input is refused before anything is touched.
     */
    public function testBadInputIsRefused(): void
    {
        // Assert
        $this->assertStringContainsString(
            'required',
            $this->add(['title' => '', 'body' => 'a'])['error']
        );
        $this->assertStringContainsString(
            'required',
            $this->add(['title' => 'a', 'body' => ''])['error']
        );
        $this->assertStringContainsString(
            'one line',
            $this->add(['title' => "two\nlines", 'body' => 'a'])['error']
        );
        $this->assertStringContainsString(
            'YYYY-MM-DD',
            $this->add(['title' => 'a', 'body' => 'b', 'date' => 'yesterday'])['error']
        );
    }

    /**
     * Without a changelog directory it refuses, and says this is a framework-repository tool.
     */
    public function testWithoutAChangelogItSaysWhereItBelongs(): void
    {
        // Arrange
        $elsewhere = sys_get_temp_dir() . '/no-changelog-' . bin2hex(random_bytes(4));
        mkdir($elsewhere);

        try {
            // Act
            $answer = (new ChangelogAddTool($elsewhere))->execute([
                'title' => 'a',
                'body'  => 'b',
            ]);

            // Assert
            $this->assertStringContainsString('No changelog at', $answer['error']);
            $this->assertStringContainsString('installed package', $answer['error']);
        } finally {
            @rmdir($elsewhere);
        }
    }

    /**
     * It will not write into an installed package.
     *
     * A changelog entry under `vendor/` is edited into oblivion by the next `composer update`,
     * and belongs in the framework's own history. A development checkout — a symlink, or a git
     * tree — *is* that history, so it counts.
     */
    public function testAnInstalledPackageIsNotWrittenTo(): void
    {
        // Arrange — a project whose vendored framework is a plain directory
        $project = sys_get_temp_dir() . '/installed-' . bin2hex(random_bytes(4));
        mkdir($project . '/vendor/mrpc/pramnosframework/docs/version-history/posts', 0777, true);

        try {
            // Act
            $refused = (new ChangelogAddTool($project))->execute(['title' => 'a', 'body' => 'b']);

            // …and the same project once that package is a git checkout
            mkdir($project . '/vendor/mrpc/pramnosframework/.git');
            $accepted = (new ChangelogAddTool($project))->execute([
                'title' => 'a',
                'body'  => 'b',
                'date'  => '2026-08-28',
            ]);

            // Assert
            $this->assertArrayHasKey('error', $refused);
            $this->assertArrayNotHasKey('error', $accepted);
            $this->assertTrue($accepted['created']);
        } finally {
            exec('rm -rf ' . escapeshellarg($project));
        }
    }

    /**
     * The description says it is the mechanical half of a rule.
     */
    public function testTheDescriptionSaysWhatItIsFor(): void
    {
        // Arrange
        $tool = new ChangelogAddTool($this->root);

        // Assert
        $this->assertSame('changelog-add', $tool->name());
        $this->assertStringContainsString('same commit', $tool->description());
        $this->assertSame(['title', 'body'], $tool->inputSchema()['required']);
    }
}
