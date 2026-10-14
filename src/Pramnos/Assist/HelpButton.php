<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The site's help button, drawn by the application instead of the framework.
 *
 * ```php
 * // app/app.php
 * 'help' => ['site' => ['button' => App\Help\Button::class]],
 * ```
 *
 * Asked for the opener only, never for the panel: `$attributes` are what `pf-assist.js` finds the
 * button by and must stay on the element that is clicked — the button opens the panel through
 * them, whatever its class. Without the script the button is the link `$href` it always was.
 */
interface HelpButton
{
    /**
     * The button's markup.
     *
     * @param string $href       The page's help address, escaped; `#` where there is no help screen
     * @param string $label      "Help", translated and escaped
     * @param string $attributes `data-pf-assist-open aria-haspopup="dialog"`, to put on the element as they are
     * @param array{slug: string, title: string} $page The help page found for the screen
     */
    public function render(string $href, string $label, string $attributes, array $page): string;
}
