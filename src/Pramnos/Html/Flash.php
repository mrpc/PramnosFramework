<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * What `addMessage()` and `addError()` flashed before a redirect, shown once.
 *
 * ```php
 * <?php echo \Pramnos\Html\Flash::render(); ?>   // in the theme's header, under the navigation
 * ```
 *
 * A controller that saves something says so and redirects — `addMessage('Saved.')` — and the
 * screen it lands on is a list that knows nothing about the save. So the place that shows the
 * flash is the page around every screen: the theme's header. Without it the message is written,
 * carried across the redirect, and never seen.
 *
 * It **takes** the flash: shown here, it is gone for the rest of the request. A view renders
 * before the theme's header does, so a view that shows the flash itself — with
 * `Request::takeMessages()` — has already taken it, and the header shows nothing twice.
 *
 * The text is escaped: a flash often carries what somebody typed ("Deleted ‹name›").
 *
 * Neutral hooks, styled by every scaffolded stylesheet: `pf-flash` around them, and each one
 * `pf-flash-message` (`role="status"`) or `pf-flash-error` (`role="alert"`).
 */
final class Flash
{
    /** The flash as markup, or '' when there is none; taken either way. */
    public static function render(): string
    {
        $request  = \Pramnos\Http\Request::getInstance();
        // What the previous request flashed, and what this one added without redirecting — still
        // in the session, where it would otherwise surface on the next page instead of this one.
        $errors   = array_merge($request->takeFlashErrors(), self::pending('_errors'));
        $messages = array_merge($request->takeMessages(), self::pending('_messages'));

        if ($errors === [] && $messages === []) {
            return '';
        }

        $html = '<div class="pf-flash">';
        foreach ($errors as $error) {
            $html .= '<p class="pf-flash-error" role="alert">' . self::e((string) $error) . '</p>';
        }
        foreach ($messages as $message) {
            $html .= '<p class="pf-flash-message" role="status">' . self::e((string) $message) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * What this request added under `$key` and has not shown, taken out of the session.
     *
     * @return list<string>
     */
    private static function pending(string $key): array
    {
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return [];
        }
        $pending = array_values(array_map('strval', $_SESSION[$key]));
        unset($_SESSION[$key]);

        return $pending;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
