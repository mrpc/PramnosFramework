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
 *
 * ## Two levels
 *
 * The pages above are the **administration's**. A site's visitors have their own, in
 * `app/help-site/` (or `'help' => ['site' => ['paths' => [...]]]`), written the same way and
 * never mixed with these: {@see site()} reads only them, and the framework's pages are not among
 * them. Which level a page is on is {@see level()}: outside the administration area, where an
 * application has one, it is the site's. The static methods here are the administration's, as
 * they always were; {@see book()} is the level of the request.
 */
final class Help
{
    /** The most of one page a tool result carries. */
    public const MAX_CHARS = 6000;

    /** Picture types a page may show. */
    public const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    /** @var list<string>|null The administration's directories; null for the defaults */
    private static ?array $directories = null;

    /** @var list<string>|null The site's directories; null for the defaults */
    private static ?array $siteDirectories = null;

    /** @var array<string, HelpBook> The books built so far, by level */
    private static array $books = [];

    /** @var HelpGate|false|null The site's gate once built; false for none */
    private static HelpGate|false|null $gate = null;

    /**
     * The site's {@see HelpGate}: `'help' => ['site' => ['gate' => Class::class]]`, built once — or
     * null, when there is none and every requirement is refused.
     */
    public static function siteGate(): ?HelpGate
    {
        if (self::$gate === null) {
            $app   = \Pramnos\Application\Application::currentInstance();
            $class = is_object($app) ? ($app->applicationInfo['help']['site']['gate'] ?? null) : null;
            if (is_string($class) && class_exists($class) && is_a($class, HelpGate::class, true)) {
                self::$gate = new $class();
            } else {
                if ($class !== null) {
                    \Pramnos\Logs\Logger::log('help.site.gate is not a HelpGate: every requirement is refused.', 'help');
                }
                self::$gate = false;
            }
        }

        return self::$gate === false ? null : self::$gate;
    }

    /** The site's gate, for a test or an application that builds its own; null to read app.php again. */
    public static function useSiteGate(?HelpGate $gate): void
    {
        self::$gate = $gate;
    }

    /**
     * The administration's pages: `app/help` (or `help.paths`), then the framework's — the last
     * directory searched, whichever it is.
     */
    public static function admin(): HelpBook
    {
        if (!isset(self::$books[HelpBook::ADMIN])) {
            $directories = self::directories();
            self::$books[HelpBook::ADMIN] = new HelpBook(HelpBook::ADMIN, $directories, $directories === [] ? null : end($directories));
        }

        return self::$books[HelpBook::ADMIN];
    }

    /** The site's pages: `app/help-site` (or `help.site.paths`) — and nothing of the administration's. */
    public static function site(): HelpBook
    {
        return self::$books[HelpBook::SITE] ??= new HelpBook(HelpBook::SITE, self::siteDirectories());
    }

    /**
     * The level of this request: the site's outside the administration area, where the
     * application has one — a prefix it strips, or an administration served from a front
     * controller of its own. An application with no area at all has one level, the
     * administration's, as it always had.
     */
    public static function level(): string
    {
        return \Pramnos\Http\AdminArea::prefix() !== '' && !\Pramnos\Http\AdminArea::isActive()
            ? HelpBook::SITE
            : HelpBook::ADMIN;
    }

    /** The pages of a level — this request's when none is named. */
    public static function book(?string $level = null): HelpBook
    {
        return ($level ?? self::level()) === HelpBook::SITE ? self::site() : self::admin();
    }

    /** One page of the administration's: see {@see HelpBook::page()}. */
    public static function page(string $slug): ?array
    {
        return self::admin()->page($slug);
    }

    /**
     * Whether the installation uses its help pages: the `help` feature, in `app.php`'s
     * `features`. Off, the side panel shows no Help tab, neither help screen answers, and the
     * analyst neither has the `help` tool nor reads a page's help. The pages themselves can
     * still be read through this class.
     */
    public static function enabled(): bool
    {
        return \Pramnos\Application\FeatureRegistry::isEnabled('help');
    }

    /** The Markdown of a screen's action, or of the screen, or null — the administration's. */
    public static function text(string $screen, string $action = ''): ?string
    {
        return self::forScreen($screen, $action)['body'] ?? null;
    }

    /**
     * The administration's page for a screen's action, or the screen: see {@see HelpBook::forScreen()}.
     *
     * @return array<string, mixed>|null
     */
    public static function forScreen(string $screen, string $action = ''): ?array
    {
        return self::admin()->forScreen($screen, $action);
    }

    /**
     * Every page of the administration's, the application's before the framework's.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::admin()->all();
    }

    /**
     * The administration's pages that answer a question, best first: see {@see HelpBook::search()}.
     * Words are compared folded — case, accents and the final sigma — and by their first five
     * letters, so `λογαριασμού` finds `λογαριασμός`.
     *
     * @return list<array<string, mixed>> Each page with its `score`
     */
    public static function search(string $question, int $limit = 5): array
    {
        return self::admin()->search($question, $limit);
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
     * The administration's help page address — the list, or one page — from a web request or a
     * worker alike: `$base` is the area's URL as the asking request saw it.
     */
    public static function url(string $slug = '', string $base = ''): string
    {
        return self::admin()->url($slug, $base);
    }

    /**
     * The application's administration help screen class, or '' when it has none — an
     * installation made before the screen existed adds `src/Admin/Controllers/Help.php`
     * extending `HelpController`.
     */
    public static function screen(): string
    {
        return self::admin()->screen();
    }

    /** A picture's address in the administration, for a signed-in reader. */
    public static function imageUrl(string $path): string
    {
        return self::admin()->imageUrl($path);
    }

    /** An administration page as HTML, its links and pictures pointing at the help page. */
    public static function pageHtml(array $page): string
    {
        return self::admin()->pageHtml($page);
    }

    /** The file of a picture an administration page shows: see {@see HelpBook::image()}. */
    public static function image(string $path): ?string
    {
        return self::admin()->image($path);
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
        unset(self::$books[HelpBook::ADMIN]);
    }

    /**
     * Where the site's pages are: `'help' => ['site' => ['paths' => …]]` or `app/help-site`.
     *
     * @return list<string>
     */
    public static function siteDirectories(): array
    {
        if (self::$siteDirectories !== null) {
            return self::$siteDirectories;
        }

        $app   = \Pramnos\Application\Application::currentInstance();
        $paths = is_object($app) ? ($app->applicationInfo['help']['site']['paths'] ?? null) : null;

        return is_array($paths) ? array_values(array_map('strval', $paths)) : (defined('ROOT') ? [ROOT . '/app/help-site'] : []);
    }

    /** @param list<string>|null $directories The site's, in order; null for the defaults */
    public static function useSiteDirectories(?array $directories): void
    {
        self::$siteDirectories = $directories;
        unset(self::$books[HelpBook::SITE]);
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

        // Function words say nothing about which page answers: "how do I" is in every question.
        return array_values(array_map(
            static fn (string $w): string => mb_substr($w, 0, 5),
            array_filter($words, static fn (string $w): bool => mb_strlen($w) > 2 && !isset(self::STOP[$w]))
        ));
    }

    /**
     * Words a search ignores, folded as {@see words()} folds them: English and Greek function
     * words of three letters or more (two-letter words are ignored anyway).
     *
     * @var array<string, true>
     */
    private const STOP = [
        'the' => true, 'and' => true, 'how' => true, 'what' => true, 'can' => true, 'for' => true, 'with' => true,
        'from' => true, 'this' => true, 'that' => true, 'does' => true, 'are' => true, 'you' => true, 'your' => true,
        'who' => true, 'why' => true, 'when' => true, 'where' => true, 'which' => true, 'will' => true, 'into' => true,
        'about' => true, 'there' => true, 'have' => true, 'has' => true, 'not' => true, 'but' => true, 'was' => true,
        'were' => true, 'did' => true, 'should' => true, 'could' => true, 'would' => true, 'its' => true, 'our' => true,
        'any' => true, 'all' => true, 'get' => true, 'one' => true,
        'πωσ' => true, 'και' => true, 'για' => true, 'τον' => true, 'την' => true, 'του' => true, 'των' => true,
        'τουσ' => true, 'τισ' => true, 'τη' => true, 'στο' => true, 'στη' => true, 'στην' => true, 'στον' => true,
        'στα' => true, 'στουσ' => true, 'στισ' => true, 'απο' => true, 'οτι' => true, 'που' => true, 'ποιοσ' => true,
        'ποια' => true, 'ποιο' => true, 'ειναι' => true, 'εχω' => true, 'ενα' => true, 'εναν' => true, 'ενασ' => true,
        'μια' => true, 'μιασ' => true, 'αυτο' => true, 'αυτη' => true, 'αυτα' => true, 'μου' => true, 'σου' => true,
        'μασ' => true, 'σασ' => true, 'τουσ' => true, 'οταν' => true, 'μπορω' => true, 'γινεται' => true, 'πρεπει' => true,
    ];

    /** Forget what was read, at both levels — for a test, or a worker between requests. */
    public static function forget(): void
    {
        self::$books = [];
    }
}
