<?php

declare(strict_types=1);

namespace Pramnos\Theme;

/**
 * Turns menu items into markup.
 *
 * `Theme::displayMenu()` has always accepted a rich set of arguments — `container`,
 * `items_wrap`, `before`, `after`, `link_before`, `link_after` — and then handed the actual
 * rendering to `pramnoscms_menu`, a legacy application class the framework does not ship. In
 * any project without it, `displayMenu()` **fatals**. The framework's own test had to `eval()`
 * a fake one to test the method at all.
 *
 * This is that missing renderer, and it is deliberately a pure function of its inputs: items
 * in, string out. No database, no settings, no application. It can be unit-tested, and a theme
 * can subclass it to change one method rather than reimplement a nav menu.
 *
 * ### The item shape
 *
 * An item is an array. Only `title` is required:
 *
 * ```php
 * ['title' => 'Home', 'url' => '/', 'active' => true, 'children' => [...]]
 * ```
 *
 * | Key | Meaning |
 * | --- | --- |
 * | `title` | The link text. Escaped. |
 * | `url` | Where it goes. Escaped. An item with no `url` renders as a `<span>`. |
 * | `active` | Marks the current item |
 * | `children` | Nested items, to any depth |
 * | `class` | Extra classes on the `<li>` |
 * | `target`, `rel` | Passed through to the anchor when set |
 *
 * Alternative spellings are accepted — `name` for `title`, `link`/`href` for `url`,
 * `submenu`/`items` for `children` — because menu rows come from application tables that
 * predate this class and renaming a column is not a reasonable price for rendering a list.
 *
 * ### Placeholders, kept for compatibility
 *
 * The legacy `$options` shape used `[URL]`, `[TITLE]` and `[ACTIVE]…[/ACTIVE]` /
 * `[HASSUB]…[/HASSUB]` markers, and the documented defaults of `displayMenu()` still contain
 * them. They are honoured: a template containing them is filled in, and the conditional pairs
 * are kept or stripped per item. A theme that passes no options gets sensible markup without
 * knowing any of this exists.
 *
 * @see Theme::displayMenu()
 */
class MenuWalker
{
    /**
     * Renders a menu.
     *
     * @param array<int, array<string, mixed>> $items   The top-level items
     * @param array<string, mixed>             $options The legacy option shape from `displayMenu()`
     * @return string Markup, or an empty string when there are no items
     */
    public function render(array $items, array $options = []): string
    {
        if ($items === []) {
            return '';
        }

        return (string) ($options['premenu'] ?? '')
            . $this->renderItems($items, $options, 0)
            . (string) ($options['postmenu'] ?? '');
    }

    /**
     * Renders one level of items.
     *
     * @param array<int, array<string, mixed>> $items   Items at this level
     * @param array<string, mixed>             $options The option shape
     * @param int                              $depth   0 for the top level
     * @return string Markup for the level
     */
    protected function renderItems(array $items, array $options, int $depth): string
    {
        $output = '';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $output .= $this->renderItem($item, $options, $depth);
        }

        return $output;
    }

    /**
     * Renders one item, and its children if it has any.
     *
     * @param array<string, mixed> $item    The item
     * @param array<string, mixed> $options The option shape
     * @param int                  $depth   0 for the top level
     * @return string Markup for the item
     */
    protected function renderItem(array $item, array $options, int $depth): string
    {
        $children = $this->childrenOf($item);
        $hasSub   = $children !== [];
        $isActive = !empty($item['active']);

        $before = $depth === 0
            ? (string) ($options['pretopmenu'] ?? '<li>')
            : (string) ($options['presubmenu'] ?? '<li>');
        $after  = $depth === 0
            ? (string) ($options['posttopmenu'] ?? '</li>')
            : (string) ($options['postsubmenu'] ?? '</li>');

        $before = $this->applyConditionals($before, $hasSub, $isActive);
        $before = $this->addItemClass($before, $item);

        $template = $depth === 0
            ? (string) ($options['topmenuoption'] ?? '')
            : (string) ($options['submenuoption'] ?? '');

        $body = $template !== ''
            ? $this->fillTemplate($template, $item)
            : $this->anchor($item);

        if ($hasSub) {
            $body .= (string) ($options['submenubodystart'] ?? '<ul>')
                . $this->renderItems($children, $options, $depth + 1)
                . (string) ($options['submenubodyend'] ?? '</ul>');
        }

        return $before . $body . $after;
    }

    /**
     * An item's children, under whichever key they arrived.
     *
     * @param array<string, mixed> $item The item
     * @return array<int, array<string, mixed>> The children, possibly empty
     */
    protected function childrenOf(array $item): array
    {
        foreach (['children', 'submenu', 'items'] as $key) {
            if (!empty($item[$key]) && is_array($item[$key])) {
                return array_values($item[$key]);
            }
        }

        return [];
    }

    /**
     * An item's link text, under whichever key it arrived.
     *
     * @param array<string, mixed> $item The item
     * @return string The title, unescaped
     */
    protected function titleOf(array $item): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            if (isset($item[$key]) && (string) $item[$key] !== '') {
                return (string) $item[$key];
            }
        }

        return '';
    }

    /**
     * An item's target, under whichever key it arrived.
     *
     * @param array<string, mixed> $item The item
     * @return string The URL, unescaped; empty when the item is not a link
     */
    protected function urlOf(array $item): string
    {
        foreach (['url', 'link', 'href'] as $key) {
            if (isset($item[$key]) && (string) $item[$key] !== '') {
                return (string) $item[$key];
            }
        }

        return '';
    }

    /**
     * The anchor for an item, when no template was supplied.
     *
     * An item with no URL becomes a `<span>` rather than an anchor to nowhere: `<a>` with no
     * `href` is focusable-but-inert in some browsers and a broken promise in all of them.
     *
     * @param array<string, mixed> $item The item
     * @return string The anchor or span
     */
    protected function anchor(array $item): string
    {
        $title = htmlspecialchars($this->titleOf($item), ENT_QUOTES, 'UTF-8');
        $url   = $this->urlOf($item);

        if ($url === '') {
            return '<span>' . $title . '</span>';
        }

        $attributes = ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';

        foreach (['target', 'rel'] as $attribute) {
            if (!empty($item[$attribute])) {
                $attributes .= ' ' . $attribute . '="'
                    . htmlspecialchars((string) $item[$attribute], ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return '<a' . $attributes . '>' . $title . '</a>';
    }

    /**
     * Fills a legacy `[URL]` / `[TITLE]` template.
     *
     * @param string               $template The template
     * @param array<string, mixed> $item     The item
     * @return string The filled template
     */
    protected function fillTemplate(string $template, array $item): string
    {
        return str_replace(
            ['[URL]', '[TITLE]'],
            [
                htmlspecialchars($this->urlOf($item), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->titleOf($item), ENT_QUOTES, 'UTF-8'),
            ],
            $template
        );
    }

    /**
     * Keeps or strips the `[ACTIVE]` and `[HASSUB]` sections of a template.
     *
     * @param string $template The template fragment
     * @param bool   $hasSub   Whether this item has children
     * @param bool   $isActive Whether this item is the current one
     * @return string The fragment with the markers resolved
     */
    protected function applyConditionals(string $template, bool $hasSub, bool $isActive): string
    {
        foreach (['HASSUB' => $hasSub, 'ACTIVE' => $isActive] as $marker => $keep) {
            $pattern = '/\[' . $marker . '\](.*?)\[\/' . $marker . '\]/s';
            $template = (string) preg_replace($pattern, $keep ? '$1' : '', $template);
        }

        return $template;
    }

    /**
     * Adds an item's own classes to its wrapper, when the wrapper is an element.
     *
     * Only touches a fragment that opens a tag and already has a `class` attribute, which is
     * what the documented defaults produce. Anything else is left exactly as the theme wrote
     * it — guessing where a class belongs in arbitrary markup is how a renderer starts
     * corrupting templates.
     *
     * @param string               $before The opening fragment
     * @param array<string, mixed> $item   The item
     * @return string The fragment, possibly with classes added
     */
    protected function addItemClass(string $before, array $item): string
    {
        $extra = trim((string) ($item['class'] ?? ''));

        if ($extra === '' || !str_contains($before, 'class="')) {
            return $before;
        }

        return (string) preg_replace(
            '/class="([^"]*)"/',
            'class="$1 ' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') . '"',
            $before,
            1
        );
    }
}
