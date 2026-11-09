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

    public function __construct(private readonly ?Database $db = null)
    {
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
            $out[] = ['id' => self::APP . $key, 'name' => $report->definition['name'] !== '' ? $report->definition['name'] : (string) $key, 'report' => $report, 'app' => true];
        }
        foreach ($this->rows() as $row) {
            if (Presets::canSee($row, $user)) {
                $out[] = $this->entry($row);
            }
        }

        return $out;
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
                return $entry;
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
