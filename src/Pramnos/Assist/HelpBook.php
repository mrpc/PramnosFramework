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
     * The page of a screen's action, or of the screen — the application's before the
     * framework's, whichever of the two it wrote: an application that wrote `users.md` means it
     * for the edit form too, and the framework's `users/edit.md` must not win over it.
     *
     * @return array<string, mixed>|null
     */
    public function forScreen(string $screen, string $action = ''): ?array
    {
        $screen = Help::slug($screen);
        $action = Help::slug($action);

        if ($screen === '' || str_contains($screen, '/')) {
            return null;
        }

        $forAction = $action !== '' && $action !== 'display' ? $this->page($screen . '/' . $action) : null;
        $forScreen = $this->page($screen);

        foreach ([$forAction, $forScreen] as $page) {
            if ($page !== null && !$page['framework']) {
                return $page;
            }
        }

        return $forAction ?? $forScreen ?? $this->claiming($screen);
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
     * The pages that answer a question, best first.
     *
     * A page's title and `questions:` count three times what its text does, each word up to three
     * times, and each word of the question by how rare it is across the pages: a word on every
     * page tells them apart no better than "how". A question that is one of a page's
     * `questions:` puts it first; one whose every word is in such a question, nearly so. Words
     * are compared as {@see Help::words()} folds them, and a four-letter one also by prefix, so
     * `goal` finds `goals`.
     *
     * @return list<array<string, mixed>> Each page with its `score`
     */
    public function search(string $question, int $limit = 5): array
    {
        $asked = array_values(array_unique(Help::words($question)));
        if ($asked === []) {
            return [];
        }

        $indexed = [];
        foreach ($this->visible() as $slug => $page) {
            $indexed[$slug] = [
                'strong'    => array_count_values(Help::words($page['title'] . ' ' . implode(' ', $page['questions']))),
                'weak'      => array_count_values(Help::words($page['body'])),
                'questions' => array_map(static fn (string $q): array => array_values(array_unique(Help::words($q))), $page['questions']),
            ];
        }

        // How many pages carry each word: the rarer, the more it says.
        $total  = count($indexed);
        $weight = [];
        foreach ($asked as $word) {
            $carrying = 0;
            foreach ($indexed as $terms) {
                $carrying += self::hits($word, $terms['strong']) + self::hits($word, $terms['weak']) > 0 ? 1 : 0;
            }
            $weight[$word] = log(1 + $total / max(1, $carrying));
        }

        $found = [];
        foreach ($indexed as $slug => $terms) {
            $score = 0.0;
            foreach ($asked as $word) {
                $score += $weight[$word] * (3 * min(3, self::hits($word, $terms['strong'])) + min(3, self::hits($word, $terms['weak'])));
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
            $found[] = $this->visible()[$slug] + ['score' => round($score, 3)];
        }

        usort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($found, 0, max(1, $limit));
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
        $this->pages = null;
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
        $text   = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file));
        $meta   = [];
        $body   = $text;

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $m) === 1) {
            $body = $m[2];
            $key  = '';
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^([A-Za-z_]+):\s*(.*)$/', $line, $pair) === 1) {
                    $key   = strtolower($pair[1]);
                    $value = trim($pair[2]);
                    // `[a, b]` is a list on one line.
                    $meta[$key] = preg_match('/^\[(.*)\]$/', $value, $list) === 1
                        ? array_values(array_filter(array_map(static fn (string $v): string => trim($v, " \"'"), explode(',', $list[1])), static fn (string $v): bool => $v !== ''))
                        : (trim($value, " \"'") === '' ? [] : trim($value, " \"'"));
                } elseif ($key !== '' && preg_match('/^\s*-\s+(.*)$/', $line, $item) === 1) {
                    $meta[$key]   = is_array($meta[$key]) ? $meta[$key] : [];
                    $meta[$key][] = trim($item[1], " \"'");
                }
            }
        }

        $body  = trim($body);
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
        $names = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map('trim', array_map('strval', $names)), static fn (string $n): bool => $n !== ''));
    }
}
