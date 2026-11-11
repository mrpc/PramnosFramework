<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Dashboard\Presets;
use Pramnos\Database\Database;

/**
 * Reports built from metrics: the application's, from app.php, and the ones people save, share
 * and have mailed.
 *
 * ```php
 * // app/app.php
 * 'metrics' => ['reports' => [
 *     'sales' => ['name' => 'Sales', 'grain' => 'month', 'columns' => ['periods' => 12], 'sections' => [...]],
 * ]],
 *
 * $reports = new Reports();
 * $reports->visibleTo($user);                 // the app's ('app:sales'), then the saved ones this person may see
 * $id = $reports->save($user, $definition, Presets::ROLE, '90', Reports::MONTHLY, 'boss@example.com');
 * ```
 *
 * Shared as dashboard presets are — {@see Presets::VISIBILITIES} — and changed or deleted by
 * their owner, or deleted by an administrator. A mailed report is sent once for each period
 * that closes ({@see due()}), as its owner would see it. Kept in `stats.reports`.
 *
 * - **Who opens which** is the framework's rule, unless the application says otherwise for one:
 *   `'metrics' => ['reports_access' => fn (object $user, array $entry): ?bool]` — null keeps the
 *   rule ({@see access()}). Listing, opening, exporting and mailing all ask it.
 * - **In the menu**: a report is shown in the application's menu where somebody who may
 *   (`'metrics' => ['menu_editors' => fn (object $user): bool]`, root by default) turned it on —
 *   an `app:` one by its definition's `menu` until then ({@see inMenu()}, {@see setMenu()}).
 * - **Customised**: a person's own copy of a report they may not change, bound to it — opened in
 *   its place for them, until they go back to the original ({@see customise()}, {@see uncustomise()}).
 */
final class Reports
{
    public const TABLE = 'stats.reports';

    /** The id prefix of a report app.php defines, before its key. */
    public const APP = 'app:';

    public const NEVER   = '';
    public const DAILY   = 'day';
    public const WEEKLY  = 'week';
    public const MONTHLY = 'month';

    /** How often a report may be mailed: after each closed day, week or month. */
    public const SCHEDULES = [self::NEVER, self::DAILY, self::WEEKLY, self::MONTHLY];

    /** Where the menu's choices are kept: report id => shown or not. */
    public const MENU_SETTING = 'metrics_reports_menu';

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * What the application says of a person and a report — true to let them open it, false to
     * keep it from them — or null where it says nothing (`metrics.reports_access`).
     *
     * @param array<string, mixed> $entry
     */
    public static function access(object $user, array $entry): ?bool
    {
        $hook = Metrics::config()['reports_access'] ?? null;
        $said = is_callable($hook) ? $hook($user, $entry) : null;

        return is_bool($said) ? $said : null;
    }

    /**
     * The reports this person may open: the application's first, then the saved ones — their
     * own and those shared with them — each with its `id`, `name`, `report` ({@see Report}) and,
     * for a saved one, its row.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(object $user): array
    {
        $out = [];
        foreach ((array) (Metrics::config()['reports'] ?? []) as $key => $definition) {
            try {
                $report = Report::fromArray((array) $definition);
            } catch (\InvalidArgumentException $e) {
                \Pramnos\Logs\Logger::log('Report ' . $key . ' in app.php is left out: ' . $e->getMessage(), 'metrics');
                continue;
            }
            $entry = ['id' => self::APP . $key, 'name' => $report->definition['name'] !== '' ? $report->definition['name'] : (string) $key, 'report' => $report, 'app' => true,
                'menu' => !empty(((array) $definition)['menu'])];
            if (self::access($user, $entry) !== false) {
                $out[] = $entry;
            }
        }
        foreach ($this->rows() as $row) {
            $entry = $this->entry($row);
            // A person's customised copy stands in for its original, never beside it in the list.
            if (isset($entry['report']->definition['customises'])) {
                continue;
            }
            if (self::access($user, $entry) ?? Presets::canSee($row, $user)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The reports shown in the application's menu for this person: those they may open that
     * somebody turned on — an `app:` one by its definition's `menu` until then.
     *
     * @return list<array<string, mixed>>
     */
    public function inMenu(object $user): array
    {
        $chosen = self::menuChoices();

        return array_values(array_filter($this->visibleTo($user), static fn (array $e): bool => $chosen[$e['id']] ?? !empty($e['menu'])));
    }

    /** Whether a report is shown in the menu. */
    public function shownInMenu(array $entry): bool
    {
        return self::menuChoices()[(string) ($entry['customises'] ?? $entry['id'])] ?? !empty($entry['menu']);
    }

    /** Whether this person turns reports on and off in the menu (`metrics.menu_editors`, root by default). */
    public static function editsMenu(object $user): bool
    {
        $hook = Metrics::config()['menu_editors'] ?? null;

        return is_callable($hook) ? (bool) $hook($user) : \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::ROOT);
    }

    /**
     * Show a report in the menu, or not — for whoever {@see editsMenu()}, and a report they may
     * open. Whether it was kept.
     */
    public function setMenu(string $id, bool $on, object $user): bool
    {
        if (!self::editsMenu($user) || $this->find($id, $user) === null) {
            return false;
        }
        $chosen      = self::menuChoices();
        $chosen[$id] = $on;
        \Pramnos\Application\Settings::setSetting(self::MENU_SETTING, (string) json_encode($chosen, JSON_UNESCAPED_UNICODE));

        return true;
    }

    /** @return array<string, bool> */
    private static function menuChoices(): array
    {
        $kept = json_decode((string) \Pramnos\Application\Settings::getSetting(self::MENU_SETTING, '{}'), true);

        return is_array($kept) ? array_map('boolval', $kept) : [];
    }

    /**
     * A person's own copy of a report they may open, bound to it — made the first time, the same
     * one after — to arrange as they like; opened in its place for them ({@see find()}). Its id.
     *
     * @throws \InvalidArgumentException For a report they may not open
     */
    public function customise(string $id, object $user): int
    {
        $original = $this->find($id, $user);
        if ($original === null) {
            throw new \InvalidArgumentException('There is no such report.');
        }
        $id = (string) ($original['customises'] ?? $original['id']);
        if (($copy = $this->copyOf($id, $user)) !== null) {
            return (int) $copy['reportid'];
        }
        $definition               = $original['report']->definition;
        $definition['customises'] = $id;
        if (!is_array($definition['layout'] ?? null)) {
            $definition['layout'] = ReportBoard::layout($original['report']);
        }

        return $this->save($user, $definition);
    }

    /** Go back to the original: the person's customised copy of it deleted. Whether there was one. */
    public function uncustomise(string $id, object $user): bool
    {
        $copy = $this->copyOf($id, $user);

        return $copy !== null && $this->delete((int) $copy['reportid'], $user);
    }

    /** A person's customised copy of a report, its row, or null. @return array<string, mixed>|null */
    private function copyOf(string $id, object $user): ?array
    {
        foreach ($this->rows(['ownerid' => (int) ($user->userid ?? 0)]) as $row) {
            if ((((array) json_decode((string) $row['definition'], true))['customises'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * One report this person may open, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, object $user): ?array
    {
        foreach ($this->visibleTo($user) as $entry) {
            if ($entry['id'] === $id) {
                // Their own copy, where they customised it: with `customises`, the original's id.
                $copy = $this->copyOf($id, $user);

                return $copy === null ? $entry : $this->entry($copy) + ['customises' => $id, 'original' => $entry];
            }
        }
        // A person's own copy, by its own id — its board, as the page draws and saves it — while
        // they may still open the original.
        if (ctype_digit($id) && ($row = $this->rows(['reportid' => (int) $id, 'ownerid' => (int) ($user->userid ?? 0)])[0] ?? null) !== null) {
            $original = ((array) json_decode((string) $row['definition'], true))['customises'] ?? null;
            if (is_string($original) && ($of = $this->find($original, $user)) !== null) {
                return $this->entry($row) + ['customises' => $original, 'original' => $of['original'] ?? $of];
            }
        }

        return null;
    }

    /**
     * Save a report — a new one, or one of the person's own with `$id` — and answer its id.
     *
     * @param array<string, mixed> $definition As {@see Report::fromArray()} takes it
     * @throws \InvalidArgumentException For a definition, visibility, schedule or address it cannot keep, or a report that is not theirs
     */
    public function save(object $user, array $definition, string $visibility = Presets::PRIVATE, ?string $audience = null, string $schedule = self::NEVER, string $recipients = '', ?int $id = null): int
    {
        $report = Report::fromArray($definition);
        $name   = $report->definition['name'];
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('A report needs a name of up to 120 characters.');
        }
        if (!in_array($schedule, self::SCHEDULES, true)) {
            throw new \InvalidArgumentException('A report is mailed daily, weekly or monthly, not "' . $schedule . '".');
        }
        $addresses = self::addresses($recipients);
        if ($schedule !== self::NEVER && $addresses === []) {
            throw new \InvalidArgumentException('Say who the report is mailed to.');
        }
        $values = [
            'name'       => $name,
            'definition' => json_encode($report->definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'visibility' => $visibility,
            'audience'   => Presets::audience($visibility, $audience),
            'schedule'   => $schedule,
            'recipients' => implode(', ', $addresses),
            'updated_at' => time(),
        ];
        if ($id !== null) {
            $row = $this->rows(['reportid' => $id])[0] ?? null;
            if ($row === null || (int) $row['ownerid'] !== (int) ($user->userid ?? 0)) {
                throw new \InvalidArgumentException('There is no such report of yours.');
            }
            $this->db()->queryBuilder()->table(self::TABLE)->where('reportid', $id)->update($values);

            return $id;
        }
        $this->db()->queryBuilder()->table(self::TABLE)->insert($values + ['ownerid' => (int) ($user->userid ?? 0), 'created_at' => time(), 'sent_period' => '']);

        return (int) $this->db()->getInsertId();
    }

    /**
     * Keep a report's cards — its layout, as the dashboard sends it, already cleaned — in its
     * definition; its sections, now read as those cards, go.
     *
     * @param list<array<string, mixed>> $layout
     * @throws \InvalidArgumentException For a report that is not this person's own
     */
    public function saveLayout(object $user, int $id, array $layout): void
    {
        $row = $this->rows(['reportid' => $id])[0] ?? null;
        if ($row === null || (int) $row['ownerid'] !== (int) ($user->userid ?? 0)) {
            throw new \InvalidArgumentException('There is no such report of yours.');
        }
        $definition             = (array) json_decode((string) $row['definition'], true);
        $definition['layout']   = $layout;
        $definition['sections'] = [];
        $this->db()->queryBuilder()->table(self::TABLE)->where('reportid', $id)->update([
            'definition' => json_encode(Report::fromArray($definition)->definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => time(),
        ]);
    }

    /** Whether a report is one this person may change: a saved one of their own. */
    public function owns(string $id, object $user): bool
    {
        if (!ctype_digit($id)) {
            return false;
        }
        $row = $this->rows(['reportid' => (int) $id])[0] ?? null;

        return $row !== null && (int) $row['ownerid'] === (int) ($user->userid ?? 0);
    }

    /** Delete a saved report: its owner's, or anybody's for an administrator. Whether one went. */
    public function delete(int $id, object $user, bool $asAdministrator = false): bool
    {
        $query = $this->db()->queryBuilder()->table(self::TABLE)->where('reportid', $id);
        if (!$asAdministrator) {
            $query->where('ownerid', (int) ($user->userid ?? 0));
        }
        $result = $query->delete();

        return is_object($result) && method_exists($result, 'getAffectedRows') ? (int) $result->getAffectedRows() > 0 : (bool) $result;
    }

    /**
     * The saved reports due to be mailed: each with a schedule whose last closed period is not
     * the one last mailed — with `period`, the first day of that period.
     *
     * @return list<array<string, mixed>>
     */
    public function due(?int $now = null): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            $schedule = (string) $row['schedule'];
            if ($schedule === self::NEVER || !in_array($schedule, self::SCHEDULES, true)) {
                continue;
            }
            $period = Window::of($schedule, $now ?? time())->previous()->key();
            if ((string) $row['sent_period'] !== $period) {
                $out[] = $this->entry($row) + ['period' => $period];
            }
        }

        return $out;
    }

    /** Record that a report was mailed for a period. */
    public function markSent(int $id, string $period): void
    {
        $this->db()->queryBuilder()->table(self::TABLE)->where('reportid', $id)->update(['sent_period' => $period]);
    }

    /** @return list<string> The valid addresses in a comma- or space-separated list */
    public static function addresses(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $address) {
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($address)] = $address;
            }
        }

        return array_values($out);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function entry(array $row): array
    {
        $report = Report::fromArray((array) json_decode((string) $row['definition'], true));

        return ['id' => (string) $row['reportid'], 'name' => (string) $row['name'], 'report' => $report, 'app' => false, 'row' => $row];
    }

    /**
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private function rows(array $where = []): array
    {
        try {
            $query = $this->db()->queryBuilder()->table(self::TABLE);
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return array_values($query->orderBy('reportid')->getAllOrFail());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Reports unavailable: ' . $e->getMessage(), 'metrics');

            return [];
        }
    }

    private function db(): Database
    {
        return $this->db ?? Database::getInstance();
    }
}
