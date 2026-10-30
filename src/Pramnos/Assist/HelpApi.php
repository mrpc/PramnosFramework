<?php

declare(strict_types=1);

namespace Pramnos\Assist;

use Pramnos\Routing\Router;

/**
 * The help pages as JSON, for a front end that draws its own pages — a SPA, a mobile application.
 *
 * Mounted with one line in the application's API routes:
 *
 * ```php
 * \Pramnos\Assist\HelpApi::routes($r);
 * ```
 *
 *   - `GET /help`              every page (`?q=` for those that answer a question)
 *   - `GET /help/page?p=faq`   one page, drawn
 *   - `GET /help/screen?screen=posts&action=edit`   the page for a screen, as the side panel finds it
 *
 * The site's pages by default, to anybody. `?level=admin` reads the administration's, and only for
 * somebody who may open the administration's Help screen. A page comes drawn as HTML: links to
 * other pages as `?help=<slug>`, for the front end to open in place, and pictures inline as `data:`
 * addresses — an `<img>` cannot carry the bearer token an API call is authenticated with.
 *
 * Answers are `['status' => …, 'data' => …]` or `['status' => …, 'error' => …]`.
 */
class HelpApi
{
    /** The largest picture drawn inline; a bigger one shows its alt text. */
    public const MAX_IMAGE_BYTES = 1048576;

    /** Mount the three routes, under `$path`. */
    public static function routes(Router $r, string $path = '/help'): void
    {
        $path = '/' . trim($path, '/');

        $r->get($path . '/page', static fn () => (new static())->page());
        $r->get($path . '/screen', static fn () => (new static())->screen());
        $r->get($path, static fn () => (new static())->list());
    }

    /** Every page of the level, by section and title — or those that answer `?q=`. */
    public function list(): array
    {
        if (($stop = $this->refusal()) !== null) {
            return $stop;
        }
        $book = $this->book();

        $query = trim((string) ($_GET['q'] ?? ''));
        $pages = $query === '' ? $book->all() : $book->search($query, 20);

        return ['status' => 200, 'data' => [
            'level' => $book->level(),
            'query' => $query,
            'pages' => array_map(static fn (array $p): array => self::summary($p), $pages),
        ]];
    }

    /** One page, by `?p=`. */
    public function page(): array
    {
        if (($stop = $this->refusal()) !== null) {
            return $stop;
        }
        $book = $this->book();

        $page = $book->page((string) ($_GET['p'] ?? ''));

        return $page === null ? self::error(404, 'There is no such help page.') : ['status' => 200, 'data' => $this->drawn($book, $page)];
    }

    /** The page for `?screen=`, `?action=` and `?option=`, as the side panel finds it. */
    public function screen(): array
    {
        if (($stop = $this->refusal()) !== null) {
            return $stop;
        }
        $book = $this->book();

        $page = $book->forScreen((string) ($_GET['screen'] ?? ''), (string) ($_GET['action'] ?? ''), (string) ($_GET['option'] ?? ''));

        return $page === null ? self::error(404, 'This screen has no help page.') : ['status' => 200, 'data' => $this->drawn($book, $page)];
    }

    /**
     * A refusal to answer with, or null: no `help` feature is no such endpoint, and the
     * administration's pages are for whoever may open its Help screen. A subclass adds its own.
     *
     * @return array{status: int, error: string}|null
     */
    protected function refusal(): ?array
    {
        if (!Help::enabled()) {
            return self::error(404, 'There is no help here.');
        }

        if ($this->level() === HelpBook::ADMIN && !\Pramnos\Auth\AdminAccess::allows(
            \Pramnos\User\User::current(),
            'admin.help',
            \Pramnos\Http\AdminArea::minUserType()
        )) {
            return self::error(403, 'The administration\'s help is for its administrators.');
        }

        return null;
    }

    /** The pages asked for: the site's, unless `?level=admin`. */
    protected function book(): HelpBook
    {
        return Help::book($this->level());
    }

    /** `admin` when asked for, the site's otherwise. */
    private function level(): string
    {
        return ($_GET['level'] ?? '') === HelpBook::ADMIN ? HelpBook::ADMIN : HelpBook::SITE;
    }

    /**
     * A page as the front end draws it.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function drawn(HelpBook $book, array $page): array
    {
        $html = Help::html(
            (string) $page['body'],
            static fn (string $slug): string => '?help=' . rawurlencode($slug),
            static function (string $path) use ($book): ?string {
                $file = $book->image($path);
                // ponytail: inline up to 1 MB each; a page of large screenshots wants an image route with a signed URL.
                if ($file === null || filesize($file) > self::MAX_IMAGE_BYTES) {
                    return null;
                }
                $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                return 'data:' . ($type === 'svg' ? 'image/svg+xml' : 'image/' . ($type === 'jpg' ? 'jpeg' : $type))
                    . ';base64,' . base64_encode((string) file_get_contents($file));
            },
            (string) $page['slug']
        );

        return self::summary($page) + ['updated' => (string) $page['updated'], 'html' => $html];
    }

    /**
     * What a list shows of a page.
     *
     * @param array<string, mixed> $page
     * @return array{slug: string, title: string, section: string, questions: list<string>}
     */
    private static function summary(array $page): array
    {
        return [
            'slug'      => (string) $page['slug'],
            'title'     => (string) $page['title'],
            'section'   => (string) $page['section'],
            'questions' => array_values((array) $page['questions']),
        ];
    }

    /** @return array{status: int, error: string} */
    private static function error(int $status, string $error): array
    {
        return ['status' => $status, 'error' => $error];
    }
}
