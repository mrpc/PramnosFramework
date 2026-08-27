<?php

declare(strict_types=1);

namespace Pramnos\User;

/**
 * What a `usertype` means, and how an application changes it.
 *
 * `users.usertype` is a plain integer, and the framework treats it as a **threshold**
 * rather than an enum: `>= 90` is an administrator, and the administration area's floor
 * is whatever `admin.min_usertype` says. Nothing about that was ever written down in one
 * place — the number 90 appeared in a console command, 80 in `app.php`, and each bundled
 * view carried its own copy of the labels. So "what is 85?" had three answers depending
 * on which file you asked.
 *
 * This is the one place. The bands below are the default; an application overrides them
 * in `app/app.php`:
 *
 * ```php
 * 'usertypes' => [
 *     100 => 'Owner',
 *     90  => 'Administrator',
 *     50  => 'Staff',
 *     10  => 'Customer',
 *     0   => 'Guest',
 * ],
 * ```
 *
 * Keyed by the band's **floor**, and read highest-first, so a value between two bands
 * belongs to the lower one. That is what makes it a threshold: an application can grant
 * 85 to somebody and every screen still calls them by the band they are in, rather than
 * showing a number with no name.
 *
 * Why a map and not a class per role: the column is an integer in a schema shared with
 * every application on this framework, and a comparison (`>= 80`) is what the framework's
 * own guards are written in. A role system would be a different feature — see the
 * Authorization guide for permissions, which is what to reach for when "may they do X"
 * matters more than "how senior are they".
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class UserTypes
{
    /**
     * The framework's own bands, used when an application declares none.
     *
     * `90` matches `Console\Commands\UserCreate::ADMIN_USERTYPE`, and `80` is what the
     * scaffolded `admin.min_usertype` uses — the two numbers the framework already had
     * opinions about.
     *
     * @var array<int, string>
     */
    public const DEFAULTS = [
        90 => 'Admin',
        80 => 'Manager',
        50 => 'Editor',
        10 => 'Member',
        0  => 'Guest',
    ];

    /**
     * The bands this application uses, floor => label, highest floor first.
     *
     * @return array<int, string>
     */
    public static function labels(): array
    {
        $configured = self::configured();
        if ($configured === []) {
            return self::DEFAULTS;
        }

        // Sorted here rather than trusted from the config: the lookup below reads the
        // first band at or below a value, so an application listing its bands lowest
        // first would otherwise get "Guest" for an administrator.
        krsort($configured, SORT_NUMERIC);

        return $configured;
    }

    /**
     * The band a usertype falls in.
     *
     * `label(85)` is `Manager` with the defaults: the number is a threshold, so a value
     * between two bands belongs to the lower one. A value below every band returns the
     * lowest band's label, because a user always has *some* standing.
     */
    public static function label(int $usertype): string
    {
        $labels = self::labels();

        foreach ($labels as $floor => $label) {
            if ($usertype >= $floor) {
                return $label;
            }
        }

        return (string) (end($labels) ?: 'Guest');
    }

    /**
     * The bands as `value => label`, for a `<select>`.
     *
     * The label carries the number — `Admin (90)` — because the filter it feeds matches
     * the column **exactly**, and an operator choosing "Admin" is entitled to know which
     * value that sends.
     *
     * @return array<string, string> value => label, the shape Html\Select::addOptions() takes
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::labels() as $floor => $label) {
            $options[(string) $floor] = $label . ' (' . $floor . ')';
        }

        return $options;
    }

    /**
     * The application's declared bands, or `[]`.
     *
     * `applicationInfo` may be an array or an object — `loadApplicationInfo()` returns a
     * config file's value as it found it — so both are read. Anything that is not a
     * usable map is ignored rather than half-applied: a mistyped config should leave the
     * defaults standing, not produce a screen labelling everybody `0`.
     *
     * @return array<int, string>
     */
    private static function configured(): array
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo;

        $declared = match (true) {
            is_array($info)  => $info['usertypes'] ?? null,
            is_object($info) => $info->usertypes ?? null,
            default          => null,
        };

        if (!is_array($declared) || $declared === []) {
            return [];
        }

        $bands = [];
        foreach ($declared as $floor => $label) {
            if (!is_numeric($floor) || !is_string($label) || $label === '') {
                continue;
            }
            $bands[(int) $floor] = $label;
        }

        return $bands;
    }
}
