<?php

declare(strict_types=1);

namespace Pramnos\Theme;

/**
 * The layout of the framework's own screens in the `bootstrap` theme, whatever Bootstrap the host
 * page loads.
 *
 * Those screens are drawn inside an application's own administration, next to its menu and under
 * its stylesheet — Bootstrap 5, or an older administration's Bootstrap 3, where `d-flex`, `gap-*`,
 * `card-body` and `form-select` do not exist and `.container` is 1170px wide whatever room it has.
 * So their page, cards, rows, fields and grids take their layout from these `pf-l-*` rules, printed
 * by each screen itself ({@see style()}), and use only the Bootstrap classes both versions have for
 * buttons, inputs and text.
 *
 * | class | |
 * |---|---|
 * | `pf-l-page` | the screen: a column, as wide as the room it is given; a wide table scrolls inside it |
 * | `pf-l-pad` | room above and below a `container-fluid` page |
 * | `pf-l-card` | a bordered box with room inside |
 * | `pf-l-row` | things side by side, wrapping |
 * | `pf-l-field` | a label above its input |
 * | `pf-l-select` | a select as wide as its choices, not a full line, nor a 20px strip |
 * | `pf-l-mt` | room above a heading |
 */
final class NeutralLayout
{
    /** The rules. */
    public const CSS = '.pf-l-page{display:flex;flex-direction:column;gap:12px;padding-top:16px;padding-bottom:16px;width:auto;max-width:100%;box-sizing:border-box;min-width:0}'
        . '.pf-l-pad{padding-top:16px;padding-bottom:16px}'
        . '.pf-l-card{display:block;border:1px solid rgba(127,127,127,.3);border-radius:6px;padding:12px 14px;box-sizing:border-box;max-width:100%;min-width:0;overflow-wrap:anywhere}'
        . '.pf-l-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}'
        . '.pf-l-field{display:flex;flex-direction:column;gap:4px}'
        . '.pf-l-page input,.pf-l-page textarea,.pf-l-page select,.pf-l-page pre{max-width:100%}'
        // A table wider than the room scrolls inside itself, not the page.
        . '.pf-l-page table{display:block;max-width:100%;overflow-x:auto}'
        . 'select.pf-l-select{display:inline-block;width:auto;min-width:8rem;height:auto}'
        . '.pf-l-mt{margin-top:16px}';

    /** The rules as a `<style>` element, for a screen to print once at its top. */
    public static function style(): string
    {
        return '<style data-pf-layout>' . self::CSS . '</style>';
    }
}
