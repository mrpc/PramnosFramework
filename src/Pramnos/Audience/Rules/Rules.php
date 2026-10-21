<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Audience\Installed;
use Pramnos\Database\Database;

/**
 * Rules as they are kept: a name, a status, the current definition, and every version before it.
 *
 * ```php
 * $id = Rules::create('Premium expiring', $definition, owner: $adminId);   // a draft, version 1
 * Rules::update($id, $changed, by: $adminId);                              // version 2
 * Rules::setStatus($id, 'shadow', by: $adminId);    // runs, writes what it would do, sends nothing
 * Rules::setStatus($id, 'active', by: $adminId);
 * ```
 *
 * A saved change is a new version: runs already under way finish on theirs, new entrants take
 * the new one. Every create, change and status is in the audit log with the definition before and
 * after. A segment or consent list a rule reads is not deleted while the rule exists.
 */
final class Rules
{
    public const STATUSES = ['draft', 'shadow', 'active', 'paused'];

    /** The statuses in which a rule takes people in. */
    public const RUNNING = ['shadow', 'active'];

    /** Where the events the running rules listen for are remembered between requests. */
    private const CACHE_KEY = 'rule-events';

    /**
     * Make a rule, as a draft.
     *
     * @param  array<string, mixed>|string $definition
     * @throws \InvalidArgumentException A definition that does not check, or no name
     */
    public static function create(string $name, array|string $definition, ?int $owner = null): int
    {
        $checked = Definition::check($definition);
        $now     = time();
        self::db()->queryBuilder()->table('#PREFIX#rules')->insert([
            'name' => self::name($name), 'status' => 'draft', 'version' => 1, 'definition' => self::json($checked),
            'owner' => $owner, 'createdat' => $now, 'updatedat' => $now,
        ]);
        $id = (int) self::db()->getInsertId();
        self::keepVersion($id, 1, $checked, $owner);
        \Pramnos\Auth\AuditLog::change('rule.created', $owner, 'rule', $id, [], ['name' => $name, 'version' => 1, 'definition' => $checked]);

        return $id;
    }

    /**
     * Change a rule's name or definition. A definition that differs is a new version.
     *
     * @param  array{name?: string, definition?: array<string, mixed>|string} $changes
     * @return int The version now current
     */
    public static function update(int $id, array $changes, ?int $by = null): int
    {
        $rule = self::get($id) ?? throw new \InvalidArgumentException("No rule {$id}.");
        $row  = ['updatedat' => time()];
        if (isset($changes['name'])) {
            $row['name'] = self::name($changes['name']);
        }
        $version = $rule['version'];
        if (isset($changes['definition'])) {
            $checked = Definition::check($changes['definition']);
            if (self::json($checked) !== self::json($rule['definition'])) {
                $version++;
                $row += ['definition' => self::json($checked), 'version' => $version];
                self::keepVersion($id, $version, $checked, $by);
            }
        }
        self::db()->queryBuilder()->table('#PREFIX#rules')->where('ruleid', $id)->update($row);
        \Pramnos\Auth\AuditLog::change('rule.updated', $by, 'rule', $id,
            ['name' => $rule['name'], 'version' => $rule['version'], 'definition' => $rule['definition']],
            ['name' => $row['name'] ?? $rule['name'], 'version' => $version, 'definition' => isset($row['definition']) ? json_decode($row['definition'], true) : $rule['definition']]);
        self::forgetEvents();

        return $version;
    }

    /**
     * Draft, shadow, active or paused. Switching a `once` rule on takes everybody who matches in,
     * in the queue.
     */
    public static function setStatus(int $id, string $status, ?int $by = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('A rule is ' . implode(', ', self::STATUSES) . '.');
        }
        $rule = self::get($id) ?? throw new \InvalidArgumentException("No rule {$id}.");
        self::db()->queryBuilder()->table('#PREFIX#rules')->where('ruleid', $id)->update(['status' => $status, 'updatedat' => time()]);
        \Pramnos\Auth\AuditLog::change('rule.status', $by, 'rule', $id, ['status' => $rule['status']], ['status' => $status]);
        self::forgetEvents();
        if (in_array($status, self::RUNNING, true) && !in_array($rule['status'], self::RUNNING, true)
            && $rule['definition']['trigger']['type'] === 'once') {
            Engine::schedule(['kind' => 'everybody', 'rule' => $id]);
        }
    }

    /**
     * Delete a rule, its versions, its runs and its log. Refused while it is running.
     */
    public static function delete(int $id, ?int $by = null): void
    {
        $rule = self::get($id) ?? throw new \InvalidArgumentException("No rule {$id}.");
        if (in_array($rule['status'], self::RUNNING, true)) {
            throw new \RuntimeException(t('Pause the rule before deleting it.'));
        }
        foreach (['rule_log', 'rule_runs', 'rule_versions', 'rules'] as $table) {
            self::db()->queryBuilder()->table('#PREFIX#' . $table)->where('ruleid', $id)->delete();
        }
        \Pramnos\Auth\AuditLog::change('rule.deleted', $by, 'rule', $id, ['name' => $rule['name'], 'definition' => $rule['definition']], []);
        self::forgetEvents();
    }

    /**
     * One rule, or null.
     *
     * @return array{id: int, name: string, status: string, version: int, definition: array<string, mixed>, owner: ?int, createdAt: int, updatedAt: int}|null
     */
    public static function get(int $id): ?array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#rules')->where('ruleid', $id)->limit(1)->getAllOrFail();

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * Every rule, or those in these statuses, by name.
     *
     * @param  list<string> $statuses
     * @return list<array{id: int, name: string, status: string, version: int, definition: array<string, mixed>, owner: ?int, createdAt: int, updatedAt: int}>
     */
    public static function all(array $statuses = []): array
    {
        $query = self::db()->queryBuilder()->table('#PREFIX#rules')->orderBy('name');
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return array_map(self::row(...), $query->getAllOrFail());
    }

    /**
     * A version of a rule's definition, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function version(int $id, int $version): ?array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#rule_versions')->where('ruleid', $id)
            ->where('version', $version)->limit(1)->getAllOrFail();

        return $rows === [] ? null : (array) json_decode((string) $rows[0]['definition'], true);
    }

    /**
     * A rule's versions, newest first: who saved each, when.
     *
     * @return list<array{version: int, by: ?int, at: int}>
     */
    public static function versions(int $id): array
    {
        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version'],
            'by'      => $row['createdby'] === null ? null : (int) $row['createdby'],
            'at'      => (int) $row['createdat'],
        ], self::db()->queryBuilder()->table('#PREFIX#rule_versions')->where('ruleid', $id)->orderBy('version', 'desc')->getAllOrFail());
    }

    /**
     * The rules that read a segment — what keeps it from being deleted.
     *
     * @return list<string>
     */
    public static function usingSegment(int $segment): array
    {
        return self::using(static fn (array $definition): bool => in_array($segment, Definition::segments($definition), true));
    }

    /**
     * The rules that send to a consent list.
     *
     * @return list<string>
     */
    public static function usingConsentList(int $list): array
    {
        return self::using(static fn (array $definition): bool => $definition['consent'] === $list);
    }

    /**
     * The event names running rules listen for — remembered a minute, so a request that fires an
     * event does not read the rules to find out.
     *
     * @return list<string>
     */
    public static function listenedEvents(): array
    {
        if (!Installed::has('rules')) {
            return [];
        }
        $read = static function (): string {
            $names = [];
            foreach (self::all(self::RUNNING) as $rule) {
                array_push($names, ...Definition::events($rule['definition']));
            }

            return (string) json_encode(array_values(array_unique($names)));
        };
        try {
            $json = \Pramnos\Cache\Cache::getInstance('audience')->remember(self::CACHE_KEY, 60, $read);
        } catch (\Throwable) {
            $json = $read();
        }

        return (array) json_decode((string) $json, true);
    }

    /** Forget the remembered event names: a rule started or stopped listening. */
    public static function forgetEvents(): void
    {
        try {
            \Pramnos\Cache\Cache::getInstance('audience')->delete(self::CACHE_KEY);
        } catch (\Throwable) {
            // nothing remembered, nothing to forget
        }
    }

    /**
     * Names of rules whose definition answers yes; none where rules are not installed.
     *
     * @param  callable(array<string, mixed>): bool $reads
     * @return list<string>
     */
    private static function using(callable $reads): array
    {
        if (!Installed::has('rules')) {
            return [];
        }
        $names = [];
        foreach (self::all() as $rule) {
            if ($reads($rule['definition'])) {
                $names[] = t('rule %s', $rule['name']);
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $definition */
    private static function keepVersion(int $id, int $version, array $definition, ?int $by): void
    {
        self::db()->queryBuilder()->table('#PREFIX#rule_versions')->insert([
            'ruleid' => $id, 'version' => $version, 'definition' => self::json($definition), 'createdby' => $by, 'createdat' => time(),
        ]);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{id: int, name: string, status: string, version: int, definition: array<string, mixed>, owner: ?int, createdAt: int, updatedAt: int}
     */
    private static function row(array $row): array
    {
        return [
            'id'         => (int) $row['ruleid'],
            'name'       => (string) $row['name'],
            'status'     => (string) $row['status'],
            'version'    => (int) $row['version'],
            'definition' => (array) json_decode((string) $row['definition'], true),
            'owner'      => $row['owner'] === null ? null : (int) $row['owner'],
            'createdAt'  => (int) $row['createdat'],
            'updatedAt'  => (int) $row['updatedat'],
        ];
    }

    private static function name(string $name): string
    {
        $name = trim(strip_tags($name));
        if ($name === '') {
            throw new \InvalidArgumentException(t('A rule needs a name.'));
        }

        return mb_substr($name, 0, 150);
    }

    /** @param array<string, mixed> $definition */
    private static function json(array $definition): string
    {
        return (string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
