<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The installation's help: Markdown pages, the application's before the framework's.
 *
 * ```
 * app/help/users.md            the Users screen — every action of it
 * app/help/users/edit.md       only its edit form
 * app/help/billing/refunds.md  a page of its own, reached from the list or a link
 * app/help/images/refund.png   a picture a page shows: ![The form](images/refund.png)
 * ```
 *
 * A page's name — its slug — is its path without `.md`. A screen's page is named after the
 * screen (see {@see Page}), so the side panel finds it on its own. The framework ships one for each
 * of its screens under `resources/help/`, and a page of the same name in `app/help/` replaces it.
 * `'help' => ['paths' => [...]]` in `app/app.php` searches other directories instead of
 * `app/help`.
 *
 * Optional front matter says what the file cannot:
 *
 * ```markdown
 * ---
 * title: Refunds
 * section: Billing
 * questions:
 *   - How do I refund an order?
 *   - Can a customer get their money back?
 * ---
 * # Refunds
 * ```
 *
 * `questions` are what a reader would ask; a search matches them before the text. Between pages,
 * `[Refunds](help:billing/refunds)` links by name, so the files are not tied to a host.
 */
final class Help
{
    /** The most of one page a tool result carries. */
    public const MAX_CHARS = 6000;

    /** Picture types a page may show. */
    private const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    /** @var list<string>|null The directories searched, first match wins; null for the defaults */
    private static ?array $directories = null;

    /** @var array<string, array<string, mixed>>|null Every page, by slug, once read */
    private static ?array $pages = null;

    /**
     * One page: `slug`, `title`, `section`, `questions`, `updated`, `body` (without front matter),
     * `framework` (shipped rather than the application's) — or null.
     *
     * @return array{slug: string, title: string, section: string, questions: list<string>, updated: string, body: string, framework: bool}|null
     */
    public static function page(string $slug): ?array
    {
        $slug = self::slug($slug);

        return $slug === '' ? null : (self::pages()[$slug] ?? null);
    }

    /** The Markdown of a screen's action, or of the screen, or null. */
    public static function text(string $screen, string $action = ''): ?string
    {
        return self::forScreen($screen, $action)['body'] ?? null;
    }

    /**
     * The page of a screen's action, or of the screen.
     *
     * @return array<string, mixed>|null
     */
    public static function forScreen(string $screen, string $action = ''): ?array
    {
        $screen = self::slug($screen);
        $action = self::slug($action);

        if ($screen === '' || str_contains($screen, '/')) {
            return null;
        }

        return ($action !== '' && $action !== 'display' ? self::page($screen . '/' . $action) : null) ?? self::page($screen);
    }

    /**
     * Every page, the application's before the framework's, by section and title.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $pages = array_values(self::pages());
        $key = static fn (array $p): array => [$p['framework'], mb_strtolower($p['section']), mb_strtolower($p['title'])];
        usort($pages, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $pages;
    }

    /**
     * The pages that answer a question, best first: its title and questions count three times
     * what its text does. Words are compared folded — case, accents and the final sigma — and
     * by their first five letters, so `λογαριασμού` finds `λογαριασμός`.
     *
     * @return list<array<string, mixed>> Each page with its `score`
     */
    public static function search(string $question, int $limit = 5): array
    {
        $asked = array_unique(self::words($question));
        if ($asked === []) {
            return [];
        }

        $found = [];
        foreach (self::pages() as $page) {
            $strong = array_count_values(self::words($page['title'] . ' ' . implode(' ', $page['questions'])));
            $weak   = array_count_values(self::words($page['body']));
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

    /**
     * A page's Markdown as HTML that is safe to print: `help:` links to `$pageUrl`, and pictures
     * beside the page to `$imageUrl` — each given the path and answering the address.
     *
     * @param \Closure(string): string|null $pageUrl
     * @param \Closure(string): string|null $imageUrl
     */
    public static function html(string $markdown, ?\Closure $pageUrl = null, ?\Closure $imageUrl = null, string $from = ''): string
    {
        $base = str_contains($from, '/') ? substr($from, 0, (int) strrpos($from, '/') + 1) : '';

        return \Pramnos\Llm\Chat\Markdown::toHtml($markdown, [
            'link'  => static function (string $target) use ($pageUrl): ?string {
                return $pageUrl !== null && str_starts_with($target, 'help:') && ($slug = self::slug(substr($target, 5))) !== ''
                    ? $pageUrl($slug)
                    : null;
            },
            'image' => static function (string $source) use ($imageUrl, $base): ?string {
                return $imageUrl !== null && !preg_match('#^[a-z][a-z0-9+.-]*:|^/#i', $source)
                    ? $imageUrl($base . $source)
                    : null;
            },
        ]);
    }

    /**
     * The help page's address — the list, or one page — in the administration area, from a web
     * request or a worker alike: `$base` is the area's URL as the asking request saw it.
     */
    public static function url(string $slug = '', string $base = ''): string
    {
        return self::area('Help' . ($slug !== '' ? '/page?p=' . rawurlencode($slug) : ''), $base);
    }

    /**
     * The application's help screen class, or '' when it has none — an installation made before
     * the screen existed adds `src/Admin/Controllers/Help.php` extending `HelpController`.
     */
    public static function screen(): string
    {
        $app       = \Pramnos\Application\Application::currentInstance();
        $namespace = trim((string) (is_object($app) ? ($app->applicationInfo['namespace'] ?? '') : ''), '\\');

        foreach ([$namespace . '\\Admin\\Controllers\\Help', $namespace . '\\Controllers\\Help'] as $class) {
            if ($namespace !== '' && class_exists($class) && is_a($class, \Pramnos\Application\Controllers\HelpController::class, true)) {
                return $class;
            }
        }

        return '';
    }

    /** A picture's address, for a signed-in reader. */
    public static function imageUrl(string $path): string
    {
        return self::area('Help/image?f=' . rawurlencode($path));
    }

    /** A page as HTML, its links and pictures pointing at the help page. */
    public static function pageHtml(array $page): string
    {
        return self::html((string) $page['body'], static fn (string $slug): string => self::url($slug), static fn (string $path): string => self::imageUrl($path), (string) $page['slug']);
    }

    /** An address in the area: see {@see \Pramnos\Http\AdminArea::address()}. */
    private static function area(string $path, string $base = ''): string
    {
        return \Pramnos\Http\AdminArea::address($path, $base);
    }

    /** The file of a picture a page shows, if it is one and lies inside a help directory. */
    public static function image(string $path): ?string
    {
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGES, true)) {
            return null;
        }

        foreach (self::directories() as $directory) {
            $root = realpath($directory);
            $file = $root === false ? false : realpath($root . '/' . $path);
            if ($root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** A page's text, cut to what one tool result may carry. */
    public static function excerpt(string $body, int $chars = self::MAX_CHARS): string
    {
        return mb_strlen($body) > $chars ? rtrim(mb_substr($body, 0, $chars)) . "\n\n[…]" : $body;
    }

    /**
     * Where the pages are, first match wins: the application's — `'help' => ['paths' => …]` or
     * `app/help` — then the framework's.
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        if (self::$directories !== null) {
            return self::$directories;
        }

        $app   = \Pramnos\Application\Application::currentInstance();
        $paths = is_object($app) ? ($app->applicationInfo['help']['paths'] ?? null) : null;
        $own   = is_array($paths) ? array_map('strval', $paths) : (defined('ROOT') ? [ROOT . '/app/help'] : []);

        return [...$own, self::frameworkDirectory()];
    }

    /** @param list<string>|null $directories Where to look, in order; null for the defaults */
    public static function useDirectories(?array $directories): void
    {
        self::$directories = $directories;
        self::$pages       = null;
    }

    /** The framework's own pages. */
    public static function frameworkDirectory(): string
    {
        return dirname(__DIR__, 3) . '/resources/help';
    }

    /** A name as a page is named: lowercase path segments of letters, digits, `-` and `_`. */
    public static function slug(string $value): string
    {
        $parts = array_filter(explode('/', strtolower(trim($value, '/ '))), static fn (string $p): bool => $p !== '');
        $parts = array_map(static fn (string $p): string => (string) preg_replace('/[^a-z0-9_-]/', '', $p), $parts);

        return implode('/', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Words as a search compares them.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }
        // Without intl, and for what it leaves: Greek tonos and dialytika, and the final sigma.
        $text = strtr($text, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ']);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(
            static fn (string $w): string => mb_substr($w, 0, 5),
            array_filter($words, static fn (string $w): bool => mb_strlen($w) > 2)
        ));
    }

    /** Forget what was read — for a test, or a worker between requests. */
    public static function forget(): void
    {
        self::$pages = null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function pages(): array
    {
        if (self::$pages !== null) {
            return self::$pages;
        }

        $pages       = [];
        $directories = self::directories();
        // The framework's are the last searched — under every directory of the application's.
        $framework   = realpath((string) end($directories));
        foreach ($directories as $directory) {
            $root = realpath($directory);
            if ($root === false) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (strtolower($file->getExtension()) !== 'md') {
                    continue;
                }
                $slug = self::slug(substr((string) $file->getPathname(), strlen($root) + 1, -3));
                if ($slug !== '' && !isset($pages[$slug])) {
                    $pages[$slug] = self::read((string) $file->getPathname(), $slug, $root === $framework);
                }
            }
        }

        return self::$pages = $pages;
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
