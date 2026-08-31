<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The small set of action icons a list needs, as inline SVG.
 *
 * A table of rows ending in `View Edit Deactivate` spends more width on the words than
 * on the data, and reads as a wall of blue text: the actions are the same on every row,
 * so the words carry no information after the first one. Icons carry the same meaning in
 * a fraction of the space — as long as they are still *labelled* for anyone who cannot
 * see them, which is why {@see link()} always emits `aria-label` and `title`.
 *
 * **Inline SVG, not an icon font and not a CSS class.** These are rendered by a
 * controller into JSON that a DataTable inserts, so the markup has to work in all three
 * bundled themes and in an application with its own: a class name would need every theme
 * to know it, and a font would need an asset none of them ship. `currentColor` and a
 * `1em` box mean an icon inherits whatever the surrounding cell already is.
 *
 * ```php
 * $row[] = Icon::link(adminUrl('users/view/') . $id, 'view', 'View this user')
 *        . Icon::link(adminUrl('users/edit/') . $id, 'edit', 'Edit this user')
 *        . Icon::link(adminUrl('users/delete/') . $id, 'deactivate', 'Deactivate', [
 *              'data-confirm' => 'Deactivate this user?',
 *              'class'        => 'pf-action pf-action-danger',
 *          ]);
 * ```
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Icon
{
    /**
     * Stroked 24×24 paths, keyed by what the action *is* rather than what it looks like.
     *
     * Named for the action so a caller asks for `deactivate` and gets whatever the
     * framework thinks that looks like — and so the set can be corrected in one place.
     * Two of these were transposed once in a project's sidebar, which is how it is known
     * that a path is not readable by eye: check the page, not the array.
     *
     * @var array<string, string>
     */
    private const PATHS = [
        'view'       => 'M15 12a3 3 0 11-6 0 3 3 0 016 0z|M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
        'edit'       => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'delete'     => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'deactivate' => 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636',
        'activate'   => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'members'    => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        'tokens'     => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
        'sessions'   => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'lock'       => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
        'unlock'     => 'M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z',
        'password'   => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
        'log'        => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'send'       => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'retry'      => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
    ];

    /**
     * One icon, as SVG.
     *
     * `aria-hidden` because it is decoration *inside* a labelled control — the label is
     * on the anchor. An icon that announces itself as well as its link is read twice.
     *
     * @param string $name One of the names above; an unknown one renders nothing
     */
    public static function svg(string $name, ?string $class = null): string
    {
        if (!isset(self::PATHS[$name])) {
            return '';
        }

        $paths = '';
        foreach (explode('|', self::PATHS[$name]) as $path) {
            $paths .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="'
                . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"/>';
        }

        // `null` means "whatever the project configured", which is `pf-icon` unless it said
        // otherwise. An explicit '' is a caller asking for no class, and is honoured.
        $class ??= ComponentClasses::get('icon');

        return '<svg xmlns="http://www.w3.org/2000/svg" class="' . self::attr($class) . '"'
            . ' width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /**
     * An icon link — the shape a row action takes.
     *
     * The label is not optional and is not decoration: it becomes `aria-label` and
     * `title`, so the action has a name for a screen reader and a tooltip for anybody
     * wondering what a pencil does here. An icon-only control with neither is a control
     * only its author can use.
     *
     * @param string                $url    Where it goes
     * @param string                $name   Icon name, see {@see svg()}
     * @param string                $label  What it does, in words
     * @param array<string, string> $extra  Extra attributes — `data-confirm`, `class`, `target`
     */
    public static function link(string $url, string $name, string $label, array $extra = []): string
    {
        $classes = trim(ComponentClasses::get('action') . ' ' . ($extra['class'] ?? ''));
        unset($extra['class']);

        $attributes = '';
        foreach ($extra as $attribute => $value) {
            $attributes .= ' ' . self::attr((string) $attribute) . '="' . self::attr((string) $value) . '"';
        }

        return '<a href="' . self::attr($url) . '" class="' . self::attr($classes) . '"'
            . ' title="' . self::attr($label) . '" aria-label="' . self::attr($label) . '"'
            . $attributes . '>' . self::svg($name) . '</a>';
    }

    /** The names this class knows, for a caller that wants to check one. */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
