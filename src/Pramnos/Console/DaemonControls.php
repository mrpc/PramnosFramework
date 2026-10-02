<?php

declare(strict_types=1);

namespace Pramnos\Console;

use Pramnos\Database\Database;

/**
 * What an operator decided about the supervised workers, where the supervisor reads it.
 *
 * Two decisions the services screen makes and a sentinel file cannot hold:
 *
 * - **A pool's shape.** A pool defined on the screen, or the screen's changes to a pool the
 *   application declares with {@see DaemonOrchestrator::queuePool()}. Every limit is
 *   nullable, and null means "as declared", so raising one ceiling does not freeze the rest
 *   of a code pool at today's values.
 * - **A stopped service.** Stopping a worker through its stop file made it exit, and the
 *   supervisor — which still had it on its list — started it again on the next cycle. A
 *   service listed here is left off the list until somebody starts it.
 *
 * The orchestrator reads both once per cycle; the screen writes them. Every value is
 * validated here, because this is the one place both sides go through.
 */
class DaemonControls
{
    /** Pools defined or adjusted on the screen. */
    public const POOLS_TABLE = 'pramnos.worker_pools';

    /** Services an operator stopped. */
    public const STOPPED_TABLE = 'pramnos.stopped_services';

    /** The limits a pool row can set, and the range each must fall in. */
    private const LIMITS = [
        'floor'        => [0, 50],
        'ceiling'      => [0, 50],
        'grow_above'   => [1, 1000000],
        'shrink_below' => [0, 1000000],
        'load_percent' => [1, 100],
        'cooldown'     => [0, 60],
    ];

    public function __construct(private ?Database $db = null)
    {
    }

    /** The connection, resolved late so constructing this connects to nothing. */
    private function db(): Database
    {
        return $this->db ??= \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Every pool row, by name.
     *
     * Empty when the tables are not there yet — an installation that has not run `migrate`
     * supervises exactly what its code declares.
     *
     * @return array<string, array{name: string, types: ?string, floor: ?int, ceiling: ?int,
     *         grow_above: ?int, shrink_below: ?int, load_percent: ?int, cooldown: ?int,
     *         enabled: bool, updated_at: int, updated_by: ?string}>
     */
    public function pools(): array
    {
        try {
            $result = $this->db()->queryBuilder()->table(self::POOLS_TABLE)->orderBy('name')->get();
        } catch (\Throwable) {
            return [];
        }

        $pools = [];
        while ($result && $result->fetch()) {
            $row  = $result->fields;
            $pool = ['name' => (string) $row['name'], 'types' => $row['types'] === null ? null : (string) $row['types']];
            foreach (array_keys(self::LIMITS) as $key) {
                $pool[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            $pool['enabled']    = (bool) (int) $row['enabled'];
            $pool['updated_at'] = (int) $row['updated_at'];
            $pool['updated_by'] = $row['updated_by'] === null ? null : (string) $row['updated_by'];
            $pools[$pool['name']] = $pool;
        }

        return $pools;
    }

    /**
     * A pool row as {@see DaemonOrchestrator::queuePool()} configuration: only what it sets.
     *
     * @param array<string, mixed> $row One entry of {@see pools()}
     * @return array<string, mixed>
     */
    public static function asPoolConfig(array $row): array
    {
        $config = [];
        if ($row['types'] !== null) {
            $config['types'] = (string) $row['types'];
        }
        foreach (['floor', 'ceiling', 'grow_above', 'shrink_below', 'cooldown'] as $key) {
            if ($row[$key] !== null) {
                $config[$key] = (int) $row[$key];
            }
        }
        if ($row['load_percent'] !== null) {
            $config['load_ceiling'] = (int) $row['load_percent'] / 100;
        }

        return $config;
    }

    /**
     * Create or replace a pool row.
     *
     * Fields left out or empty are stored as null — "as declared" for a code pool, the
     * policy's defaults for a pool that exists only here.
     *
     * @param array<string, mixed> $fields name-less: types, floor, ceiling, grow_above,
     *                                     shrink_below, load_percent, cooldown, enabled
     * @throws \InvalidArgumentException With a sentence an operator can act on
     */
    public function savePool(string $name, array $fields, string $by = ''): void
    {
        $name = $this->validName($name);
        $row  = ['name' => $name, 'types' => $this->validTypes($fields['types'] ?? null)];

        foreach (self::LIMITS as $key => [$min, $max]) {
            $value = $fields[$key] ?? null;
            if ($value === null || $value === '') {
                $row[$key] = null;
                continue;
            }
            if (!is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
                throw new \InvalidArgumentException(
                    str_replace('_', ' ', $key) . ' must be a whole number from ' . $min . ' to ' . $max . '.'
                );
            }
            $row[$key] = (int) $value;
        }

        if ($row['floor'] !== null && $row['ceiling'] !== null && $row['ceiling'] < $row['floor']) {
            throw new \InvalidArgumentException('ceiling cannot be below floor.');
        }
        // One threshold oscillates by construction; the gap is what lets the pool settle.
        if ($row['grow_above'] !== null && $row['shrink_below'] !== null && $row['shrink_below'] >= $row['grow_above']) {
            throw new \InvalidArgumentException('shrink below has to be less than grow above.');
        }

        $row['enabled']    = array_key_exists('enabled', $fields) ? (bool) $fields['enabled'] : true;
        $row['updated_at'] = time();
        $row['updated_by'] = $by === '' ? null : mb_substr($by, 0, 191);

        $update = $row;
        unset($update['name']);
        $this->db()->queryBuilder()->table(self::POOLS_TABLE)->upsert($row, ['name'], $update);
    }

    /**
     * Stop or start a pool.
     *
     * A pool declared in code with no row yet gets one carrying nothing but the flag, so its
     * declared limits still apply when it is started again.
     */
    public function setPoolEnabled(string $name, bool $enabled, string $by = ''): void
    {
        $name   = $this->validName($name);
        $values = [
            'name'       => $name,
            'enabled'    => $enabled,
            'updated_at' => time(),
            'updated_by' => $by === '' ? null : mb_substr($by, 0, 191),
        ];
        $update = $values;
        unset($update['name']);

        $this->db()->queryBuilder()->table(self::POOLS_TABLE)->upsert($values, ['name'], $update);
    }

    /**
     * Forget a pool row: a screen pool goes away, a code pool goes back to its declaration.
     */
    public function deletePool(string $name): void
    {
        $this->db()->queryBuilder()->table(self::POOLS_TABLE)->where('name', $this->validName($name))->delete();
    }

    /**
     * The services an operator stopped, by id.
     *
     * @return array<string, array{id: string, stopped_at: int, stopped_by: ?string}>
     */
    public function stoppedServices(): array
    {
        try {
            $result = $this->db()->queryBuilder()->table(self::STOPPED_TABLE)->get();
        } catch (\Throwable) {
            return [];
        }

        $stopped = [];
        while ($result && $result->fetch()) {
            $id           = (string) $result->fields['id'];
            $stopped[$id] = [
                'id'         => $id,
                'stopped_at' => (int) $result->fields['stopped_at'],
                'stopped_by' => $result->fields['stopped_by'] === null ? null : (string) $result->fields['stopped_by'],
            ];
        }

        return $stopped;
    }

    /** Keep a service stopped until {@see startService()}. */
    public function stopService(string $id, string $by = ''): void
    {
        $id     = $this->validServiceId($id);
        $values = ['id' => $id, 'stopped_at' => time(), 'stopped_by' => $by === '' ? null : mb_substr($by, 0, 191)];
        $update = $values;
        unset($update['id']);

        $this->db()->queryBuilder()->table(self::STOPPED_TABLE)->upsert($values, ['id'], $update);
    }

    /** Let the supervisor run a stopped service again. */
    public function startService(string $id): void
    {
        $this->db()->queryBuilder()->table(self::STOPPED_TABLE)->where('id', $this->validServiceId($id))->delete();
    }

    /**
     * A pool name: what worker ids and lock files are built from, so a narrow alphabet.
     *
     * @throws \InvalidArgumentException
     */
    private function validName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,59}$/i', $name) !== 1) {
            throw new \InvalidArgumentException(
                'A pool name is letters, digits, dots, dashes and underscores, up to 60 characters.'
            );
        }

        return $name;
    }

    /**
     * Task types as a clean comma list, or null when none are given.
     *
     * @throws \InvalidArgumentException
     */
    private function validTypes(mixed $types): ?string
    {
        if ($types === null) {
            return null;
        }

        $list = array_values(array_filter(array_map('trim', explode(',', (string) $types)), 'strlen'));
        // Empty is "as declared": a form field left blank must not widen a code pool to every
        // type. A pool that exists only here takes every type when it names none.
        if ($list === []) {
            return null;
        }
        foreach ($list as $type) {
            // The width of queueitems.type, and an alphabet that cannot become an argument.
            if (preg_match('/^[A-Za-z0-9_.:-]{1,50}$/', $type) !== 1) {
                throw new \InvalidArgumentException('"' . $type . '" is not a task type name.');
            }
        }

        return implode(',', $list);
    }

    /**
     * A service id as the orchestrator writes it.
     *
     * @throws \InvalidArgumentException
     */
    private function validServiceId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || mb_strlen($id) > 191) {
            throw new \InvalidArgumentException('That is not a service id.');
        }

        return $id;
    }
}
