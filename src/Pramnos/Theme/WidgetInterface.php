<?php

declare(strict_types=1);

namespace Pramnos\Theme;

/**
 * Something a theme can render inside a widget area.
 *
 * The interface is deliberately one method wide. A widget is asked for markup and returns it;
 * everything else — where it goes, what wraps it, whether the area exists — belongs to the
 * theme.
 *
 * Implement this directly for full control, or extend {@see Widget}, which handles the
 * `before_widget` / `before_title` wrapping every theme passes and leaves you only the body.
 *
 * ```php
 * class LatestPosts implements WidgetInterface
 * {
 *     public function render(array $args = []): string
 *     {
 *         return '<ul>…</ul>';
 *     }
 * }
 *
 * $theme->widgets()->register('latest-posts', LatestPosts::class);
 * ```
 *
 * Nothing here is loaded, constructed or asked anything unless a widget area that has stored
 * widgets is actually rendered.
 *
 * @see Widget for the optional base class
 * @see WidgetRegistry for how a stored widget record finds its class
 */
interface WidgetInterface
{
    /**
     * The widget's markup.
     *
     * @param array<string, mixed> $args The area's arguments merged with this widget's stored
     *                                   settings — `before_widget`, `after_widget`,
     *                                   `before_title`, `after_title`, `title`, plus whatever
     *                                   the widget itself was configured with
     * @return string Markup, or an empty string to render nothing
     */
    public function render(array $args = []): string;
}
