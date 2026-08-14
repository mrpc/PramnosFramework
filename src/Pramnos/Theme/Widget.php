<?php

declare(strict_types=1);

namespace Pramnos\Theme;

/**
 * A widget that only has to write its own body.
 *
 * Every theme passes the same four wrapping arguments — `before_widget`, `after_widget`,
 * `before_title`, `after_title` — and every widget would otherwise assemble them itself, the
 * same way, with the same chance of forgetting the title when it is empty. This base class
 * does that once and calls {@see content()} for the part that differs.
 *
 * ```php
 * class LatestPosts extends Widget
 * {
 *     protected function content(array $args): string
 *     {
 *         return '<ul>…</ul>';
 *     }
 * }
 * ```
 *
 * Which renders as:
 *
 * ```html
 * <aside class="widget">      ← before_widget, from the area
 *   <h3>Latest posts</h3>     ← before_title + title + after_title, if a title was set
 *   <ul>…</ul>                ← content()
 * </aside>                    ← after_widget
 * ```
 *
 * **A widget with nothing to say renders nothing at all** — no empty wrapper, no stray
 * heading. An area whose widgets are all empty produces an empty string, so a theme can test
 * the result rather than having to ask each widget in advance.
 *
 * The title is escaped; the body is not, because a widget's whole job is to produce markup.
 *
 * Extending this is optional: {@see WidgetInterface} is the contract, and a widget that wants
 * to own its wrapper should implement that directly.
 */
abstract class Widget implements WidgetInterface
{
    /**
     * The widget's body.
     *
     * @param array<string, mixed> $args The area's arguments merged with the widget's settings
     * @return string Markup, or an empty string to render nothing
     */
    abstract protected function content(array $args): string;

    /**
     * The body, wrapped the way the area asked for.
     *
     * @param array<string, mixed> $args The area's arguments merged with the widget's settings
     * @return string Markup, or an empty string when there is no body
     */
    public function render(array $args = []): string
    {
        $body = $this->content($args);

        if (trim($body) === '') {
            return '';
        }

        return (string) ($args['before_widget'] ?? '')
            . $this->renderTitle($args)
            . $body
            . (string) ($args['after_widget'] ?? '');
    }

    /**
     * The title, wrapped, or nothing when there is no title.
     *
     * A heading with no text in it is worse than no heading: it takes space in the outline of
     * the page and tells a screen reader there is a section here with no name.
     *
     * @param array<string, mixed> $args The merged arguments
     * @return string The wrapped title, or an empty string
     */
    protected function renderTitle(array $args): string
    {
        $title = trim((string) ($args['title'] ?? ''));

        if ($title === '') {
            return '';
        }

        return (string) ($args['before_title'] ?? '')
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . (string) ($args['after_title'] ?? '');
    }
}
