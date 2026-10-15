<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

use Pramnos\Database\Database;

/**
 * Each person's dashboard: which widgets, in what order, how wide — kept per person and per area.
 *
 * ```php
 * $layouts = new Layouts();
 * $layouts->for($user);                         // [['id' => 'users.active', 'size' => 'half'], …]
 * $layouts->save($user, [['id' => 'health', 'size' => 'full']]);
 * $layouts->save($user, null);                  // back to the default
 * ```
 *
 * A layout is cleaned whenever it is read or written: a widget nobody registers any more, one this
 * person may not have, a second copy of one, an unknown width — each is dropped or put right, not
 * an error. So a saved layout can never show what its owner's permissions do not allow.
 *
 * Kept in `user_dashboards` (`userid`, `area`, `layout` as JSON): a layout of a dozen widgets does
 * not fit the 255 characters of a user setting, and an area — the administration, a partner
 * portal — has a dashboard of its own.
 */
final class Layouts
{
    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * This person's layout: the one they saved, cleaned, or the default.
     *
     * @return list<array{id: string, size: string}>
     */
    public function for(object $user, string $area = 'admin'): array
    {
        try {
            $result = $this->table()->where('userid', (int) ($user->userid ?? 0))->where('area', $area)->first();
        } catch (\Throwable $e) {
            // No table yet — `migrate` has not run since the framework added it. The default still
            // draws; saving will say what is wrong.
            \Pramnos\Logs\Logger::log('Dashboard layouts unavailable, the default shown: ' . $e->getMessage(), 'dashboard');
            $result = null;
        }
        $saved  = $result && $result->numRows > 0 ? json_decode((string) ($result->fields['layout'] ?? ''), true) : null;

        return is_array($saved) ? self::clean($saved, $user) : self::defaults($user);
    }

    /**
     * Keep a layout, cleaned; null forgets it, so the default applies again.
     *
     * @param mixed $layout As a page sends it: `[{id, size}, …]`
     * @return list<array{id: string, size: string}> What applies now
     */
    public function save(object $user, mixed $layout, string $area = 'admin'): array
    {
        $userId = (int) ($user->userid ?? 0);
        $this->table()->where('userid', $userId)->where('area', $area)->delete();
        if ($layout === null) {
            return self::defaults($user);
        }

        $clean = self::clean($layout, $user);
        $this->table()->insert([
            'userid'     => $userId,
            'area'       => $area,
            'layout'     => (string) json_encode($clean),
            'updated_at' => time(),
        ]);

        return $clean;
    }

    /**
     * The layout of somebody who has saved none: every widget they may have that is on by default,
     * at its own width, in the registry's order.
     *
     * @return list<array{id: string, size: string}>
     */
    public static function defaults(?object $user): array
    {
        $layout = [];
        foreach (WidgetRegistry::available($user) as $id => $widget) {
            if ($widget->byDefault()) {
                $layout[] = ['id' => (string) $id, 'size' => self::size($widget->size())];
            }
        }

        return $layout;
    }

    /**
     * A layout as it may be kept: widgets this person may have, each once, at a known width.
     *
     * @return list<array{id: string, size: string}>
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
            $size       = is_array($item) ? (string) ($item['size'] ?? '') : '';
            $clean[$id] = ['id' => $id, 'size' => in_array($size, [Widget::HALF, Widget::FULL], true) ? $size : self::size($available[$id]->size())];
        }

        return array_values($clean);
    }

    private static function size(string $size): string
    {
        return $size === Widget::FULL ? Widget::FULL : Widget::HALF;
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return ($this->db ?? Database::getInstance())->queryBuilder()->table('#PREFIX#user_dashboards');
    }
}
