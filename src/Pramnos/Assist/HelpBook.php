<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * One set of help pages, and where they are read: the administration's, or the site's.
 *
 * The two are separate corpora on purpose. The administration's pages describe settings, screens
 * and workflows a visitor has no business reading, so the site's help never falls back to them —
 * and the framework's own pages, which document its administration screens, belong to the
 * administration alone. Built by {@see Help::admin()} and {@see Help::site()}; see {@see Help}
 * for how a page is written.
 */
final class HelpBook
{
    /** The administration's pages. */
    public const ADMIN = 'admin';

    /** The site's pages, for visitors. */
    public const SITE = 'site';

    /** @var array<string, array<string, mixed>>|null Every page, by slug, once read */
    private ?array $pages = null;

    /** Who these pages are for, on the site's level; the request's reader when none was given. */
    private ?HelpReader $reader = null;

    /** @var array<string, mixed>|null The sections as a search counts them, once built */
    private ?array $index = null;

    /** @var list<list<string>>|null The application's synonyms, folded, once read */
    private ?array $synonyms = null;

    /** How soon a word's repeats stop adding to a section's score (BM25's k1). */
    public const SATURATION = 1.2;

    /** How much a long section is held back for its length (BM25's b): 0 not at all, 1 fully. */
    public const LENGTH = 0.75;

    /** What a synonym of an asked word counts, against the word itself. */
    public const SYNONYM_WEIGHT = 0.5;

    /**
     * @param string      $level       {@see ADMIN} or {@see SITE}
     * @param list<string> $directories Where to look, first match wins
     * @param string|null $framework   The one of them holding the framework's own pages, if any
     */
    public function __construct(
        private readonly string $level,
        private readonly array $directories,
        private readonly ?string $framework = null
    ) {
    }

    /**
     * These pages as `$reader` may see them. On the site's level a page or a block that
     * `requires` what the {@see HelpGate} refuses this reader is not there; on the
     * administration's nothing is gated. Without this, the site's pages are the request's
     * reader's.
     */
    public function for(HelpReader $reader): self
    {
        // Read once, here, so every reader's view shares the files rather than reading them again.
        $this->pages();
        $view         = clone $this;
        $view->reader = $reader;
        $view->index  = null;

        return $view;
    }

    /** {@see ADMIN} or {@see SITE}. */
    public function level(): string
    {
        return $this->level;
    }

    /**
     * Where the pages are, first match wins.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    /**
     * One page: `slug`, `title`, `section`, `questions`, `updated`, `whatsnew` (the lines that
     * announce it, {@see WhatsNew}), `body` (without front matter), `framework` (shipped rather
     * than the application's) — or null.
     *
     * @return array{slug: string, title: string, section: string, questions: list<string>, updated: string, whatsnew: list<string>, body: string, framework: bool}|null
     */
    public function page(string $slug): ?array
    {
        $slug = Help::slug($slug);

        return $slug === '' ? null : ($this->visible()[$slug] ?? null);
    }

    /**
     * The page of a screen's action for one option of its address, of the action, or of the
     * screen — the application's before the framework's, whichever it wrote: an application that
     * wrote `users.md` means it for the edit form too, and the framework's `users/edit.md` must
     * not win over it.
     *
     * The option is the address's part after the action — `/Devices/massiveupdate/3` — for a
     * screen that does a different thing for each: `devices/massiveupdate/3.md` is that one's
     * page, and `devices/massiveupdate.md` stays the overview, found for any option without one.
     *
     * @return array<string, mixed>|null
     */
    public function forScreen(string $screen, string $action = '', string $option = ''): ?array
    {
        $screen = Help::slug($screen);
        $action = Help::slug($action);
        $option = Help::slug(str_replace('/', '', $option));

        if ($screen === '' || str_contains($screen, '/')) {
            return null;
        }

        $acts      = $action !== '' && $action !== 'display';
        $forOption = $acts && $option !== '' ? $this->page($screen . '/' . $action . '/' . $option) : null;
        $forAction = $acts ? $this->page($screen . '/' . $action) : null;
        $forScreen = $this->page($screen);

        foreach ([$forOption, $forAction, $forScreen] as $page) {
            if ($page !== null && !$page['framework']) {
                return $page;
            }
        }

        return $forOption ?? $forAction ?? $forScreen ?? $this->claiming($screen);
    }

    /**
     * The page that lists `$screen` under `screens:` — one page for several screens. After a
     * page named after the screen, so a dedicated page overrides an area's without editing its
     * list; between two that claim it, the first by name.
     *
     * @return array<string, mixed>|null
     */
    private function claiming(string $screen): ?array
    {
        $pages = $this->visible();
        ksort($pages);

        foreach ($pages as $page) {
            if (in_array($screen, $page['screens'], true)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Every page, the application's before the framework's, by section and title.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $pages = array_values($this->visible());
        // `order:` within a section, then the title; a page without one after those with one.
        $key = static fn (array $p): array => [$p['framework'], mb_strtolower($p['section']), $p['order'] ?? PHP_INT_MAX, mb_strtolower($p['title'])];
        usort($pages, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $pages;
    }

    /**
     * The pages that answer a question, best first: each page as its best section scores it
     * ({@see searchSections()}), with that section as `best` — so a long page wins a question by
     * the part of it that answers, not by its length.
     *
     * @return list<array<string, mixed>> Each page with its `score` and `best` section
     */
    public function search(string $question, int $limit = 5): array
    {
        $pages = [];
        foreach ($this->searchSections($question, PHP_INT_MAX) as $section) {
            $slug = (string) $section['page'];
            if (!isset($pages[$slug])) {
                $pages[$slug] = $this->visible()[$slug] + ['score' => $section['score'], 'best' => $section];
            }
        }

        return array_slice(array_values($pages), 0, max(1, $limit));
    }

    /**
     * Every section of every page, as {@see Help::sections()} cuts them, with its page: `page`,
     * `title` (the page's), `topic` (the page's `section:`), `heading`, `anchor`, `path`
     * («Page › Section»), `text`, `questions` (the section's own; the page's for the text before
     * its first heading) and `framework`.
     *
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        return $this->index()['sections'];
    }

    /**
     * One section of a page, by its anchor or its heading as written; null when the page has no
     * such section.
     *
     * @return array<string, mixed>|null
     */
    public function section(string $slug, string $name): ?array
    {
        $slug   = Help::slug($slug);
        $anchor = Help::anchor(ltrim(trim($name), '#'));
        foreach ($this->sections() as $section) {
            if ($section['page'] === $slug && ($section['anchor'] === ltrim(trim($name), '#') || Help::anchor($section['heading']) === $anchor)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The sections that answer a question, best first — scored by BM25: each word of the question
     * by how rare it is across the sections, by how often it is in the section with its repeats
     * counting less and less ({@see SATURATION}), and against the section's length so a long one
     * does not win for being long ({@see LENGTH}). A section's path («Page › Section») and its
     * questions count three times its text; a synonym of an asked word ({@see synonyms()}) counts
     * {@see SYNONYM_WEIGHT}. A question that is one of the section's questions puts it first, one
     * whose every word is in such a question nearly so. `$topic` keeps the pages of one `section:`.
     *
     * @return list<array<string, mixed>> Each section with its `score`
     */
    public function searchSections(string $question, int $limit = 5, string $topic = ''): array
    {
        $asked = array_values(array_unique(Help::words($question)));
        if ($asked === []) {
            return [];
        }
        $weights = array_fill_keys($asked, 1.0);
        foreach ($this->expand($asked) as $word) {
            $weights[$word] ??= self::SYNONYM_WEIGHT;
        }

        $index   = $this->index();
        $total   = count($index['terms']);
        $average = max(1.0, $index['average']);
        $rarity  = [];
        foreach (array_keys($weights) as $word) {
            $carrying = 0;
            foreach ($index['terms'] as $terms) {
                $carrying += self::hits((string) $word, $terms['strong']) + self::hits((string) $word, $terms['weak']) > 0 ? 1 : 0;
            }
            $rarity[$word] = log(1 + $total / max(1, $carrying));
        }

        $found = [];
        foreach ($index['terms'] as $at => $terms) {
            $section = $index['sections'][$at];
            if ($topic !== '' && mb_strtolower($section['topic']) !== mb_strtolower($topic)) {
                continue;
            }
            $norm  = self::SATURATION * (1 - self::LENGTH + self::LENGTH * $terms['length'] / $average);
            $score = 0.0;
            foreach ($weights as $word => $weight) {
                $often = 3 * self::hits((string) $word, $terms['strong']) + self::hits((string) $word, $terms['weak']);
                if ($often > 0) {
                    $score += $weight * $rarity[$word] * $often * (self::SATURATION + 1) / ($often + $norm);
                }
            }
            if ($score <= 0) {
                continue;
            }
            foreach ($terms['questions'] as $listed) {
                if ($listed === []) {
                    continue;
                }
                $covered = array_filter($asked, static fn (string $w): bool => self::hits($w, array_fill_keys($listed, 1)) > 0);
                if (count($covered) === count($asked)) {
                    // Every word of the question is in this listed one: the same question, or nearly.
                    $score += count($listed) === count($asked) ? 1000 : 100;
                    break;
                }
            }
            $found[] = $section + ['score' => round($score, 3)];
        }

        usort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($found, 0, max(1, $limit));
    }

    /**
     * The pages and their sections by title only, grouped by the pages' `section:` — what an
     * assistant reads to see what there is, without any page's text. `$topic` keeps one group.
     *
     * @return array<string, list<array{page: string, title: string, sections: list<array{heading: string, anchor: string, path: string}>}>>
     */
    public function map(string $topic = ''): array
    {
        $map = [];
        foreach ($this->all() as $page) {
            $group = (string) $page['section'];
            if ($topic !== '' && mb_strtolower($group) !== mb_strtolower($topic)) {
                continue;
            }
            $sections = [];
            foreach ($this->sections() as $section) {
                if ($section['page'] === $page['slug'] && $section['heading'] !== '') {
                    $sections[] = ['heading' => $section['heading'], 'anchor' => $section['anchor'], 'path' => $section['path']];
                }
            }
            $map[$group !== '' ? $group : 'Other'][] = ['page' => (string) $page['slug'], 'title' => (string) $page['title'], 'sections' => $sections];
        }

        return $map;
    }

    /**
     * The application's synonyms: `_synonyms.php` in a help directory returns groups of words
     * that mean the same here — `[['form', 'link', 'page', 'url'], ['phone', 'contact']]` — every
     * directory's groups together. A word of a question brings the rest of its group in, at
     * {@see SYNONYM_WEIGHT}.
     *
     * @return list<list<string>> Each group folded as {@see Help::words()} folds
     */
    public function synonyms(): array
    {
        if ($this->synonyms !== null) {
            return $this->synonyms;
        }
        $groups = [];
        foreach ($this->directories as $directory) {
            $file = rtrim($directory, '/') . '/_synonyms.php';
            if (!is_file($file)) {
                continue;
            }
            $read = (static fn (string $f): mixed => include $f)($file);
            foreach (is_array($read) ? $read : [] as $group) {
                $words = array_values(array_unique(Help::words(implode(' ', array_map('strval', (array) $group)))));
                if (count($words) > 1) {
                    $groups[] = $words;
                }
            }
        }

        return $this->synonyms = $groups;
    }

    /**
     * The synonyms of asked words, not themselves asked.
     *
     * @param list<string> $asked
     * @return list<string>
     */
    private function expand(array $asked): array
    {
        $more = [];
        foreach ($this->synonyms() as $group) {
            foreach ($asked as $word) {
                if (self::hits($word, array_fill_keys($group, 1)) > 0) {
                    $more = [...$more, ...array_diff($group, $asked)];
                    break;
                }
            }
        }

        return array_values(array_unique($more));
    }

    /**
     * The sections and what a search counts of each, built once per book (and per reader, on the
     * site's level).
     *
     * @return array{sections: list<array<string, mixed>>, terms: list<array{strong: array<string, int>, weak: array<string, int>, questions: list<list<string>>, length: int}>, average: float}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $sections = [];
        $terms    = [];
        foreach ($this->visible() as $slug => $page) {
            foreach (Help::sections((string) $page['body']) as $part) {
                $questions  = $part['heading'] === '' ? [...$page['questions'], ...$part['questions']] : $part['questions'];
                $path       = implode(' › ', [(string) $page['title'], ...$part['trail']]);
                $sections[] = [
                    'page'      => (string) $slug,
                    'title'     => (string) $page['title'],
                    'topic'     => (string) $page['section'],
                    'heading'   => $part['heading'],
                    'anchor'    => $part['anchor'],
                    'path'      => $path,
                    'text'      => $part['text'],
                    'questions' => $questions,
                    'framework' => (bool) $page['framework'],
                ];
                $strong  = Help::words($path . ' ' . implode(' ', $questions));
                $weak    = Help::words($part['text']);
                $terms[] = [
                    'strong'    => array_count_values($strong),
                    'weak'      => array_count_values($weak),
                    'questions' => array_map(static fn (string $q): array => array_values(array_unique(Help::words($q))), $questions),
                    'length'    => count($strong) + count($weak),
                ];
            }
        }
        $lengths = array_column($terms, 'length');

        return $this->index = [
            'sections' => $sections,
            'terms'    => $terms,
            'average'  => $lengths === [] ? 1.0 : array_sum($lengths) / count($lengths),
        ];
    }

    /**
     * How often a folded word occurs among counted words: exactly, or — four letters or more on
     * the shorter side — one a prefix of the other, so `goal` meets `goals`.
     *
     * @param array<string, int> $counts
     */
    private static function hits(string $word, array $counts): int
    {
        if (mb_strlen($word) < 4) {
            return $counts[$word] ?? 0;
        }

        $hits = 0;
        foreach ($counts as $term => $count) {
            $term = (string) $term;
            if ($term === $word || (mb_strlen($term) >= 4 && (str_starts_with($term, $word) || str_starts_with($word, $term)))) {
                $hits += $count;
            }
        }

        return $hits;
    }

    /** The file of a picture a page shows, if it is one and lies inside one of these directories. */
    public function image(string $path): ?string
    {
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), Help::IMAGES, true)) {
            return null;
        }

        foreach ($this->directories as $directory) {
            $root = realpath($directory);
            $file = $root === false ? false : realpath($root . '/' . $path);
            if ($root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The help screen's address — the list, or one page: in the administration area for its
     * pages (`$base` is the area's URL as the asking request saw it, for a worker), on the site
     * for the site's.
     */
    public function url(string $slug = '', string $base = ''): string
    {
        $path = 'Help' . ($slug !== '' ? '/page?p=' . rawurlencode($slug) : '');

        return $this->address($path, $base);
    }

    /** A picture's address. */
    public function imageUrl(string $path): string
    {
        return $this->address('Help/image?f=' . rawurlencode($path));
    }

    /** A page as HTML, its links and pictures pointing at this level's help screen. */
    public function pageHtml(array $page): string
    {
        return Help::html(
            (string) $page['body'],
            fn (string $slug): string => $this->url($slug),
            fn (string $path): string => $this->imageUrl($path),
            (string) $page['slug']
        );
    }

    /**
     * The application's help screen class for this level, or '' when it has none: for the
     * administration, `Admin\Controllers\Help` (or `Controllers\Help`, in an application that is
     * the administration) extending `HelpController`; for the site, `Controllers\Help` extending
     * `SiteHelpController`.
     */
    public function screen(): string
    {
        $app       = \Pramnos\Application\Application::currentInstance();
        $namespace = trim((string) (is_object($app) ? ($app->applicationInfo['namespace'] ?? '') : ''), '\\');

        if ($namespace === '') {
            return '';
        }

        $site    = \Pramnos\Application\Controllers\SiteHelpController::class;
        $classes = $this->level === self::SITE
            ? [$namespace . '\\Controllers\\Help']
            : [$namespace . '\\Admin\\Controllers\\Help', $namespace . '\\Controllers\\Help'];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $isSite = is_a($class, $site, true);
            if ($this->level === self::SITE ? $isSite : (!$isSite && is_a($class, \Pramnos\Application\Controllers\HelpController::class, true))) {
                return $class;
            }
        }

        return '';
    }

    /** Forget what was read — for a test, or a worker between requests. */
    public function forget(): void
    {
        $this->pages    = null;
        $this->index    = null;
        $this->synonyms = null;
    }

    /** An address on this level's side: the administration area's, or the site's. */
    private function address(string $path, string $base = ''): string
    {
        if ($this->level === self::ADMIN) {
            return \Pramnos\Http\AdminArea::address($path, $base);
        }

        return ($base !== '' ? rtrim($base, '/') . '/' : (defined('sURL') ? (string) \sURL : '/')) . $path;
    }

    /**
     * The pages this reader may see, with the blocks they may not cut out — on the site's level.
     *
     * Fail closed: a requirement with no gate to answer it, or a gate that throws, is refused.
     * The search scores what is left, so a hidden block's words cannot make its page match.
     *
     * @return array<string, array<string, mixed>>
     */
    private function visible(): array
    {
        if ($this->level !== self::SITE) {
            return $this->pages();
        }

        $reader  = $this->reader ?? HelpReader::fromRequest();
        $gate    = Help::siteGate();
        $answers = [];
        $allows  = static function (string $requirement) use ($reader, $gate, &$answers): bool {
            if (!isset($answers[$requirement])) {
                try {
                    $answers[$requirement] = $gate !== null && $gate->allows($requirement, $reader);
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log('Help gate failed on "' . $requirement . '": ' . $e->getMessage(), 'help');
                    $answers[$requirement] = false;
                }
            }

            return $answers[$requirement];
        };

        $visible = [];
        foreach ($this->pages() as $slug => $page) {
            foreach ($page['requires'] as $requirement) {
                if (!$allows($requirement)) {
                    continue 2;
                }
            }
            $page['body']   = self::cut($page['body'], $allows);
            $visible[$slug] = $page;
        }

        return $visible;
    }

    /**
     * A body without the blocks this reader may not see: from a line `<!-- requires: x -->` to a
     * line `<!-- /requires -->`. Kept, only the two lines go; refused, everything between them.
     * An unclosed block is cut to the end — hiding is the safe way to be wrong.
     *
     * @param \Closure(string): bool $allows
     */
    private static function cut(string $body, \Closure $allows): string
    {
        if (!str_contains($body, '<!-- requires:')) {
            return $body;
        }

        $kept = [];
        $open = null;
        foreach (explode("\n", $body) as $line) {
            if ($open === null && preg_match('/^\s*<!--\s*requires:\s*([A-Za-z0-9_.:-]+)\s*-->\s*$/', $line, $m) === 1) {
                $open = $allows($m[1]);
                continue;
            }
            if ($open !== null && preg_match('/^\s*<!--\s*\/requires\s*-->\s*$/', $line) === 1) {
                $open = null;
                continue;
            }
            if ($open !== false) {
                $kept[] = $line;
            }
        }

        return trim(implode("\n", $kept));
    }

    /** @return array<string, array<string, mixed>> */
    private function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $pages     = [];
        $framework = $this->framework !== null ? realpath($this->framework) : false;
        foreach ($this->directories as $directory) {
            $root = realpath($directory);
            if ($root === false) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (strtolower($file->getExtension()) !== 'md') {
                    continue;
                }
                $slug = Help::slug(substr((string) $file->getPathname(), strlen($root) + 1, -3));
                if ($slug !== '' && !isset($pages[$slug])) {
                    $pages[$slug] = self::read((string) $file->getPathname(), $slug, $root === $framework);
                }
            }
        }

        return $this->pages = $pages;
    }

    /** @return array{slug: string, title: string, section: string, questions: list<string>, updated: string, order: ?int, requires: list<string>, screens: list<string>, whatsnew: list<string>, body: string, framework: bool} */
    private static function read(string $file, string $slug, bool $framework): array
    {
        ['meta' => $meta, 'body' => $body] = \Pramnos\Support\FrontMatter::parse((string) file_get_contents($file));
        $title = is_string($meta['title'] ?? null) ? $meta['title'] : '';
        if ($title === '' && preg_match('/^#\s+(.+)$/m', $body, $heading) === 1) {
            $title = trim($heading[1]);
        }

        return [
            'slug'      => $slug,
            'title'     => $title !== '' ? $title : $slug,
            'section'   => is_string($meta['section'] ?? null) ? $meta['section'] : ($framework ? 'Administration' : ''),
            'questions' => is_array($meta['questions'] ?? null) ? array_values($meta['questions']) : [],
            // A changelog post says `date:`, a page `updated:`; either is when it is from.
            'updated'   => is_string($meta['updated'] ?? null) ? $meta['updated'] : (is_string($meta['date'] ?? null) ? $meta['date'] : ''),
            'order'     => is_string($meta['order'] ?? null) && is_numeric($meta['order']) ? (int) $meta['order'] : null,
            // What the site's gate must allow for the page to exist; every one of them.
            'requires'  => self::names($meta['requires'] ?? []),
            // The screens it is the page of, beside the one it is named after.
            'screens'   => array_values(array_filter(array_map([Help::class, 'slug'], self::names($meta['screens'] ?? [])), static fn (string $s): bool => $s !== '' && !str_contains($s, '/'))),
            // The lines that announce it in What's new; none for a page that is not announced.
            'whatsnew'  => self::names($meta['whatsnew'] ?? []),
            'body'      => $body,
            'framework' => $framework,
        ];
    }

    /**
     * A front matter value as a list of names: one name, or a list of them.
     *
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        return \Pramnos\Support\FrontMatter::names($value);
    }
}
