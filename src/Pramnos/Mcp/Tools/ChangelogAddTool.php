<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: add a section to today's changelog post.
 *
 * The only tool here that writes, and it earned that by being a ritual that kept going wrong.
 * The framework's rule is one post per day, each section listed at the top with a count — so
 * adding an entry means appending the section, rebuilding the list from the headings, and
 * getting the count and its plural right. Done by hand a dozen times in a day, that produced a
 * regex that threw on `\D`, and a summary list left three items behind the sections it was
 * supposed to summarise. Nothing noticed: the page renders, the list is simply wrong.
 *
 * Mechanical, repetitive, and silent when it fails, which is the whole case for a tool.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangelogAddTool implements McpToolInterface
{
    /** Where the posts live, relative to a repository root. */
    private const POSTS = 'docs/version-history/posts';

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(
            $root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()),
            DIRECTORY_SEPARATOR
        );
    }

    public function name(): string
    {
        return 'changelog-add';
    }

    public function description(): string
    {
        return 'Add a section to today\'s dated changelog post, creating the post if it does '
            . 'not exist, and rebuild the summary list and its count from the actual headings. '
            . 'The framework requires a changelog entry in the same commit as a guide change; '
            . 'this is the mechanical half of that. Use `preview` to see the result first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'The section heading, without the `##`. It becomes the '
                        . 'summary-list entry too, so write it as the one-line version of what '
                        . 'changed.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'The section, in Markdown, without the heading.',
                ],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Categories to add to the post\'s frontmatter. Existing '
                        . 'ones are kept.',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Which post, as `YYYY-MM-DD`. Defaults to today.',
                ],
                'replace' => [
                    'type' => 'boolean',
                    'description' => 'Rewrite a section with this title instead of refusing.',
                ],
                'preview' => [
                    'type' => 'boolean',
                    'description' => 'Report what would change and write nothing.',
                ],
            ],
            'required' => ['title', 'body'],
        ];
    }

    public function execute(array $input): mixed
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body  = rtrim((string) ($input['body'] ?? ''));

        if ($title === '' || $body === '') {
            return ['error' => 'Both `title` and `body` are required.'];
        }

        if (str_contains($title, "\n")) {
            return ['error' => 'The title is one line — it becomes a heading and a list entry.'];
        }

        $posts = $this->postsDirectory();

        if ($posts === null) {
            return [
                'error' => 'No changelog at ' . self::POSTS . '. This is a tool for the '
                    . 'framework\'s own repository, or for a development checkout of it — it '
                    . 'will not write into an installed package.',
            ];
        }

        $date = trim((string) ($input['date'] ?? ''));

        if ($date === '') {
            $date = date('Y-m-d');
        }

        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) !== 1) {
            return ['error' => 'The date is `YYYY-MM-DD`, or omitted for today.'];
        }

        $file     = $posts . '/' . $date . '.md';
        $existed  = is_file($file);
        $contents = $existed ? (string) file_get_contents($file) : $this->newPost($date, $input);

        if ($existed && $this->hasSection($contents, $title)) {
            if (empty($input['replace'])) {
                return [
                    'error' => 'Today\'s post already has a section called "' . $title . '". '
                        . 'Pass `replace: true` to rewrite it, or use a different title — two '
                        . 'sections with one name is a summary list that points at the wrong '
                        . 'one.',
                    'file'  => $this->relative($file),
                ];
            }

            $contents = $this->removeSection($contents, $title);
        }

        $contents = $this->addCategories($contents, (array) ($input['categories'] ?? []));
        $contents = rtrim($contents) . "\n\n## " . $title . "\n\n" . $body . "\n";
        $contents = $this->rebuildSummary($contents);

        $sections = $this->sections($contents);
        $listed   = $this->listedEntries($contents);

        // Verified rather than assumed, because the failure this replaces was exactly this
        // going wrong quietly: a list that no longer matched the sections under it.
        if (count($sections) !== count($listed)) {
            return [
                'error' => 'Refusing to write: the rebuilt summary has ' . count($listed)
                    . ' entries for ' . count($sections) . ' sections. Nothing was changed.',
            ];
        }

        if (!empty($input['preview'])) {
            return [
                'preview'  => true,
                'file'     => $this->relative($file),
                'exists'   => $existed,
                'sections' => $sections,
                'note'     => 'Nothing was written. Call again without `preview`.',
            ];
        }

        if (@file_put_contents($file, $contents) === false) {
            return ['error' => 'Could not write ' . $this->relative($file) . '.'];
        }

        return [
            'file'     => $this->relative($file),
            'created'  => !$existed,
            'title'    => $title,
            'sections' => count($sections),
            'note'     => $existed
                ? 'Appended, and the summary list rebuilt from the headings.'
                : 'Post created. One post per day is the rule — a second file for the same '
                    . 'date would split the day\'s entry in two.',
        ];
    }

    /**
     * A new post, with the frontmatter and the parts the theme expects.
     *
     * `<!-- more -->` is the fold: everything above it is the excerpt the blog index shows, so
     * the summary list belongs above and the sections below. A post without it shows its
     * entire contents on the index page.
     *
     * @param array<string, mixed> $input
     */
    private function newPost(string $date, array $input): string
    {
        $categories = array_values(array_unique(array_merge(
            ['Changelog'],
            array_map('strval', (array) ($input['categories'] ?? []))
        )));

        $frontmatter = "---\ndate: " . $date . "\ncategories:\n";

        foreach ($categories as $category) {
            $frontmatter .= '  - ' . $category . "\n";
        }

        $frontmatter .= "---\n\n";

        // `28 August 2026`, matching every other post.
        $heading = date('j F Y', (int) strtotime($date));

        return $frontmatter . '# ' . $heading . "\n\n0 changes:\n\n<!-- more -->\n";
    }

    /**
     * Rebuild the `N changes:` list from the `##` headings.
     *
     * The list is derived, never edited: that is the entire point. A hand-maintained list drifts
     * from the sections it describes, and the drift is invisible — this one was three entries
     * behind before anybody noticed.
     */
    private function rebuildSummary(string $contents): string
    {
        $sections = $this->sections($contents);
        $count    = count($sections);
        $list     = $count . ' change' . ($count === 1 ? '' : 's') . ":\n\n"
            . implode("\n", array_map(static fn (string $s): string => '- ' . $s, $sections))
            . "\n";

        $existing = preg_match('~^\d+ change(?:s)?:\n\n(?:- .*\n)*~m', $contents, $match, PREG_OFFSET_CAPTURE);

        if ($existing === 1) {
            return substr($contents, 0, (int) $match[0][1])
                . $list
                . substr($contents, (int) $match[0][1] + strlen($match[0][0]));
        }

        // No list yet — put one straight after the `# heading`, which is where every post has
        // it, rather than appending it to the end where nobody reads it.
        $heading = preg_match('~^# .*\n~m', $contents, $match, PREG_OFFSET_CAPTURE);

        if ($heading !== 1) {
            return $contents;
        }

        $at = (int) $match[0][1] + strlen($match[0][0]);

        return substr($contents, 0, $at) . "\n" . $list . substr($contents, $at);
    }

    /** @return list<string> */
    private function sections(string $contents): array
    {
        $matches = [];
        preg_match_all('~^## (.+)$~m', $contents, $matches);

        return array_map('trim', $matches[1] ?? []);
    }

    /** @return list<string> */
    private function listedEntries(string $contents): array
    {
        if (preg_match('~^\d+ change(?:s)?:\n\n((?:- .*\n)*)~m', $contents, $match) !== 1) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", trim($match[1])) as $line) {
            if (str_starts_with($line, '- ')) {
                $entries[] = substr($line, 2);
            }
        }

        return $entries;
    }

    private function hasSection(string $contents, string $title): bool
    {
        return in_array($title, $this->sections($contents), true);
    }

    /**
     * Cut a section out, from its heading to the next one or the end.
     */
    private function removeSection(string $contents, string $title): string
    {
        $pattern = '~\n?^## ' . preg_quote($title, '~') . '\n.*?(?=^## |\z)~ms';

        return (string) preg_replace($pattern, "\n", $contents, 1);
    }

    /**
     * Merge categories into the frontmatter, keeping what is there.
     *
     * @param array<int, mixed> $wanted
     */
    private function addCategories(string $contents, array $wanted): string
    {
        $wanted = array_values(array_filter(array_map('strval', $wanted)));

        if ($wanted === []) {
            return $contents;
        }

        // The list form. `categories: [Changelog]` — the inline form, which one post uses — is
        // left alone rather than rewritten: reformatting somebody's frontmatter to add one
        // entry is a bigger change than the entry.
        if (preg_match('~^categories:\n((?:  - .*\n)+)~m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $contents;
        }

        $existing = [];

        foreach (explode("\n", trim($match[1][0])) as $line) {
            $existing[] = trim(substr(trim($line), 2));
        }

        $merged = $existing;

        foreach ($wanted as $category) {
            if (!in_array($category, $existing, true)) {
                $merged[] = $category;
            }
        }

        if ($merged === $existing) {
            return $contents;
        }

        $replacement = "categories:\n";

        foreach ($merged as $category) {
            $replacement .= '  - ' . $category . "\n";
        }

        return substr($contents, 0, (int) $match[0][1])
            . $replacement
            . substr($contents, (int) $match[0][1] + strlen($match[0][0]));
    }

    /**
     * The posts directory, or null.
     *
     * A development checkout of the framework counts — that is where this work happens, and the
     * package is a symlink or a git tree there. An *installed* package does not: writing a
     * changelog entry into `vendor/` would be edited into oblivion by the next `composer
     * update`, and the entry belongs in the framework's own history anyway.
     */
    private function postsDirectory(): ?string
    {
        $own = $this->root . '/' . self::POSTS;

        if (is_dir($own)) {
            return $own;
        }

        $package = $this->root . '/vendor/mrpc/pramnosframework';

        if (!is_dir($package . '/' . self::POSTS)) {
            return null;
        }

        $isCheckout = is_link($package) || is_dir($package . '/.git');

        return $isCheckout ? $package . '/' . self::POSTS : null;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }
}
