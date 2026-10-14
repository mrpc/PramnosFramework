<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The page an administrator is looking at — what the side panel's help is for, and what a
 * question asked from the panel is about.
 *
 * Recorded by `Controller::exec()` for the request's controller, so every screen has a page
 * without code of its own: its screen name (`users` for `Admin\Controllers\Users`, or a
 * framework `UsersController` used directly), the action and the address. A screen whose
 * actions take one record's id says what that record is in one property, and the page knows
 * which record is open:
 *
 * ```php
 * class Posts extends \Pramnos\Application\Controller
 * {
 *     protected string $assistSubject = 'post';   // /admin/Posts/edit/12 is post 12
 * }
 * ```
 *
 * An action that knows better names it itself — `Page::about('post', 12, 'Spring launch')`.
 *
 * What comes back from the browser with a question is checked against the same shape and
 * trimmed: it steers the analyst's answer and is never used to read anything.
 */
final class Page
{
    /** The longest any one value may be, from the browser. */
    private const MAX = 200;

    private static string $screen = '';

    /** Whether a controller of this request has been recorded. */
    private static bool $entered = false;

    private static string $action = '';

    /** @var array{kind: string, id: string, label: string}|null */
    private static ?array $subject = null;

    /**
     * A controller is running: the first one is the page's. Its `$assistSubject` and the
     * address's id say which record is open, unless an action named one already.
     */
    public static function entered(object $controller, string $action, string $subjectKind = ''): void
    {
        if (self::$entered) {
            return;
        }
        self::$entered = true;

        // The letters a help page's name can have: an anonymous class's name carries a path.
        $short        = (string) strtok((new \ReflectionClass($controller))->getShortName(), "@\0");
        self::$screen = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) preg_replace('/Controller$/', '', $short)));
        self::$action = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $action === '' ? 'display' : $action));

        $id = \Pramnos\Http\Request::staticGetOption();
        if (self::$subject === null && $subjectKind !== '' && is_scalar($id) && trim((string) $id) !== '') {
            self::$subject = ['kind' => $subjectKind, 'id' => self::trim((string) $id), 'label' => ''];
        }
    }

    /** The record this page shows, named by the action that opened it. */
    public static function about(string $kind, int|string $id, string $label = ''): void
    {
        self::$subject = ['kind' => self::trim($kind), 'id' => self::trim((string) $id), 'label' => self::trim($label)];
    }

    /** The screen's name, lowercase — `users` — or '' outside any controller. */
    public static function screen(): string
    {
        return self::$screen;
    }

    public static function action(): string
    {
        return self::$action;
    }

    /** @return array{kind: string, id: string, label: string}|null */
    public static function subject(): ?array
    {
        return self::$subject;
    }

    /**
     * Everything the panel sends back with a question.
     *
     * @return array{screen: string, action: string, title: string, url: string, kind: string, id: string, label: string}
     */
    public static function context(): array
    {
        $title = '';
        try {
            $title = (string) (\Pramnos\Framework\Factory::getDocument()->title ?? '');
        } catch (\Throwable) {
            // No document: a page drawn outside the application.
        }

        return [
            'screen' => self::$screen,
            'action' => self::$action,
            'title'  => self::trim($title),
            'url'    => self::trim((string) ($_SERVER['REQUEST_URI'] ?? '')),
            'kind'   => self::$subject['kind'] ?? '',
            'id'     => self::$subject['id'] ?? '',
            'label'  => self::$subject['label'] ?? '',
        ];
    }

    /**
     * A page as the browser sent it back, keeping only the known keys, as short strings.
     *
     * @return array{screen: string, action: string, title: string, url: string, kind: string, id: string, label: string}|null
     */
    public static function fromInput(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }

        $page = [];
        foreach (['screen', 'action', 'title', 'url', 'kind', 'id', 'label'] as $key) {
            $value      = $input[$key] ?? '';
            $page[$key] = is_scalar($value) ? self::trim((string) $value) : '';
        }
        // A screen name finds a help file: letters, digits, dashes and underscores only.
        foreach (['screen', 'action'] as $key) {
            $page[$key] = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $page[$key]));
        }

        return $page['screen'] === '' && $page['url'] === '' ? null : $page;
    }

    /**
     * What the analyst is told about the page a question came from, or '' for none.
     *
     * @param array<string, string>|null $page As {@see fromInput()} answers
     */
    public static function prompt(?array $page): string
    {
        if ($page === null) {
            return '';
        }

        $clean = static fn (string $v): string => \Pramnos\Llm\Analyst\Redactor::clean(str_replace(["\n", '"'], [' ', "'"], $v));
        $lines = ['The administrator is asking from a page of the administration: "' . $clean($page['title'] ?: $page['screen']) . '"'
            . ($page['url'] !== '' ? ' at ' . $clean($page['url']) : '') . '.'];

        if ($page['kind'] !== '' && $page['id'] !== '') {
            $lines[] = 'It shows one ' . $clean($page['kind']) . ', id ' . $clean($page['id'])
                . ($page['label'] !== '' ? ' ("' . $clean($page['label']) . '")' : '') . '. When the question says "this", '
                . '"here" or names nothing else, it means that ' . $clean($page['kind']) . '; read it with your tools '
                . 'when one can, and say so when none can.';
        } else {
            $lines[] = 'When the question says "this" or "here", it means that page.';
        }

        $help = Help::text($page['screen'], $page['action']);
        if ($help !== null) {
            $lines[] = "What the page's help says about it:\n" . mb_substr($help, 0, 4000);
        }

        return implode("\n", $lines);
    }

    /** For a test, and a worker between requests: no page. */
    public static function reset(): void
    {
        self::$screen  = '';
        self::$action  = '';
        self::$subject = null;
        self::$entered = false;
    }

    private static function trim(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value)), 0, self::MAX);
    }
}
