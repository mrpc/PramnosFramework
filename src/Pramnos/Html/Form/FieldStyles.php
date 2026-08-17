<?php

declare(strict_types=1);

namespace Pramnos\Html\Form;

/**
 * The CSS classes a form field wears, per front-end theme.
 *
 * ## Why this is a class of its own
 *
 * Two things in this framework put attributes on a form field, and they cannot share a
 * renderer: {@see \Pramnos\Console\Commands\MakeCommandBase::buildWizardFormFields()}
 * generates **template source** — strings containing `<?php echo …`, written into a file for
 * a project to own — while {@see SettingsForm} renders **markup at runtime**. One emits code,
 * the other emits HTML; a shared render method would have to be both.
 *
 * What they can share is the part that actually drifts: the class names. A settings form that
 * says `form-control` while the generated CRUD form beside it says something else is the kind
 * of difference nobody notices until a designer does, and there is no reason for two lists.
 *
 * So the presets live here, and both read them.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class FieldStyles
{
    /**
     * Every preset, keyed by theme.
     *
     * `plain` uses inline styles rather than class names on purpose: it is the preset for a
     * project with no CSS framework, where a class name would refer to nothing.
     *
     * @var array<string, array<string, string>>
     */
    private const PRESETS = [
        'bootstrap' => [
            'group'  => ' class="mb-3"',
            'label'  => ' class="form-label"',
            'input'  => ' class="form-control"',
            'select' => ' class="form-select"',
            'area'   => ' class="form-control"',
            'check'  => ' class="form-check-input"',
            'help'   => ' class="form-text"',
        ],
        'tailwind' => [
            'group'  => ' class="mb-4"',
            'label'  => ' class="block text-sm font-medium text-gray-700 mb-1"',
            'input'  => ' class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm"',
            'select' => ' class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm"',
            'area'   => ' class="w-full px-3 py-2 border border-gray-300 rounded-sm text-sm"',
            'check'  => ' class="h-4 w-4 rounded-sm border-gray-300"',
            'help'   => ' class="mt-1 text-xs text-gray-500"',
        ],
        'plain' => [
            'group'  => ' style="margin-bottom:12px"',
            'label'  => ' style="display:block;font-weight:600;margin-bottom:4px"',
            'input'  => ' style="width:100%;padding:8px;border:1px solid #ccc;'
                . 'border-radius:4px;box-sizing:border-box"',
            'select' => ' style="width:100%;padding:8px;border:1px solid #ccc;'
                . 'border-radius:4px"',
            'area'   => ' style="width:100%;padding:8px;border:1px solid #ccc;'
                . 'border-radius:4px;box-sizing:border-box"',
            'check'  => '',
            'help'   => ' style="display:block;font-size:12px;color:#666;margin-top:4px"',
        ],
    ];

    /**
     * The preset for a theme, falling back to `plain`.
     *
     * Unknown themes fall back rather than throwing: the theme name reaches this from an
     * application's configuration, and a settings page that dies because somebody typed
     * `bootstrap5` is a worse outcome than one that renders unstyled.
     *
     * @param  string $theme `plain`, `bootstrap` or `tailwind`
     * @return array<string, string>
     */
    public static function for(string $theme): array
    {
        return self::PRESETS[strtolower($theme)] ?? self::PRESETS['plain'];
    }

    /**
     * The theme names that have a preset.
     *
     * @return list<string>
     */
    public static function themes(): array
    {
        return array_keys(self::PRESETS);
    }
}
