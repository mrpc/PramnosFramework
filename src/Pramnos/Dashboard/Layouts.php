<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

use Pramnos\Database\Database;

/**
 * Each person's dashboard: which widgets, in what order, how wide — kept per person and per area.
 *
 * ```php
 * $layouts = new Layouts();
 * $layouts->for($user);                         // [['id' => 'users.active', 'size' => 'm', 'settings' => []], …]
 * $layouts->save($user, [['id' => 'health', 'size' => 'full'], ['id' => 'sales', 'size' => 's', 'settings' => ['period' => '30']]]);
 * $layouts->save($user, null);                  // back to the default
 * ```
 *
 * A layout is cleaned whenever it is read or written: a widget nobody registers any more, one this
 * person may not have, a second copy of one, an unknown width, a setting the widget does not
 * declare — each is dropped or put right, not an error. A width saved as the older `half` reads as
 * `m`. So a saved layout can never show what its owner's permissions do not allow.
 *
 * Kept as a user setting, `dashboard.{area}` in `usersettings` (`DB_USERSETTINGSTABLE`), the layout
 * as its JSON value: an area — the administration, a partner portal — has a dashboard of its own.
 * A report's board (`report:<id>`, {@see \Pramnos\Metrics\ReportBoard}) is the exception: one
 * layout, kept in the report, changed by its owner alone.
 */
final class Layouts
{
    /** The user setting a layout is kept under, before its area: `dashboard.admin`. */
    public const SETTING = 'dashboard.';

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * This person's layout: the one they saved, cleaned, or the default.
     *
     * @return list<array{id: string, size: string, settings: array<string, mixed>}>
     */
    public function for(object $user, string $area = 'admin'): array
    {
        // A report's board: the report's own cards, the same for everybody who may open it.
        if (\Pramnos\Metrics\ReportBoard::isReportBoard($area)) {
            $entry = (new \Pramnos\Metrics\Reports($this->db))->find(\Pramnos\Metrics\ReportBoard::reportId($area), $user);

            return $entry === null ? [] : self::clean(\Pramnos\Metrics\ReportBoard::layout($entry['report']), $user, $area);
        }
        $saved = $this->savedFor((int) ($user->userid ?? 0), $area);
        if ($saved !== null) {
            return self::clean($saved, $user, $area);
        }
        // A person's own board: where its owner left it, for somebody it was shared with.
        if (UserBoards::isUserBoard($area)) {
            return self::clean((new UserBoards($this->db))->startingLayout($user, $area), $user, $area);
        }
        // Nothing saved: the preset an administrator made the default for this person's role, then
        // the board's own default.
        $preset = (new Presets($this->db))->defaultLayoutFor($user, $area);

        return $preset !== null ? self::clean($preset, $user, $area) : self::defaults($user, $area);
    }

    /**
     * A person's saved layout of an area, as kept — not cleaned — or null when they saved none,
     * or the table is not there yet.
     *
     * @return list<mixed>|null
     */
    public function savedFor(int $userId, string $area): ?array
    {
        try {
            $rows = $this->table()->select(['value'])->where('userid', $userId)->where('setting', self::SETTING . $area)
                ->limit(1)->getAllOrFail();
        } catch (\Throwable $e) {
            // No table yet — `migrate` has not run. The default still draws; saving will say what is wrong.
            \Pramnos\Logs\Logger::log('Dashboard layouts unavailable, the default shown: ' . $e->getMessage(), 'dashboard');
            $rows = [];
        }
        $saved = $rows === [] ? null : json_decode((string) ($rows[0]['value'] ?? ''), true);

        return is_array($saved) ? array_values($saved) : null;
    }

    /**
     * Keep a layout, cleaned; null forgets it, so the default applies again.
     *
     * @param mixed $layout As a page sends it: `[{id, size, settings}, …]`
     * @return list<array{id: string, size: string, settings: array<string, mixed>}> What applies now
     */
    public function save(object $user, mixed $layout, string $area = 'admin'): array
    {
        // A report's board: kept in the report, by its owner alone; there is no default to go back to.
        if (\Pramnos\Metrics\ReportBoard::isReportBoard($area)) {
            if ($layout !== null) {
                (new \Pramnos\Metrics\Reports($this->db))->saveLayout($user, (int) \Pramnos\Metrics\ReportBoard::reportId($area), self::clean($layout, $user, $area));
            }

            return $this->for($user, $area);
        }
        $userId = (int) ($user->userid ?? 0);
        $this->table()->where('userid', $userId)->where('setting', self::SETTING . $area)->delete();
        if ($layout === null) {
            return $this->for($user, $area);
        }

        $clean = self::clean($layout, $user, $area);
        $this->table()->insert([
            'userid'     => $userId,
            'setting'    => self::SETTING . $area,
            'value'      => (string) json_encode($clean),
            'updated_at' => time(),
            'updated_by' => $userId,
        ]);

        return $clean;
    }

    /**
     * The layout of somebody who has saved none: every widget they may have that is on by default,
     * at its own width — in the board's `default` order where it lists one, the registry's
     * otherwise.
     *
     * @return list<array{id: string, size: string, settings: array<string, mixed>}>
     */
    public static function defaults(?object $user, ?string $board = null): array
    {
        // A board that lists its defaults: those, in that order — groups of tabs among them.
        $entries = WidgetRegistry::defaultEntries($board);
        if ($entries !== null) {
            return self::clean(array_map(static fn ($entry) => is_array($entry) ? $entry : ['id' => (string) $entry], $entries), $user, $board);
        }
        $layout = [];
        foreach (WidgetRegistry::available($user, $board) as $id => $widget) {
            if (WidgetRegistry::byDefault($widget, $board)) {
                $layout[] = ['id' => (string) $id, 'size' => self::size($widget->size()), 'settings' => Widget::cleanSettings($widget, [])];
            }
        }

        return $layout;
    }

    /**
     * A layout as it may be kept: widgets this person may have on the board, each once — or, for a
     * {@see Widget::repeatable()} one, each card once under its `<id>~<key>` — at a known width, and
     * groups of them as tabs.
     *
     * A group is `{id: 'tabs:…', size, tabs: [{id, settings}, …], active, title}` (tabs may be
     * given as ids). Its tabs are cleaned like cards: one this person may not have is dropped, and
     * a card appears once in the whole layout, in a group or not. A group left with one card is
     * that card, at the group's width; one left with none goes. `active` is one of its tabs — the
     * first, unless it names another.
     *
     * @return list<array<string, mixed>>
     */
    public static function clean(mixed $layout, ?object $user, ?string $board = null): array
    {
        $available = WidgetRegistry::available($user, $board);
        $seen      = [];
        $clean     = [];
        $card      = static function (mixed $item, ?string $size = null) use ($available, &$seen): ?array {
            $id     = is_array($item) ? (string) ($item['id'] ?? '') : (is_string($item) ? $item : '');
            $widget = WidgetRegistry::forCard($available, $id);
            if ($widget === null || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;

            return [
                'id'       => $id,
                'size'     => Widget::normalSize($size ?? (is_array($item) ? ($item['size'] ?? null) : null)) ?? self::size($widget->size()),
                'settings' => Widget::cleanSettings($widget, is_array($item) ? ($item['settings'] ?? []) : []),
            ];
        };
        foreach (is_array($layout) ? $layout : [] as $item) {
            if (!is_array($item) || !isset($item['tabs'])) {
                if (($one = $card($item)) !== null) {
                    $clean[] = $one;
                }
                continue;
            }
            $size = Widget::normalSize($item['size'] ?? null);
            $tabs = [];
            foreach (is_array($item['tabs']) ? $item['tabs'] : [] as $tab) {
                if (($one = $card($tab, $size ?? Widget::MEDIUM)) !== null) {
                    $tabs[] = ['id' => $one['id'], 'settings' => $one['settings']];
                }
            }
            if ($tabs === []) {
                continue;
            }
            if (count($tabs) === 1) {
                $clean[] = ['id' => $tabs[0]['id'], 'size' => $size ?? self::size(WidgetRegistry::forCard($available, $tabs[0]['id'])->size()), 'settings' => $tabs[0]['settings']];
                continue;
            }
            $ids      = array_column($tabs, 'id');
            $clean[]  = [
                'id'     => self::groupId($item['id'] ?? null, $ids),
                'size'   => $size ?? Widget::FULL,
                'tabs'   => $tabs,
                'active' => in_array($item['active'] ?? null, $ids, true) ? (string) $item['active'] : $ids[0],
                'title'  => mb_substr(trim((string) ($item['title'] ?? '')), 0, 120),
            ];
        }

        return $clean;
    }

    /** Whether a layout item is a group of tabs. */
    public static function isGroup(array $item): bool
    {
        return isset($item['tabs']) && is_array($item['tabs']);
    }

    /**
     * Every card of a layout, its groups' tabs among them, as `{id, size, settings}` — a tab at
     * its group's width.
     *
     * @return list<array{id: string, size: string, settings: array<string, mixed>}>
     */
    public static function cards(array $layout): array
    {
        $cards = [];
        foreach ($layout as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!self::isGroup($item)) {
                $cards[] = ['id' => (string) ($item['id'] ?? ''), 'size' => (string) ($item['size'] ?? Widget::MEDIUM), 'settings' => (array) ($item['settings'] ?? [])];
                continue;
            }
            foreach ($item['tabs'] as $tab) {
                $id = is_array($tab) ? (string) ($tab['id'] ?? '') : (string) $tab;
                $cards[] = ['id' => $id, 'size' => (string) ($item['size'] ?? Widget::MEDIUM), 'settings' => is_array($tab) ? (array) ($tab['settings'] ?? []) : []];
            }
        }

        return $cards;
    }

    /** A group's id as kept: `tabs:` and up to 32 letters or digits, or one made from its cards. */
    private static function groupId(mixed $given, array $ids): string
    {
        if (is_string($given) && preg_match('/^tabs:[a-z0-9]{1,32}$/', $given) === 1) {
            return $given;
        }

        return 'tabs:' . substr(md5(implode(',', $ids)), 0, 12);
    }

    /** A widget's own width, or {@see Widget::MEDIUM} for one it does not know. */
    private static function size(string $size): string
    {
        return Widget::normalSize($size) ?? Widget::MEDIUM;
    }

    /**
     * Every layout saved for an area, as kept — what `dashboard:precompute` works out the shared
     * cards of. Each is cleaned against nobody: settings are cleaned by the card that reads them.
     *
     * @return list<list<array<string, mixed>>>
     */
    public function saved(string $area = 'admin'): array
    {
        $layouts = [];
        foreach ($this->table()->select(['value'])->where('setting', self::SETTING . $area)->getAllOrFail() as $row) {
            $layout = json_decode((string) ($row['value'] ?? ''), true);
            if (is_array($layout)) {
                $layouts[] = array_values(array_filter($layout, 'is_array'));
            }
        }

        return $layouts;
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return ($this->db ?? Database::getInstance())->queryBuilder()->table(defined('DB_USERSETTINGSTABLE') ? DB_USERSETTINGSTABLE : '#PREFIX#usersettings');
    }
}
