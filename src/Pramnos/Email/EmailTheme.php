<?php

namespace Pramnos\Email;

use Pramnos\Application\Settings;

/**
 * The HTML shell a sent email is wrapped in.
 *
 * `mailtemplates` has carried an `emailtemplate` column since 2020 — the administration
 * screen renders a field for it, `save()` writes it, and nothing has ever read it. So the
 * answer to "does this framework support themed email" was "there is a column for it",
 * which is the same as no.
 *
 * What was missing is small: mail bodies are written as fragments (a paragraph, a code, a
 * link) and every application then wants the same shell around all of them — its logo, its
 * colours, an unsubscribe line, a footer with a company name in it. Without somewhere to
 * put that shell it ends up copied into each body, and then the day the address in the
 * footer changes it is changed in some of them.
 *
 * Off unless asked for. `emailtheme` is empty on an existing installation, and an empty
 * name wraps nothing — the body goes out exactly as it did before. An application opts in
 * by naming a wrapper, and one mail can override the choice with
 * {@see Email::setTemplate()}, which is what `mailtemplates.emailtemplate` is for.
 *
 * ## Where a wrapper lives
 *
 * `{name}.html.php`, looked for in this order:
 *
 * 1. `app/emails/` — the application's own
 * 2. `emails/` at the project root — an older layout
 * 3. the framework's bundled `default`
 *
 * Not per theme, deliberately. A theme is a stylesheet and an email cannot use one: HTML
 * mail is inline attributes and nested tables, because that is what mail clients read. A
 * wrapper is its own artefact, so it is named rather than derived, and an application that
 * wants two looks names two wrappers.
 *
 * ## What a wrapper receives
 *
 * The file is included with these in scope:
 *
 * - `$content`  — the body being wrapped, already HTML
 * - `$subject`  — the subject line, for a preheader or a title
 * - `$sitename`, `$siteurl`, `$year` — from the settings, so the shell needs no arguments
 *
 * Anything else passed to {@see wrap()} is in scope too, under its own key.
 *
 * ## It fails open
 *
 * A wrapper that does not exist, or that raises while rendering, logs and returns the
 * unwrapped body. A mail whose *shell* is broken still has to be delivered: the code in it
 * is what the recipient is waiting for, and a missing footer is not a reason to withhold
 * it.
 */
class EmailTheme
{
    /** The setting naming the wrapper every mail uses by default. Empty means none. */
    public const SETTING = 'emailtheme';

    /**
     * Wrap a body, or return it unchanged.
     *
     * @param string  $html   The body, as HTML.
     * @param ?string $name   Wrapper name; null falls back to the `emailtheme` setting.
     * @param array<string, mixed> $tokens Extra variables for the wrapper.
     */
    public static function wrap(string $html, ?string $name = null, array $tokens = []): string
    {
        if ($html === '') {
            return $html;
        }

        // Only null asks for the installation's default. An empty string is a decision —
        // "send this one bare" — and treating the two the same would make that decision
        // impossible to express on an installation that wraps everything.
        if ($name === null) {
            $name = (string) (Settings::getSetting(self::SETTING) ?? '');
        }

        $name = trim($name);

        if ($name === '') {
            return $html;
        }

        $file = static::locate($name);

        if ($file === null) {
            // Named and not found is worth a line: it is the difference between "we do not
            // wrap mail here" and "the wrapper this installation asked for is missing", and
            // the page that names it cannot tell the operator which.
            \Pramnos\Logs\Logger::log(
                'Email wrapper "' . $name . '" was not found; the message was sent unwrapped.',
                'email'
            );

            return $html;
        }

        try {
            return static::render($file, $html, $tokens);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Email wrapper "' . $name . '" failed to render (' . $exception->getMessage()
                . '); the message was sent unwrapped.',
                'email'
            );

            return $html;
        }
    }

    /**
     * The file a wrapper name resolves to, or null.
     *
     * The name is checked against `[A-Za-z0-9_-]` before it reaches a path, because it
     * arrives from a database column an administrator edits: a name is a name, and anything
     * with a separator in it is refused rather than sanitised, so there is nothing to get
     * subtly wrong about how many `..` a path can contain.
     */
    public static function locate(string $name): ?string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            return null;
        }

        foreach (static::directories() as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $name . '.html.php';

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Where wrappers may live, most specific first.
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        $candidates = [];

        if (defined('APP_PATH')) {
            $candidates[] = APP_PATH . DIRECTORY_SEPARATOR . 'emails';
        }

        if (defined('ROOT')) {
            $candidates[] = ROOT . DIRECTORY_SEPARATOR . 'emails';
        }

        // The bundled one, so `default` resolves on an installation that has published
        // nothing. Last, so an application's own wrapper of the same name wins.
        $candidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'scaffolding'
            . DIRECTORY_SEPARATOR . 'emails';

        return array_values(array_unique($candidates));
    }

    /**
     * Include the wrapper with the body in scope.
     *
     * @param array<string, mixed> $tokens
     */
    protected static function render(string $file, string $html, array $tokens): string
    {
        $variables = array_merge([
            'subject'  => '',
            'sitename' => (string) (Settings::getSetting('sitename') ?? ''),
            'siteurl'  => (string) (Settings::getSetting('site_url') ?? ''),
            'year'     => date('Y'),
        ], $tokens);

        // `content` is not overridable: a wrapper that received a different body than the
        // one being sent would be a very confusing afternoon.
        $variables['content'] = $html;

        ob_start();

        try {
            (static function (array $variables, string $file): void {
                extract($variables, EXTR_SKIP);
                require $file;
            })($variables, $file);

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }
}
