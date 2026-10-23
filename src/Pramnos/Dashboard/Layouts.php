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
        try {
            $rows = $this->table()->select(['value'])->where('userid', (int) ($user->userid ?? 0))->where('setting', self::SETTING . $area)
                ->limit(1)->getAllOrFail();
        } catch (\Throwable $e) {
            // No table yet — `migrate` has not run. The default still draws; saving will say what is wrong.
            \Pramnos\Logs\Logger::log('Dashboard layouts unavailable, the default shown: ' . $e->getMessage(), 'dashboard');
            $rows = [];
        }
        $saved = $rows === [] ? null : json_decode((string) ($rows[0]['value'] ?? ''), true);

        return is_array($saved) ? self::clean($saved, $user) : self::defaults($user);
    }

    /**
     * Keep a layout, cleaned; null forgets it, so the default applies again.
     *
     * @param mixed $layout As a page sends it: `[{id, size, settings}, …]`
     * @return list<array{id: string, size: string, settings: array<string, mixed>}> What applies now
     */
    public function save(object $user, mixed $layout, string $area = 'admin'): array
    {
        $userId = (int) ($user->userid ?? 0);
        $this->table()->where('userid', $userId)->where('setting', self::SETTING . $area)->delete();
        if ($layout === null) {
            return self::defaults($user);
        }

        $clean = self::clean($layout, $user);
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
     * at its own width, in the registry's order.
     *
     * @return list<array{id: string, size: string, settings: array<string, mixed>}>
     */
    public static function defaults(?object $user): array
    {
        $layout = [];
        foreach (WidgetRegistry::available($user) as $id => $widget) {
            if (WidgetRegistry::byDefault($widget)) {
                $layout[] = ['id' => (string) $id, 'size' => self::size($widget->size()), 'settings' => Widget::cleanSettings($widget, [])];
            }
        }

        return $layout;
    }

    /**
     * A layout as it may be kept: widgets this person may have, each once, at a known width.
     *
     * @return list<array{id: string, size: string, settings: array<string, mixed>}>
     */
    public static function clean(mixed $layout, ?object $user): array
    {
        $available = WidgetRegistry::available($user);
        $clean     = [];
        foreach (is_array($layout) ? $layout : [] as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : (is_string($item) ? $item : '');
            if (!isset($available[$id]) || isset($clean[$id])) {
                continue;
            }
            $size       = Widget::normalSize(is_array($item) ? ($item['size'] ?? null) : null);
            $clean[$id] = [
                'id'       => $id,
                'size'     => $size ?? self::size($available[$id]->size()),
                'settings' => Widget::cleanSettings($available[$id], is_array($item) ? ($item['settings'] ?? []) : []),
            ];
        }

        return array_values($clean);
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
