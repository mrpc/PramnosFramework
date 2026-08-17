<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Mcp;

use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\FrameworkDocsTool;

/**
 * The framework offers its own guides over MCP, and offers the right ones.
 *
 * `docs/` ships inside the composer package so that whoever works in a consuming project
 * — an AI assistant included — has documentation matching the vendored code. Nothing ever
 * *offered* it: the five other MCP tools introspect the database and the routes, and the
 * registered resources are the application's own files. The only route to a guide was to
 * guess that one should look inside `vendor/`.
 *
 * Two of these tests exist because of mistakes made while writing the tool, and they are
 * the ones worth keeping:
 *
 *   - **the corpus is really found.** A structural helper in this repository once resolved
 *     `dirname(__DIR__, 5)` to a path outside the tree, scanned zero files, and passed
 *     every assertion it made about them. Asserting "no errors" would do the same here.
 *   - **the frozen page does not win.** Measured, not assumed: the first query tried
 *     ranked `1.2-new-features` — a deliberately frozen v1.2 reference — above every live
 *     guide, on body volume alone.
 */
class FrameworkDocsToolTest extends TestCase
{
    /** @var string A fixture corpus, for the cases the real one cannot produce on demand */
    private string $fixtures = '';

    /**
     * Builds a three-page corpus: two guides and one page with no use cases.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixtures = sys_get_temp_dir() . '/pramnos-docs-' . getmypid() . '-' . uniqid();
        mkdir($this->fixtures, 0777, true);

        file_put_contents($this->fixtures . '/Guide_Widgets.md', <<<'MD'
---
use_cases:
  - Adding a widget to a dashboard
  - Removing a widget somebody else added
---

# Widgets

Widgets are arranged by the dashboard.
MD);

        file_put_contents($this->fixtures . '/Guide_Tokens.md', <<<'MD'
---
use_cases:
  - Issuing a bearer token for an API caller
---

# Tokens

## Minting

A token is minted with a signing key.
MD);

        // No frontmatter at all — the shape of the frozen reference and the release index
        file_put_contents($this->fixtures . '/frozen-reference.md', <<<'MD'
# Frozen reference

Widgets, widgets, widgets. Tokens, tokens, tokens. Dashboard dashboard dashboard.
Adding a widget to a dashboard is described here too, at length, repeatedly.
MD);
    }

    /**
     * Removes the fixture corpus.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->fixtures . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->fixtures);
    }

    /**
     * The tool pointed at the fixture corpus.
     *
     * @return FrameworkDocsTool
     */
    private function fixtureTool(): FrameworkDocsTool
    {
        return new FrameworkDocsTool($this->fixtures);
    }

    /**
     * The default path really resolves to this framework's guides.
     *
     * **Asserts a count, deliberately.** The failure this guards is a wrong `dirname()`
     * depth, which produces an empty corpus and no error at all — every softer assertion
     * would pass against a directory that does not exist. A named page is checked as well,
     * so a coincidentally non-empty directory somewhere else cannot satisfy it either.
     *
     * @return void
     */
    public function testTheDefaultPathFindsTheRealGuides(): void
    {
        // Act
        $index = (new FrameworkDocsTool())->execute([]);

        // Assert
        $this->assertArrayNotHasKey('error', $index);
        $this->assertGreaterThan(
            20,
            $index['count'],
            'The vendored corpus is dozens of pages; a handful means the path is wrong.'
        );
        $names = array_column($index['pages'], 'page');
        $this->assertContains('Pramnos_Authentication_Guide', $names);
    }

    /**
     * A page with no use cases does not outrank a guide about the same task.
     *
     * The defect found by measuring. `frozen-reference.md` in the fixtures repeats the
     * query's terms more often than the guide does, exactly as the real frozen page does,
     * and it is the wrong answer: it is history, and history stopped describing current
     * state on purpose.
     *
     * @return void
     */
    public function testAPageWithNoUseCasesDoesNotOutrankAGuide(): void
    {
        // Act
        $results = $this->fixtureTool()
            ->execute(['query' => 'adding a widget to a dashboard'])['results'];

        // Assert
        $this->assertSame('Guide_Widgets', $results[0]['page']);
        $this->assertTrue($results[0]['guidance']);

        // And it is demoted rather than hidden — a reader asking about something only it
        // mentions should still be told it exists.
        $frozen = array_values(array_filter(
            $results,
            fn(array $r): bool => $r['page'] === 'frozen-reference'
        ));
        $this->assertNotSame([], $frozen, 'Demoted, not dropped.');
        $this->assertFalse($frozen[0]['guidance']);
        $this->assertLessThan($results[0]['score'], $frozen[0]['score']);
    }

    /**
     * A use-case match outweighs a body match.
     *
     * The ranking rule the whole corpus convention is built on: use cases are phrased as
     * the task the reader has in hand, so they are the closest thing here to the question
     * an assistant arrives with. `Guide_Tokens` names minting only in its body.
     *
     * @return void
     */
    public function testAUseCaseMatchOutweighsABodyMatch(): void
    {
        // Act
        $results = $this->fixtureTool()
            ->execute(['query' => 'issuing bearer token'])['results'];

        // Assert
        $this->assertSame('Guide_Tokens', $results[0]['page']);
        $this->assertSame(
            ['Issuing a bearer token for an API caller'],
            $results[0]['use_cases'],
            'The frontmatter is parsed, not merely detected.'
        );
    }

    /**
     * Excerpts quote the matching lines with their line numbers.
     *
     * What makes this a search rather than a way to return the whole corpus on every
     * question: the caller can judge whether the full read is worth it.
     *
     * @return void
     */
    public function testExcerptsCarryLineNumbers(): void
    {
        // Act
        $results = $this->fixtureTool()
            ->execute(['query' => 'signing key'])['results'];

        // Assert
        $this->assertNotSame([], $results[0]['excerpts']);
        $this->assertMatchesRegularExpression('/^\d+: /', $results[0]['excerpts'][0]);
        $this->assertStringContainsString('signing key', $results[0]['excerpts'][0]);
    }

    /**
     * A page is returned in full, by name, with or without the suffix.
     *
     * @return void
     */
    public function testAPageIsReadInFullWithOrWithoutTheSuffix(): void
    {
        // Act
        $bare     = $this->fixtureTool()->execute(['page' => 'Guide_Tokens']);
        $suffixed = $this->fixtureTool()->execute(['page' => 'Guide_Tokens.md']);

        // Assert
        $this->assertSame('Guide_Tokens', $bare['page']);
        $this->assertStringContainsString('A token is minted', $bare['content']);
        $this->assertSame($bare['content'], $suffixed['content']);
    }

    /**
     * A page name cannot escape the guide directory.
     *
     * The name arrives from a model, which is a caller that can be talked into asking for
     * anything. `app/app.php` holds the application's database credentials and its
     * authentication key, and it sits two directories above the guides in exactly the
     * layout the default path produces.
     *
     * @return void
     */
    public function testAPageNameCannotEscapeTheDirectory(): void
    {
        // Arrange — a real file one level up, named as the traversal target
        $outside = dirname($this->fixtures) . '/pramnos-secret-' . getmypid() . '.md';
        file_put_contents($outside, 'the authentication key');

        try {
            // Act
            $result = $this->fixtureTool()
                ->execute(['page' => '../' . basename($outside)]);

            // Assert
            $this->assertArrayHasKey('error', $result);
            $this->assertArrayNotHasKey('content', $result);
        } finally {
            @unlink($outside);
        }
    }

    /**
     * An unknown page is refused with the list of real ones.
     *
     * A bare "not found" leaves the caller guessing at names; the list is what turns a
     * wrong guess into the right second call.
     *
     * @return void
     */
    public function testAnUnknownPageIsRefusedWithTheAvailableOnes(): void
    {
        // Act
        $result = $this->fixtureTool()->execute(['page' => 'Guide_Nothing']);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertContains('Guide_Tokens', $result['available']);
    }

    /**
     * A missing corpus is an error, not an empty result.
     *
     * The two are indistinguishable to a caller and mean opposite things: no answer to
     * this question, versus an installation with no documentation in it. Reachable in
     * practice — a package built with docs excluded.
     *
     * @return void
     */
    public function testAMissingCorpusIsReportedAsAnError(): void
    {
        // Act
        $result = (new FrameworkDocsTool('/nonexistent/pramnos/docs'))->execute([]);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('composer package', $result['error']);
        $this->assertStringContainsString('guides', $result['error']);
    }

    /**
     * A directory with no guides in it is reported too.
     *
     * The other half of the case above: the path exists, and there is still nothing to
     * search. Silently answering "no results" here would read as a confident negative.
     *
     * @return void
     */
    public function testAnEmptyCorpusIsReportedAsAnError(): void
    {
        // Arrange
        $empty = $this->fixtures . '/empty';
        mkdir($empty);

        try {
            // Act
            $result = (new FrameworkDocsTool($empty))->execute([]);

            // Assert
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('No pages found', $result['error']);
        } finally {
            @rmdir($empty);
        }
    }

    /**
     * A query of nothing but stop words says so instead of returning everything.
     *
     * `substr_count()` against an empty term list matches every page, so without this the
     * answer to "how does this work" would be the entire corpus ranked by nothing.
     *
     * @return void
     */
    public function testAQueryOfOnlyStopWordsIsRefusedRatherThanMatchingEverything(): void
    {
        // Act
        $result = $this->fixtureTool()->execute(['query' => 'how does the it and to']);

        // Assert
        $this->assertSame([], $result['results']);
        $this->assertStringContainsString('too short or too common', $result['hint']);
    }

    /**
     * A query matching nothing says the index is the next call.
     *
     * The whole point of the tool: "the framework does not do this" must be a conclusion
     * drawn from the map, not from one search that missed.
     *
     * @return void
     */
    public function testNoMatchesPointsAtTheIndex(): void
    {
        // Act
        $result = $this->fixtureTool()->execute(['query' => 'quantum flywheel calibration']);

        // Assert
        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('index of every page', $result['hint']);
    }

    /**
     * The index carries the use cases, because they are what makes a page findable.
     *
     * @return void
     */
    public function testTheIndexCarriesUseCases(): void
    {
        // Act
        $index = $this->fixtureTool()->execute([]);

        // Assert
        $this->assertSame(3, $index['count']);
        $byPage = array_column($index['pages'], 'use_cases', 'page');
        $this->assertCount(2, $byPage['Guide_Widgets']);
        $this->assertSame([], $byPage['frozen-reference'], 'No frontmatter, no use cases.');
    }

    /**
     * The index is ordered, so two calls agree.
     *
     * @return void
     */
    public function testTheIndexIsOrderedByName(): void
    {
        // Act
        $names = array_column($this->fixtureTool()->execute([])['pages'], 'page');

        // Assert
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    /**
     * The guides and the changelog are separate corpora.
     *
     * There are around sixty more posts than guides, and each post repeats the vocabulary
     * of the change it describes. Merged, "how does this work" would be answered by three
     * dated fragments of a feature's history — the failure the guide/changelog split
     * exists to prevent, arriving as a ranking accident rather than as a decision.
     *
     * Asserted against the real corpora, because the counts are the point.
     *
     * @return void
     */
    public function testTheChangelogIsASeparateCorpus(): void
    {
        // Arrange
        $tool = new FrameworkDocsTool();

        // Act
        $guides    = $tool->execute([]);
        $changelog = $tool->execute(['corpus' => 'changelog']);

        // Assert — both real, and not the same set
        $this->assertGreaterThan(20, $guides['count']);
        $this->assertGreaterThan(50, $changelog['count'], 'The posts are the larger corpus.');
        $this->assertSame('guides', $guides['corpus']);
        $this->assertSame('changelog', $changelog['corpus']);

        $guideNames = array_column($guides['pages'], 'page');
        $postNames  = array_column($changelog['pages'], 'page');
        $this->assertSame(
            [],
            array_intersect($guideNames, $postNames),
            'A page belongs to exactly one corpus.'
        );

        // And a guide never appears in a changelog search, however well it matches
        $hits = array_column(
            $tool->execute(['corpus' => 'changelog', 'query' => 'authentication token'])['results'],
            'page'
        );
        $this->assertSame([], array_intersect($hits, $guideNames));
    }

    /**
     * An unrecognised corpus falls back to the guides.
     *
     * The value arrives from a model, and the guides are the answer to "how does this
     * work" — the question asked far more often. Erroring on a typo would turn the common
     * call into a failure.
     *
     * @return void
     */
    public function testAnUnrecognisedCorpusFallsBackToTheGuides(): void
    {
        // Act
        $result = (new FrameworkDocsTool())->execute(['corpus' => 'posts']);

        // Assert
        $this->assertSame('guides', $result['corpus']);
    }

    /**
     * The tool describes itself as the place to look before concluding absence.
     *
     * The description is the only thing a model reads when deciding whether to call this
     * at all, so it is part of the fix rather than decoration around it.
     *
     * @return void
     */
    public function testTheDescriptionTellsACallerWhenToUseIt(): void
    {
        // Act
        $tool = new FrameworkDocsTool();

        // Assert
        $this->assertSame('framework-docs', $tool->name());
        $this->assertStringContainsString('before concluding', $tool->description());
        $this->assertArrayHasKey('query', $tool->inputSchema()['properties']);
        $this->assertArrayHasKey('page', $tool->inputSchema()['properties']);
        $this->assertSame(
            ['guides', 'changelog'],
            $tool->inputSchema()['properties']['corpus']['enum']
        );
    }
}
