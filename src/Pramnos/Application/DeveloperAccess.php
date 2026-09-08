<?php

namespace Pramnos\Application;

/**
 * Who may open a developer tool on this installation.
 *
 * ## Why this exists
 *
 * Three developer-facing routes had three separate gates, and only one of them could name a
 * person:
 *
 * | Route | Gate | Named a person? |
 * |---|---|---|
 * | `/devpanel/*` | `production_min_usertype`, `usertypes`, **`userids`** | yes |
 * | `/adminer` | `adminer_min_usertype`, else `ROOT_USERTYPE` | no |
 * | `/debugbar` | `debug.grant_min_usertype`, else 90 | no |
 *
 * So `'devpanel' => ['userids' => [2]]` closed the panel to one account exactly as the guide
 * describes, and there was no value of the other two keys that did the same. A usertype
 * floor was the only vocabulary they had.
 *
 * **A usertype is a role, and on a real installation it is a role granted to other
 * organisations.** One production database had 9 accounts at usertype 99 across six
 * different organisations — each able to open a full database client against a live 418 GB
 * database — and 64 accounts at 90 or above, each able to issue itself a toolbar grant
 * carrying the query log of a live request. "May administer their own organisation's data"
 * and "may read the whole database from a browser" are different questions, and a floor
 * answers them with one number.
 *
 * That installation had to **turn two of the tools off** — floor 100, out of reach — because
 * off was the only state narrower than "nine organisations". The one account that should
 * have had them got them through shell access instead, which is a property of that
 * installation and not a design.
 *
 * ## Why one resolver rather than a fourth copy
 *
 * The asymmetry was known and half-closed: `allowedOnAnyDeployment()` cites Adminer as the
 * arrangement it is copying — *"the more dangerous of the two tools"* — and then the panel
 * gained the narrower gate and Adminer did not. Three gates each reading their own subset of
 * the same three ideas is how that drifted, and a fourth tool would drift the same way.
 *
 * ## The keys
 *
 * Every existing key keeps its name and its meaning. Each tool gains a `usertypes` and a
 * `userids` list, and **an unset list falls back to the panel's** — so an installation that
 * wants one person to have everything writes one line, and one that wants Adminer narrower
 * than the panel can still say so.
 *
 * ```php
 * // app/app.php
 * 'devpanel' => [
 *     'production_min_usertype' => 100,   // out of reach: nobody by role
 *     'userids'                 => [2],   // this person, everywhere
 *     'adminer_userids'         => [2],   // …or only here, if the panel is wider
 * ],
 * 'debug' => [
 *     'grant_userids'           => [2],
 * ],
 * ```
 *
 * An installation that sets none of the new keys sees no change: the floors are what they
 * always were, and an absent list widens nothing.
 */
final class DeveloperAccess
{
    /** The DevPanel and everything under `/devpanel`. */
    public const DEVPANEL = 'devpanel';

    /** The bundled database client at `/adminer`. */
    public const ADMINER = 'adminer';

    /** The route that issues a debug-toolbar grant, `/debugbar`. */
    public const DEBUGBAR = 'debugbar';

    /**
     * Where each tool's three keys live.
     *
     * `section` is the `app.php` block; the three names are read from it. The floor keys are
     * the ones installations already set, spelled exactly as they always were — renaming one
     * would silently widen a gate somebody had narrowed.
     *
     * @var array<string, array{section: string, floor: string, types: string, ids: string}>
     */
    private const TOOLS = [
        self::DEVPANEL => [
            'section' => 'devpanel',
            'floor'   => 'production_min_usertype',
            'types'   => 'usertypes',
            'ids'     => 'userids',
        ],
        self::ADMINER => [
            'section' => 'devpanel',
            'floor'   => 'adminer_min_usertype',
            'types'   => 'adminer_usertypes',
            'ids'     => 'adminer_userids',
        ],
        self::DEBUGBAR => [
            'section' => 'debug',
            'floor'   => 'grant_min_usertype',
            'types'   => 'grant_usertypes',
            'ids'     => 'grant_userids',
        ],
    ];

    /**
     * May the signed-in user open this tool?
     *
     * Answers the whole question — floor, usertype list, user-id list — so a caller has one
     * call rather than three reads and a boolean expression to get wrong. The floor default
     * stays with the caller, because it is a property of the tool and the callers disagree
     * about it on purpose: 99 for a database client, 90 for one request's query log.
     *
     * @param string      $tool         One of the constants above
     * @param int         $defaultFloor The floor when the installation configures none
     * @param object|null $user         The user to ask about; the current one when null
     */
    public static function permits(string $tool, int $defaultFloor, ?object $user = null): bool
    {
        /*
         * An unregistered tool name is refused outright, and it took a test to see why.
         *
         * The first version read every key as absent and fell through — to the caller's
         * floor, and then to the panel's `userids`, because the fallback only excludes the
         * panel itself. So a typo in a tool name inherited the panel's permissions: a
         * mistake that opens a gate is not a mistake anybody notices.
         *
         * Fail closed, and say so. There are three callers and they pass constants, so this
         * can only be a typo or a fourth tool somebody forgot to register — and both want
         * finding, not defaulting.
         */
        if (!isset(self::TOOLS[$tool])) {
            \Pramnos\Logs\Logger::log(
                'DeveloperAccess was asked about an unregistered tool "' . $tool
                . '" and refused. Registered: ' . implode(', ', array_keys(self::TOOLS)) . '.',
                'auth'
            );

            return false;
        }

        if ($user === null) {
            $current = \Pramnos\User\User::getCurrentUser();
            // `getCurrentUser()` answers **false** for an anonymous visitor, not null, so
            // `is_object()` rather than a null comparison — reading `->usertype` off `false`
            // raises before any guard runs.
            $user = is_object($current) ? $current : null;
        }

        if ($user === null || !\Pramnos\Http\Session::staticIsLogged()) {
            return false;
        }

        $usertype = (int) ($user->usertype ?? 0);
        $userid   = (int) ($user->userid ?? 0);

        if ($userid < 1) {
            return false;
        }

        $floor = self::floor($tool, $defaultFloor);
        if ($floor > 0 && $usertype >= $floor) {
            return true;
        }

        if (in_array($usertype, self::usertypes($tool), true)) {
            return true;
        }

        return in_array($userid, self::userids($tool), true);
    }

    /**
     * The configured floor for this tool, or the caller's default.
     *
     * A configured `0` is treated as "not set" rather than "nobody", because that is what
     * both existing gates already did and an installation with a stray zero would otherwise
     * lose a tool it thought it had left alone.
     */
    public static function floor(string $tool, int $defaultFloor): int
    {
        $configured = (int) self::value($tool, 'floor', 0);

        return $configured > 0 ? $configured : $defaultFloor;
    }

    /**
     * Usertypes named for this tool, falling back to the panel's.
     *
     * @return list<int>
     */
    public static function usertypes(string $tool): array
    {
        return self::intList(self::valueWithPanelFallback($tool, 'types'));
    }

    /**
     * User ids named for this tool, falling back to the panel's.
     *
     * @return list<int>
     */
    public static function userids(string $tool): array
    {
        return self::intList(self::valueWithPanelFallback($tool, 'ids'));
    }

    /**
     * Record that a developer tool was opened where the environment would not have allowed
     * it.
     *
     * The panel already did this and it is the only visible trace on a live server that a
     * tool was opened at all. Adminer opening for a named account deserves it more, not
     * less — so it is here rather than in one of the three gates, and the tool is named in
     * the line.
     *
     * Silent on a development machine, where it would run on every request to every tab and
     * be a log nobody reads.
     *
     * @param string $tool One of the constants above
     */
    public static function recordOpening(string $tool): void
    {
        if (\Pramnos\Application\Application::isDeveloperEnvironment()) {
            return;
        }

        $user     = \Pramnos\User\User::getCurrentUser();
        $userid   = is_object($user) ? (int) ($user->userid ?? 0) : 0;
        $usertype = is_object($user) ? (int) ($user->usertype ?? 0) : 0;

        \Pramnos\Logs\Logger::log(
            ucfirst($tool) . ' opened outside a development environment by user ' . $userid
            . ' (usertype ' . $usertype . ') from '
            . \Pramnos\Http\Request::clientIp('an unknown address'),
            'auth'
        );
    }

    /**
     * A tool's own value, or the panel's when the tool names none.
     *
     * The fallback is what makes one line enough for the common case. It is deliberately
     * one-way: the panel never inherits from a tool, so narrowing Adminer cannot widen the
     * panel by accident.
     *
     * **The explicit `DEVPANEL` clause is belt and braces, and it is worth knowing that it
     * is.** Removing it reddens nothing, because the panel falling back to *itself* reads
     * the same absent key twice and lands on the same empty list. It stays because the
     * one-way property is the security-relevant half of this method, and leaving it to be
     * an emergent consequence of which key the panel happens to own is how the next person
     * adds a second section and inverts it.
     *
     * @return mixed
     */
    private static function valueWithPanelFallback(string $tool, string $kind)
    {
        $own = self::value($tool, $kind, null);
        if ($own !== null && $own !== '' && $own !== array()) {
            return $own;
        }

        if ($tool === self::DEVPANEL) {
            return array();
        }

        return self::value(self::DEVPANEL, $kind, array());
    }

    /**
     * One key out of one tool's `app.php` block.
     *
     * `currentInstance()` and not `getInstance()`: the second is a factory that reads
     * `app.php`, defines constants and boots a whole application — a database, a language
     * and a session — to answer a question about a number. `?->` because a unit test has no
     * instance at all, and the default is then the safe answer.
     *
     * @param  mixed $default
     * @return mixed
     */
    private static function value(string $tool, string $kind, $default)
    {
        if (!isset(self::TOOLS[$tool])) {
            return $default;
        }

        $spec = self::TOOLS[$tool];

        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo[$spec['section']][$spec[$kind]] ?? null;

        return ($configured === null || $configured === '') ? $default : $configured;
    }

    /**
     * @param  mixed $value
     * @return list<int>
     */
    private static function intList($value): array
    {
        return array_values(array_map('intval', (array) $value));
    }
}
