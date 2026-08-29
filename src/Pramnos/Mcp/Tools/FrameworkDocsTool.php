<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: search and read the framework's own guides.
 *
 * ## Why this exists
 *
 * `docs/` is not export-ignored, so every guide ships inside the composer package and sits
 * in `vendor/pramnos/framework/docs/` of every project. That is deliberate, and the reason
 * given for it is explicit: the documentation should be available to whoever is working in
 * that project, *including an AI assistant*, and the vendored docs always match the
 * vendored code so there is no version to negotiate.
 *
 * The gap was that nothing ever **offered** them. The five other tools introspect the
 * database and the routes — they answer *what exists in this application*, never *how does
 * the framework work*. The three registered resources are the application's own
 * `CLAUDE.md`, `README.md` and `app/app.php`. So the only route to a guide was for an
 * assistant to guess that it should look inside `vendor/`.
 *
 * That is not a hypothetical failure. It is the one the documentation rules were written
 * after: a feature was documented, present in the vendored corpus, not found, and built a
 * second time beside the working copy.
 *
 * ## How pages are ranked
 *
 * Every guide carries `use_cases:` frontmatter, and each entry is phrased as **the task
 * the reader has in hand** rather than as a description of the page — "Adding a column to
 * an existing table", not "Schema builder reference". That makes them the closest thing in
 * the corpus to the question an assistant actually arrives with, so a hit there outweighs
 * a hit in a heading, which outweighs a hit in the body.
 *
 * Body matches still count, because a question about a specific method name will not
 * appear in any use case. They are just worth less: a method named once in an aside should
 * not outrank the page whose stated purpose is the task.
 *
 * ## Two corpora, never merged
 *
 * The guides answer *how does this work* and describe current state. The dated changelog
 * posts answer *what changed, when, and why* and stay deltas. They are searched separately,
 * selected with `corpus`, because there are around sixty more posts than guides and each
 * post repeats the vocabulary of the change it describes — merged, a question about how a
 * feature works would be answered by three fragments of its history. That is the exact
 * failure the split was introduced to prevent, and it would arrive here as a ranking
 * accident rather than as a decision.
 *
 * ## Calling it
 *
 * With no arguments it returns the **index** — every guide with its use cases. That is the
 * cheap call to make first: it is the whole map of what the framework has documented, and
 * it is what makes "there is no guide for this" a conclusion rather than an assumption.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class FrameworkDocsTool implements McpToolInterface
{
    /**
     * How many pages a search returns.
     *
     * @var int
     */
    private const MAX_RESULTS = 8;

    /**
     * How many matching lines are quoted per page.
     *
     * @var int
     */
    private const MAX_EXCERPTS = 4;

    /**
     * Absolute path to the guides.
     *
     * @var string
     */
    private string $docsPath;

    /**
     * @param string|null $docsPath Override the guide directory; defaults to the
     *                              framework's own `docs/`, which in a consuming project
     *                              is `vendor/pramnos/framework/docs`.
     */
    public function __construct(?string $docsPath = null)
    {
        // Four levels: Tools -> Mcp -> Pramnos -> src -> the package root. Counted rather
        // than assumed, because a structural helper in this repository once used the wrong
        // depth, scanned zero files, and passed every assertion it made about them.
        $this->docsPath = $docsPath ?? dirname(__DIR__, 4) . '/docs';
    }

    /**
     * The directory a call reads from.
     *
     * The guides and the changelog are **two corpora, searched separately and never
     * merged**. They answer different questions — *how does this work* and *what changed
     * and when* — and there are around sixty posts against forty guides, each post
     * repeating the vocabulary of the change it describes. Merged, a question about how a
     * feature works would be answered by three dated fragments of its history, which is
     * the exact failure the guide/changelog split was introduced to prevent.
     *
     * @param  string $corpus `guides` or `changelog`
     * @return string
     */
    private function pathFor(string $corpus): string
    {
        return $corpus === 'changelog'
            ? $this->docsPath . '/version-history/posts'
            : $this->docsPath;
    }

    /**
     * Machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'framework-docs';
    }

    /**
     * One sentence for `tools/list`.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Search and read the Pramnos Framework guides that shipped with this '
            . 'installed version. Call with no arguments for the full index of pages and '
            . 'what task each one covers; with a query to find the page for a task; with '
            . 'a page name to read it in full. Ask this before concluding the framework '
            . 'has no support for something.';
    }

    /**
     * Input schema.
     *
     * @return array{type: string, properties: array<string, mixed>}
     */
    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'What you are trying to do, in words — e.g. '
                        . '"issue an API token for a signed-in browser". Omit to get the '
                        . 'index of every page.',
                ],
                'page' => [
                    'type'        => 'string',
                    'description' => 'A page name from the index, to read in full — e.g. '
                        . '"Pramnos_Authentication_Guide". The .md suffix is optional.',
                ],
                'detail' => [
                    'type'        => 'string',
                    'enum'        => ['brief', 'full'],
                    'description' => 'How much of the index to return. `brief` (the default) '
                        . 'is one line per page — the name and its first use case. `full` is '
                        . 'every use case of every page, which is an order of magnitude larger '
                        . 'and worth asking for only when the brief index did not name what '
                        . 'you are looking for.',
                ],
                'corpus' => [
                    'type'        => 'string',
                    'enum'        => ['guides', 'changelog'],
                    'description' => 'Which corpus to read. "guides" (the default) '
                        . 'describes how the framework works now — ask this first. '
                        . '"changelog" is the dated history of what changed and when; '
                        . 'ask it only when the date or the reason for a change is the '
                        . 'question. They are never searched together.',
                ],
            ],
        ];
    }

    /**
     * Index, search, or read.
     *
     * @param  array<string, mixed> $input `query` and/or `page`, both optional
     * @return array<string, mixed>
     */
    public function execute(array $input): mixed
    {
        $corpus = (isset($input['corpus']) && (string) $input['corpus'] === 'changelog')
            ? 'changelog'
            : 'guides';
        $root = $this->pathFor($corpus);

        if (!is_dir($root)) {
            return [
                'error' => 'The framework ' . $corpus . ' are not present at ' . $root
                    . '. They ship inside the composer package, so this usually means an '
                    . 'install with --prefer-dist from a package built with docs excluded.',
            ];
        }

        $page = isset($input['page']) ? trim((string) $input['page']) : '';
        if ($page !== '') {
            return $this->readPage($root, $page);
        }

        $pages = $this->index($root);

        // An empty corpus is reported, not returned as "no results". The two look
        // identical to a caller and mean opposite things: one is a question with no
        // answer, the other is a broken installation.
        if ($pages === []) {
            return [
                'error' => 'No pages found under ' . $root . '.',
            ];
        }

        $query = isset($input['query']) ? trim((string) $input['query']) : '';
        if ($query === '') {
            /*
             * The index is brief by default, and that is a usability fix rather than a saving.
             *
             * Every use case of every page came to about 27KB — which is a page of reading
             * before the question has been asked, and the observable effect was that grepping
             * `docs/` won: one line, and you know what comes back. An index that fits in a
             * glance gets asked reflexively, which is the whole point of it existing.
             */
            $full = (string) ($input['detail'] ?? 'brief') === 'full';

            return [
                'corpus'    => $corpus,
                'docs_path' => $root,
                'count'     => count($pages),
                'detail'    => $full ? 'full' : 'brief',
                'hint'      => $full
                    ? 'Call again with `page` to read one in full, or `query` to search.'
                    : 'One line per page: the name, and the first of its use cases. Call again '
                        . 'with `page` to read one in full, `query` to search, or '
                        . '`"detail": "full"` for every use case of every page.',
                /*
                 * A map, not a list of objects, in the brief shape.
                 *
                 * `{"Pramnos_Push_Guide": "Sending a notification to…"}` against
                 * `[{"page": …, "for": …}]` is the same information with half the punctuation,
                 * and at fifty pages the punctuation was most of the index.
                 */
                'pages'     => $full
                    ? array_map(
                        static fn (array $p): array => [
                            'page'      => $p['page'],
                            'title'     => $p['title'],
                            'use_cases' => $p['use_cases'],
                        ],
                        $pages
                    )
                    : array_combine(
                        array_column($pages, 'page'),
                        array_map(
                            static fn (array $p): string => $p['use_cases'][0] ?? $p['title'],
                            $pages
                        )
                    ),
            ];
        }

        return $this->search($query, $pages, $corpus);
    }

    /**
     * Read one page in full.
     *
     * @param  string $root The corpus directory
     * @param  string $page Page name, with or without the `.md` suffix
     * @return array<string, mixed>
     */
    private function readPage(string $root, string $page): array
    {
        // basename() rather than a path check: the name arrives from a model, and a
        // `page` of `../../app/app.php` must resolve to a missing guide, not to the
        // application's configuration. There is exactly one directory to read from.
        $name = basename($page);
        if (!str_ends_with(strtolower($name), '.md')) {
            $name .= '.md';
        }

        $path = $root . '/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            return [
                'error'     => 'No page named ' . $name . '.',
                'available' => array_map(
                    fn(array $p): string => $p['page'],
                    $this->index($root)
                ),
            ];
        }

        $content = file_get_contents($path);

        return [
            'page'    => preg_replace('/\.md$/i', '', $name),
            'path'    => $path,
            'content' => $content === false ? '' : $content,
        ];
    }

    /**
     * Every page in a corpus, with its title and use cases.
     *
     * @param  string $root The corpus directory
     * @return list<array{page: string, path: string, title: string, use_cases: list<string>, haystack: string, body: string}>
     */
    private function index(string $root): array
    {
        $pages = [];

        foreach (glob($root . '/*.md') ?: [] as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            $name      = preg_replace('/\.md$/i', '', basename($path));
            $useCases  = $this->useCases($raw);
            $headings  = $this->headings($raw);

            $pages[] = [
                'page'      => (string) $name,
                'path'      => $path,
                'title'     => $headings[0] ?? str_replace('_', ' ', (string) $name),
                'use_cases' => $useCases,
                'haystack'  => strtolower(
                    implode(' ', $useCases) . ' ' . implode(' ', $headings)
                ),
                'body'      => strtolower($raw),
            ];
        }

        usort($pages, fn(array $a, array $b): int => strcmp($a['page'], $b['page']));

        return $pages;
    }

    /**
     * The `use_cases:` list from a page's frontmatter.
     *
     * Parsed rather than pulled through a YAML library: the frontmatter block is a fixed
     * shape enforced by a test in this repository, and the MCP server must not acquire a
     * dependency to read a list of dashed lines.
     *
     * @param  string $raw The page source
     * @return list<string>
     */
    private function useCases(string $raw): array
    {
        if (!preg_match('/^---\R(.*?)\R---/s', $raw, $frontmatter)) {
            return [];
        }
        if (!preg_match('/^use_cases:\s*\R((?:\s*-\s*.+\R?)+)/m', $frontmatter[1], $block)) {
            return [];
        }

        $cases = [];
        foreach (preg_split('/\R/', $block[1]) ?: [] as $line) {
            if (preg_match('/^\s*-\s*(.+?)\s*$/', $line, $entry)) {
                $cases[] = trim($entry[1], "\"' ");
            }
        }

        return $cases;
    }

    /**
     * Every markdown heading on a page, in order.
     *
     * @param  string $raw The page source
     * @return list<string>
     */
    private function headings(string $raw): array
    {
        preg_match_all('/^#{1,3}\s+(.+?)\s*$/m', $raw, $matches);

        return array_map(
            fn(string $h): string => trim(str_replace(['`', '*'], '', $h)),
            $matches[1]
        );
    }

    /**
     * Rank pages against a query and quote what matched.
     *
     * Pages carrying no `use_cases:` are demoted rather than dropped: they are history,
     * not guidance, but a reader who asks about something only they mention should still
     * be told they exist.
     *
     * @param  string                                                                          $query The caller's words
     * @param  list<array{page: string, path: string, title: string, use_cases: list<string>, haystack: string, body: string}> $pages  The index
     * @param  string                                                                          $corpus Which corpus was searched
     * @return array<string, mixed>
     */
    private function search(string $query, array $pages, string $corpus = 'guides'): array
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return [
                'query'   => $query,
                'results' => [],
                'hint'    => 'Every word in the query was too short or too common to '
                    . 'search on. Call with no arguments for the index instead.',
            ];
        }

        $scored = [];
        foreach ($pages as $entry) {
            $score   = 0;
            $matched = [];

            foreach ($terms as $term) {
                // Weighted, not counted. A term in a use case means the page states this
                // task as its purpose; a term in the body may be an aside.
                $inUseCases = substr_count($entry['haystack'], $term);
                $inBody     = substr_count($entry['body'], $term);

                if ($inUseCases > 0) {
                    $score += 10 * min($inUseCases, 3);
                    $matched[] = $term;
                } elseif ($inBody > 0) {
                    $score += min($inBody, 5);
                    $matched[] = $term;
                }
            }

            // Every term present beats a page that merely repeats one of them.
            if (count($matched) === count($terms) && count($terms) > 1) {
                $score += 15;
            }

            // A page with no use cases is not guidance, and the two in this corpus that
            // have none say so in their own text: a frozen version reference and a
            // release index. They are also the two longest files here, so on body volume
            // alone the frozen one outranked every live guide on the first query this was
            // measured with — sending a reader to a page that stopped describing current
            // state on purpose. Structural rather than a list of names, so a page cannot
            // become quietly exempt by being added later.
            // Within the changelog every page lacks use cases, so the demotion would
            // apply uniformly and mean nothing except smaller numbers. It exists to keep
            // history from outranking guidance, and there is no guidance in that corpus
            // to outrank.
            $guidance = $entry['use_cases'] !== [];
            if (!$guidance && $corpus !== 'changelog') {
                $score = (int) round($score * 0.2);
            }

            if ($score > 0) {
                $scored[] = [
                    'page'      => $entry['page'],
                    'title'     => $entry['title'],
                    'score'     => $score,
                    'guidance'  => $guidance,
                    'use_cases' => $entry['use_cases'],
                    'excerpts'  => $this->excerpts($entry['path'], $terms),
                ];
            }
        }

        usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $results = array_slice($scored, 0, self::MAX_RESULTS);

        return [
            'query'   => $query,
            'corpus'  => $corpus,
            'count'   => count($results),
            'results' => $results,
            'hint'    => $results === []
                ? 'Nothing matched. Call with no arguments for the index of every page '
                    . 'before concluding the framework does not support this.'
                : 'Call again with `page` to read one of these in full.',
        ];
    }

    /**
     * Search terms from a natural-language question.
     *
     * Words of one or two characters and a small stop list are dropped. The stop list is
     * short on purpose: a long one would remove words that are genuinely load-bearing in
     * this corpus — `get`, `set`, `new` and `all` are all method names here.
     *
     * @param  string $query The caller's words
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $stop = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'how', 'what',
            'when', 'why', 'can', 'does', 'have', 'has', 'are', 'was', 'you', 'your',
            'not', 'but', 'its',
        ];

        $words = preg_split('/[^a-z0-9_]+/', strtolower($query)) ?: [];
        $terms = [];

        foreach ($words as $word) {
            if (strlen($word) > 2 && !in_array($word, $stop, true) && !in_array($word, $terms, true)) {
                $terms[] = $word;
            }
        }

        return $terms;
    }

    /**
     * The lines of a page that contain a search term.
     *
     * Quoted with line numbers so a caller can decide whether the full read is worth it,
     * which is the difference between this and returning the whole corpus every time.
     *
     * @param  string       $path  The page
     * @param  list<string> $terms The search terms
     * @return list<string>
     */
    private function excerpts(string $path, array $terms): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $excerpts = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $lower = strtolower($trimmed);
            foreach ($terms as $term) {
                if (str_contains($lower, $term)) {
                    $excerpts[] = ($number + 1) . ': ' . mb_substr($trimmed, 0, 220);
                    break;
                }
            }

            if (count($excerpts) >= self::MAX_EXCERPTS) {
                break;
            }
        }

        return $excerpts;
    }
}
