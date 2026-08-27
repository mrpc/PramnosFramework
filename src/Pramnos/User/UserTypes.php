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
     * The framework's own types, used when an application declares none.
     *
     * Taken from a production application on this framework rather than invented: the
     * three administrative levels above 90 are the ones an operations team actually
     * distinguishes — an administrator who runs the application, a super administrator who
     * can change *its* configuration, and root. Everything between 2 and 89 is one kind of
     * account, because a framework has no basis for inventing more: the roles an
     * application needs are its own, and it declares them (see the class docblock).
     *
     * **`1` is the machine account**, not a rung on the ladder — see {@see EXACT}.
     *
     * @var array<int, string>
     */
    public const DEFAULTS = [
        99 => 'Root',
        98 => 'Super Administrator',
        90 => 'Administrator',
        1  => 'System User (Client Credentials Grant)',
        0  => 'Simple User',
    ];

    /**
     * Types that mean one specific kind of account rather than a floor.
     *
     * `1` is the built-in system account — the identity a Client Credentials grant
     * authenticates as. It is not "more senior than a simple user", so the threshold rule
     * must not apply to it: without this, `label(50)` would answer *System User*, because
     * 50 is above 1.
     *
     * So an exact match wins, and the threshold search skips these.
     *
     * @var list<int>
     */
    public const EXACT = [1];

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

        // An exact match first: a type in EXACT names one kind of account, and the
        // threshold rule would otherwise let every value above it inherit its name.
        if (array_key_exists($usertype, $labels)) {
            return $labels[$usertype];
        }

        foreach ($labels as $floor => $label) {
            if (in_array($floor, self::EXACT, true)) {
                continue;
            }
            if ($usertype >= $floor) {
                return $label;
            }
        }

        return (string) (end($labels) ?: 'Simple User');
    }

    /**
     * The tone a screen should show a type in — not a colour, a meaning.
     *
     * A view must not carry its own thresholds. Every one that did drifted: this view's
     * badge said *Admin* at 90 while the list said *Administrator* and the filter offered
     * something else again, and each had its own idea of which number was alarming.
     *
     * So the registry answers both halves of the question — what the type is called and how
     * loudly to say it — and a theme maps a tone to its own classes, which is the part a
     * theme legitimately owns. Four tones, because that is as many distinctions as a badge
     * can carry:
     *
     *   - `danger`  — root and above: nothing on this account is out of reach
     *   - `warning` — administrators: can change other people's accounts
     *   - `neutral` — the machine account, and anything an application marks as ordinary
     *     but not a person
     *   - `primary` — everybody else
     *
     * An application declaring its own types may declare tones beside them:
     *
     * ```php
     * 'usertypes'      => [99 => 'Root', 90 => 'Administrator', 50 => 'Management User', 0 => 'Simple User'],
     * 'usertype_tones' => [50 => 'warning'],   // this application treats 50 as privileged
     * ```
     *
     * @var array<int, string>
     */
    public const DEFAULT_TONES = [
        99 => 'danger',
        98 => 'danger',
        90 => 'warning',
        1  => 'neutral',
        0  => 'primary',
    ];

    /** The tones a theme has to be able to render. */
    public const TONES = ['danger', 'warning', 'neutral', 'primary'];

    /**
     * The tone for one usertype, resolved the way {@see label()} resolves a name.
     *
     * Exact match first — so the machine account is neutral rather than inheriting the
     * tone of everything above it — then the nearest floor.
     */
    public static function tone(int $usertype): string
    {
        $tones = self::tones();

        if (array_key_exists($usertype, $tones)) {
            return $tones[$usertype];
        }

        foreach ($tones as $floor => $tone) {
            if (in_array($floor, self::EXACT, true)) {
                continue;
            }
            if ($usertype >= $floor) {
                return $tone;
            }
        }

        return 'primary';
    }

    /**
     * The tone map: the application's, over the framework's, highest floor first.
     *
     * A tone declared for a type the application did not declare is kept — an application
     * may want to colour a band it inherited — and an unknown tone name is dropped rather
     * than passed to a theme that has no class for it.
     *
     * @return array<int, string>
     */
    public static function tones(): array
    {
        $declared = self::configuredKey('usertype_tones');

        $tones = self::DEFAULT_TONES;
        foreach ($declared as $floor => $tone) {
            if (in_array($tone, self::TONES, true)) {
                $tones[$floor] = $tone;
            }
        }

        krsort($tones, SORT_NUMERIC);

        return $tones;
    }

    /**
     * What each type may do by default — the framework's own answer, in one place.
     *
     * Until this existed, "what can an Administrator do" was answered by reading twelve
     * controllers: nine declared `requiredUserType = 80` and three declared `90`, the
     * administration area declared its own floor in `app.php`, and nothing anywhere said
     * what those numbers were *for*. An operator deciding which type to give somebody had
     * no document to read and no screen to look at.
     *
     * Each capability is a name the framework's own screens check through
     * {@see can()}. `*` means every capability, including ones added later — which is what
     * root means and the only honest way to write it.
     *
     * These are **defaults**, and they are floors: a capability listed for 90 belongs to 98
     * and 99 as well, because the resolution below walks down from the value it is given.
     * An application replaces the whole map with `'usertype_capabilities'` in `app.php`, in
     * the same shape.
     *
     * They are not a permission system. A capability answers "may this *type* of account
     * reach this kind of screen"; a permission answers "may this *account* touch this
     * record" and lives in `authserver.permissions` — see the Authorization guide.
     *
     * @var array<int, list<string>>
     */
    public const DEFAULT_CAPABILITIES = [
        99 => ['*'],
        98 => [
            'admin.area', 'admin.users', 'admin.users.write', 'admin.settings',
            'admin.logs', 'admin.applications', 'admin.permissions', 'admin.organizations',
            'admin.queue', 'admin.messages', 'admin.tokens', 'devpanel',
        ],
        90 => [
            'admin.area', 'admin.users', 'admin.users.write', 'admin.logs',
            'admin.applications', 'admin.organizations', 'admin.queue', 'admin.messages',
            'admin.tokens',
        ],
        // The machine account: it authenticates to the API and reaches nothing a person
        // reaches. Listed explicitly because "no capabilities" and "not written down" look
        // the same on a screen.
        1  => ['api.client_credentials'],
        0  => ['account.self'],
    ];

    /**
     * Every capability this usertype has, resolved.
     *
     * Exact match first, then the nearest floor below — the same rule as {@see label()} —
     * and then **every floor below that**, because capabilities accumulate: an
     * administrator has what a simple user has. `*` is returned as itself; callers should
     * ask {@see can()} rather than searching this list for a name.
     *
     * @return list<string>
     */
    public static function capabilities(int $usertype): array
    {
        $map = self::capabilityMap();
        $own = [];

        foreach ($map as $floor => $capabilities) {
            if (in_array($floor, self::EXACT, true)) {
                // An exact type does not inherit: the machine account is not a very senior
                // simple user, and giving it `account.self` would be inventing a person.
                if ($usertype === $floor) {
                    return array_values(array_unique($capabilities));
                }
                continue;
            }

            if ($usertype >= $floor) {
                $own = array_merge($own, $capabilities);
            }
        }

        return array_values(array_unique($own));
    }

    /**
     * May this usertype do this?
     *
     * The question the framework's own screens ask. `*` answers yes to everything, which is
     * why a caller must come through here rather than reading {@see capabilities()}.
     */
    public static function can(int $usertype, string $capability): bool
    {
        $capabilities = self::capabilities($usertype);

        return in_array('*', $capabilities, true) || in_array($capability, $capabilities, true);
    }

    /**
     * The capability map: the application's if it declared one, else the framework's.
     *
     * Replaced rather than merged. A capability list is a security decision, and an
     * application that writes one has said what it means — quietly adding the framework's
     * defaults underneath would grant things it did not ask for.
     *
     * @return array<int, list<string>>
     */
    public static function capabilityMap(): array
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo;

        $declared = match (true) {
            is_array($info)  => $info['usertype_capabilities'] ?? null,
            is_object($info) => $info->usertype_capabilities ?? null,
            default          => null,
        };

        $map = self::DEFAULT_CAPABILITIES;
        if (is_array($declared) && $declared !== []) {
            $map = [];
            foreach ($declared as $floor => $capabilities) {
                if (!is_numeric($floor) || !is_array($capabilities)) {
                    continue;
                }
                $map[(int) $floor] = array_values(array_filter($capabilities, 'is_string'));
            }
            if ($map === []) {
                $map = self::DEFAULT_CAPABILITIES;
            }
        }

        krsort($map, SORT_NUMERIC);

        return $map;
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
        return self::configuredKey('usertypes');
    }

    /**
     * One `applicationInfo` key, read defensively and normalised to `int => string`.
     *
     * `applicationInfo` may be an array or an object — `loadApplicationInfo()` returns a
     * config file's value as it found it — so both are read. Anything that is not a usable
     * map is ignored rather than half-applied: a mistyped config should leave the defaults
     * standing, not produce a screen labelling everybody `0`.
     *
     * @return array<int, string>
     */
    private static function configuredKey(string $key): array
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo;

        $declared = match (true) {
            is_array($info)  => $info[$key] ?? null,
            is_object($info) => $info->$key ?? null,
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
