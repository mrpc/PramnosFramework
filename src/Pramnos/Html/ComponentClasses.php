<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The class names a project wants on the components, declared once.
 *
 * The components emit neutral `pf-*` hooks and each theme's stylesheet dresses them, which is the
 * arrangement that needs no PHP at all — see the components guide. This exists for the case that
 * arrangement does not cover: markup that has to carry a **specific** class because something
 * other than a stylesheet is looking for it. A jQuery plugin doing `$('.breadcrumb')` does not
 * read CSS; it reads the name.
 *
 * ```php
 * // app/app.php
 * 'component_classes' => [
 *     'breadcrumb'         => 'breadcrumb',
 *     'pagination'         => 'pagination',
 *     'pagination.current' => 'active',
 * ],
 * ```
 *
 * ### Why config and not a property, when a property already exists
 *
 * Because a property is set on an object, and the objects are not all yours. `Breadcrumb` is
 * constructed in **eight** places in a scaffolded project — `Document`, `Application`, and an
 * `admin_breadcrumb` and `account_breadcrumb` partial in each of three themes — and two of those
 * are inside this framework, where an application cannot reach them at all.
 *
 * So a per-object override covers six sites out of eight, and the two it misses are the ones that
 * render on every page. Read at construction from configuration, one declaration covers all of
 * them.
 *
 * The property stays, and still wins: this decides the **default**, and a caller who sets
 * `$listClass` afterwards is making a decision about one breadcrumb rather than about the project.
 *
 * ### It is not where a palette lives
 *
 * Changing a class name to get a different look is almost always the wrong move. Colours and radii
 * live in `app/themes/theme.css`, propagated by `theme:build`, and a `pf-*` hook is styled from
 * there. Renaming the hook only makes sense when the name itself is load-bearing.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ComponentClasses
{
    /**
     * Every key this reads, with the hook it replaces.
     *
     * Listed rather than open-ended, so a typo in `app.php` is findable: a key nobody reads is
     * indistinguishable from a key that did not work, and the second is what somebody will
     * assume. {@see unknownKeys()} reports the difference.
     *
     * @var array<string, string> key => default
     */
    public const KEYS = [
        'breadcrumb'         => 'pf-breadcrumb',
        'pagination'         => 'pf-pagination',
        'pagination.current' => 'current',
        'icon'               => 'pf-icon',
        'action'             => 'pf-action',
        'omnibox'            => 'pf-omnibox',
    ];

    /**
     * The class configured for a key, or the framework's own hook.
     *
     * An empty string is honoured: a project that wants no class at all says `''`, and that is a
     * decision rather than an absence.
     */
    public static function get(string $key): string
    {
        $configured = self::configured();

        if (array_key_exists($key, $configured) && is_string($configured[$key])) {
            return trim($configured[$key]);
        }

        return self::KEYS[$key] ?? '';
    }

    /**
     * Keys in `app.php` that this class does not read.
     *
     * For `pramnos-check` and for a startup warning: a misspelled key is silent otherwise, and
     * silence here looks exactly like a feature that does not work.
     *
     * @return list<string>
     */
    public static function unknownKeys(): array
    {
        return array_values(array_diff(
            array_keys(self::configured()),
            array_keys(self::KEYS)
        ));
    }

    /** @return array<string, mixed> */
    private static function configured(): array
    {
        try {
            $declared = \Pramnos\Application\Application::currentInstance()
                ?->applicationInfo['component_classes'] ?? null;
        } catch (\Throwable) {
            return [];
        }

        return is_array($declared) ? $declared : [];
    }
}
