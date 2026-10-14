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
     * One page: `slug`, `title`, `section`, `questions`, `updated`, `body` (without front matter),
     * `framework` (shipped rather than the application's) — or null.
     *
     * @return array{slug: string, title: string, section: string, questions: list<string>, updated: string, body: string, framework: bool}|null
     */
    public function page(string $slug): ?array
    {
        $slug = Help::slug($slug);

        return $slug === '' ? null : ($this->pages()[$slug] ?? null);
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

        return $forAction ?? $forScreen;
    }

    /**
     * Every page, the application's before the framework's, by section and title.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $pages = array_values($this->pages());
        $key = static fn (array $p): array => [$p['framework'], mb_strtolower($p['section']), mb_strtolower($p['title'])];
        usort($pages, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $pages;
    }

    /**
     * The pages that answer a question, best first: its title and questions count three times
     * what its text does. See {@see Help::words()} for how words are compared.
     *
     * @return list<array<string, mixed>> Each page with its `score`
     */
    public function search(string $question, int $limit = 5): array
    {
        $asked = array_unique(Help::words($question));
        if ($asked === []) {
            return [];
        }

        $found = [];
        foreach ($this->pages() as $page) {
            $strong = array_count_values(Help::words($page['title'] . ' ' . implode(' ', $page['questions'])));
            $weak   = array_count_values(Help::words($page['body']));
            $score  = 0;
            foreach ($asked as $word) {
                $score += 3 * min(3, $strong[$word] ?? 0) + min(3, $weak[$word] ?? 0);
            }
            if ($score > 0) {
                $found[] = $page + ['score' => $score];
            }
        }

        usort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($found, 0, max(1, $limit));
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

    /** @return array{slug: string, title: string, section: string, questions: list<string>, updated: string, body: string, framework: bool} */
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
                    $key        = strtolower($pair[1]);
                    $meta[$key] = trim($pair[2], " \"'") === '' ? [] : trim($pair[2], " \"'");
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
            'updated'   => is_string($meta['updated'] ?? null) ? $meta['updated'] : '',
            'body'      => $body,
            'framework' => $framework,
        ];
    }
}
