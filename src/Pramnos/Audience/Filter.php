<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Database\QueryBuilder;

/**
 * Who matches a set of conditions over people's attributes — the one audience model segments,
 * sends and rules share.
 *
 * ```php
 * $filter = Filter::fromArray(['match' => 'all', 'conditions' => [
 *     ['attribute' => 'usertype',  'op' => 'is',                 'value' => 2],
 *     ['attribute' => 'lastlogin', 'op' => 'more_than_days_ago', 'value' => 30],
 *     ['match' => 'any', 'conditions' => [
 *         ['attribute' => 'groups',   'op' => 'any_of', 'value' => [4, 7]],
 *         ['attribute' => 'language', 'op' => 'is',     'value' => 'el'],
 *     ]],
 * ]]);
 *
 * $filter->count();               // people now
 * $filter->sample(10);            // the newest ten, for the builder's preview
 * $filter->ids(after: 0);         // a page of ids, keyset by userid
 * $filter->breakdown('language'); // how many per value
 * $filter->explain(1234);         // each condition ✓/✗ with this person's value
 * ```
 *
 * A filter is checked once, when it is made: an unknown attribute, an operator its type does not
 * have, or a value of the wrong shape is refused there, with the path of the condition, so a
 * saved filter that loads always compiles.
 *
 * "Is not", "none of" and "does not contain" include people with no value: somebody with no
 * professional profile is not a professional in pillar 3. "Before" and "more than N days ago"
 * do not include an empty date — "never signed in" is its own condition, "is empty".
 */
final class Filter
{
    /** How deep groups may nest; deeper is a filter nobody can read. */
    public const MAX_DEPTH = 5;

    /** @param array{match: string, conditions: list<array<string, mixed>>} $tree Already checked */
    private function __construct(private readonly array $tree, private readonly Database $database)
    {
    }

    /**
     * A filter from its stored form; an empty one matches everybody in scope.
     *
     * @param  array<string, mixed>|string|null $filter The array, or its JSON
     * @throws \InvalidArgumentException                What is wrong, and where
     */
    public static function fromArray(array|string|null $filter, ?Database $database = null): self
    {
        if (is_string($filter)) {
            $filter = $filter === '' ? [] : json_decode($filter, true);
            if (!is_array($filter)) {
                throw new \InvalidArgumentException('The filter is not valid JSON.');
            }
        }

        return new self(self::group($filter ?? [], 'filter', 1), $database ?? Database::getInstance());
    }

    /**
     * The checked filter, as it is stored.
     *
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return $this->tree;
    }

    /** How many people match now. */
    public function count(): int
    {
        $rows = $this->query()->select([new Expression('COUNT(DISTINCT users.userid) AS n')])->getAllOrFail();

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * The newest people who match, for a preview.
     *
     * @return list<array{userid: int, username: string, firstname: string, lastname: string, email: string}>
     */
    public function sample(int $limit = 10): array
    {
        $rows = $this->query()->distinct()
            ->select(['users.userid', 'users.username', 'users.firstname', 'users.lastname', 'users.email'])
            ->orderBy('users.userid', 'desc')->limit(max(1, $limit))->getAllOrFail();

        return array_map(static fn (array $row): array => [
            'userid'    => (int) $row['userid'],
            'username'  => (string) $row['username'],
            'firstname' => (string) $row['firstname'],
            'lastname'  => (string) $row['lastname'],
            'email'     => (string) $row['email'],
        ], $rows);
    }

    /**
     * A page of the ids that match, in order, after the last id of the previous page — a
     * keyset, so a page deep into a large audience costs what the first did.
     *
     * @return list<int>
     */
    public function ids(int $after = 0, int $limit = 1000): array
    {
        $rows = $this->query()->distinct()->select(['users.userid'])
            ->where('users.userid', '>', $after)
            ->orderBy('users.userid', 'asc')->limit(max(1, $limit))->getAllOrFail();

        return array_map(static fn (array $row): int => (int) $row['userid'], $rows);
    }

    /** Whether one person matches. */
    public function matches(int $personId): bool
    {
        return $this->query()->select(['users.userid'])->where('users.userid', $personId)->limit(1)->getAllOrFail() !== [];
    }

    /**
     * How many of the people who match have each value of an attribute, largest first.
     *
     * @return list<array{value: mixed, label: string, count: int}>
     */
    public function breakdown(string $key, int $limit = 20): array
    {
        $attribute = self::attribute($key, 'breakdown');
        $query     = $this->query();
        $driver    = $this->database->type;
        if ($attribute->type() === Attribute::MANY) {
            if ($attribute->matchedWith() !== 'users.userid') {
                throw new \InvalidArgumentException("{$key}: a table matched on anything but userid cannot be broken down.");
            }
            $alias  = (string) $attribute->alias();
            $column = $alias . '.' . $attribute->valueColumn();
            $query->join((string) $attribute->table(), $alias . '.' . $attribute->foreignKey(), '=', 'users.userid');
        } else {
            $column = $attribute->sql($driver);
            $this->joinFor($query, [$attribute]);
        }
        $rows   = $query->select([new Expression($column . ' AS v'), new Expression('COUNT(DISTINCT users.userid) AS n')])
            ->groupByRaw($column)->orderByRaw('n DESC')->limit(max(1, $limit))->getAllOrFail();
        $labels = $attribute->valueLabels();

        return array_map(static fn (array $row): array => [
            'value' => $row['v'],
            'label' => $row['v'] === null ? t('(empty)') : (string) ($labels[$row['v']] ?? $row['v']),
            'count' => (int) $row['n'],
        ], $rows);
    }

    /**
     * Why one person matches or not: every condition with ✓/✗ and the value it was compared
     * against, and whether they are a person at all (the scope).
     *
     * @return array{ok: bool, inScope: bool, match: string, conditions: list<array<string, mixed>>}
     */
    public function explain(int $personId): array
    {
        $inScope = Attributes::applyScope($this->database->queryBuilder()->table(Attributes::FROM))
            ->select(['users.userid'])->where('users.userid', $personId)->limit(1)->getAllOrFail() !== [];
        $tree    = $this->explainGroup($this->tree, $personId);

        return ['ok' => $inScope && $tree['ok'], 'inScope' => $inScope] + $tree;
    }

    /**
     * The query every answer starts from: the people in scope who match, with the one-to-one
     * tables the conditions read joined once each. Selects nothing yet.
     */
    public function query(): QueryBuilder
    {
        $query = Attributes::applyScope($this->database->queryBuilder()->table(Attributes::FROM));
        $this->joinFor($query, self::attributesIn($this->tree));
        $this->applyGroup($query, $this->tree);

        return $query;
    }

    /**
     * Every attribute a group reads, nested ones included.
     *
     * @param  array<string, mixed> $group
     * @return list<Attribute>
     */
    private static function attributesIn(array $group): array
    {
        $found = [];
        foreach ($group['conditions'] as $condition) {
            if (isset($condition['conditions'])) {
                array_push($found, ...self::attributesIn($condition));
            } elseif (isset($condition['attribute'])) {
                $found[] = self::attribute($condition['attribute'], 'filter');
            }
        }

        return $found;
    }

    /**
     * The segments a filter asks about, nested groups included — what a segment's recomputation
     * must come after, and what may not be deleted while this filter is kept.
     *
     * @param  array<string, mixed> $group A checked filter, {@see toArray()}
     * @return list<int>
     */
    public static function segmentsIn(array $group): array
    {
        $found = [];
        foreach ($group['conditions'] ?? [] as $condition) {
            if (isset($condition['conditions'])) {
                array_push($found, ...self::segmentsIn($condition));
            } elseif (isset($condition['segment'])) {
                $found[] = (int) $condition['segment'];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * LEFT JOIN each one-to-one table the attributes read, once.
     *
     * @param list<Attribute> $attributes
     */
    private function joinFor(QueryBuilder $query, array $attributes): void
    {
        $joined = [];
        foreach ($attributes as $attribute) {
            $table = $attribute->table();
            if ($table === null || $attribute->type() === Attribute::MANY || isset($joined[$table])) {
                continue;
            }
            $joined[$table] = true;
            $query->leftJoin($table, $attribute->alias() . '.' . $attribute->foreignKey(), '=', 'users.userid');
        }
    }

    /** @param array<string, mixed> $group */
    private function applyGroup(QueryBuilder $query, array $group, string $boolean = 'and'): void
    {
        if (self::isEmpty($group)) {
            return;
        }
        $joiner = $group['match'] === 'any' ? 'or' : 'and';
        $query->where(function (QueryBuilder $nested) use ($group, $joiner): void {
            // Empty groups inside say nothing, and an empty pair of parentheses is not SQL.
            $conditions = array_values(array_filter($group['conditions'], static fn (array $c): bool => !self::isEmpty($c)));
            foreach ($conditions as $i => $condition) {
                $each = $i === 0 ? 'and' : $joiner;
                if (isset($condition['conditions'])) {
                    $this->applyGroup($nested, $condition, $each);
                } elseif (isset($condition['segment'])) {
                    $nested->whereExists(self::inSegment((int) $condition['segment']), $each, $condition['op'] === 'not_in');
                } elseif (isset($condition['event'])) {
                    $this->applyEvent($nested, $condition, $each);
                } else {
                    $this->applyCondition($nested, $condition, $each);
                }
            }
        }, null, null, $boolean);
    }

    /**
     * Whether a group asks nothing — no conditions, or only groups that ask nothing. A condition
     * is never empty.
     *
     * @param array<string, mixed> $node
     */
    private static function isEmpty(array $node): bool
    {
        if (!isset($node['conditions'])) {
            return false;
        }
        foreach ($node['conditions'] as $condition) {
            if (!self::isEmpty($condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One condition, as a nested WHERE so the operators that need two clauses ("is not, or
     * empty") stay one term of the group.
     *
     * @param array{attribute: string, op: string, value?: mixed} $condition
     */
    private function applyCondition(QueryBuilder $query, array $condition, string $boolean): void
    {
        $attribute = self::attribute($condition['attribute'], 'filter');
        $value     = $condition['value'] ?? null;
        $op        = $condition['op'];

        if ($attribute->type() === Attribute::MANY) {
            $this->applyMany($query, $attribute, $op, (array) $value, $boolean);

            return;
        }

        $query->where(function (QueryBuilder $q) use ($attribute, $op, $value): void {
            $c = new Comparison($q, $attribute, $this->database->type);
            match ($op) {
                'is'                 => $c->compare('=', $value),
                'is_not'             => $c->orEmpty(fn () => $c->compare('<>', $value)),
                'one_of'             => $c->in((array) $value),
                'not_one_of'         => $c->orEmpty(fn () => $c->in((array) $value, true)),
                'contains'           => $c->like('%' . self::escapeLike((string) $value) . '%'),
                'not_contains'       => $c->orEmpty(fn () => $c->like('%' . self::escapeLike((string) $value) . '%', true)),
                'starts_with'        => $c->like(self::escapeLike((string) $value) . '%'),
                'gt'                 => $c->compare('>', $value),
                'lt'                 => $c->compare('<', $value),
                // A date range is whole days, the second one included to its last second.
                'between'            => $attribute->type() === Attribute::DATE
                    ? $c->between($c->dateValue(self::dayStart($value[0])), $c->dateValue(self::dayStart($value[1]) + 86399))
                    : $c->between($value[0], $value[1]),
                'after'              => $c->compare('>=', $c->dateValue(self::dayStart((string) $value) + 86400)),
                'before'             => $c->notEmptyAnd(fn () => $c->compare('<', $c->dateValue(self::dayStart((string) $value)))),
                'more_than_days_ago' => $c->notEmptyAnd(fn () => $c->compare('<', $c->dateValue(time() - (int) $value * 86400))),
                'less_than_days_ago' => $c->compare('>=', $c->dateValue(time() - (int) $value * 86400)),
                'true'               => $c->compare('=', 1),
                'false'              => $c->orEmpty(fn () => $c->compare('=', 0)),
                'empty'              => $c->empty(),
                'not_empty'          => $c->notEmpty(),
            };
        }, null, null, $boolean);
    }

    /** The operators an event condition takes. */
    public const EVENT_OPERATORS = ['ever', 'never', 'at_least', 'at_most', 'last_within_days', 'not_within_days',
        'first_within_days', 'first_before', 'first_after', 'last_before', 'last_after'];

    /**
     * An event condition. Ever, never, counts of all time and first or last read the summary;
     * a count in the last days reads the log, as far back as it is kept.
     *
     * @param array{event: string, op: string, value?: mixed, days?: int} $condition
     */
    private function applyEvent(QueryBuilder $query, array $condition, string $boolean): void
    {
        $event = $condition['event'];
        $value = $condition['value'] ?? null;
        $days  = (int) ($condition['days'] ?? 0);
        $op    = $condition['op'];
        $summary = static fn (?callable $more = null): \Closure => static function (QueryBuilder $sub) use ($event, $more): void {
            $sub->table('#PREFIX#person_events AS person_events')->select([new Expression('1')])
                ->whereRaw('person_events.personid = users.userid')->where('person_events.event', $event);
            if ($more !== null) {
                $more($sub);
            }
        };
        if (($op === 'at_least' || $op === 'at_most') && $days > 0) {
            // How many in the window: a person with more than N rows there, or at least N.
            $since  = time() - $days * 86400;
            $window = static function (QueryBuilder $sub) use ($event, $since, $op, $value): void {
                $sub->table('#PREFIX#person_event_log AS person_event_log')->select([new Expression('1')])
                    ->whereRaw('person_event_log.personid = users.userid')->where('person_event_log.event', $event)
                    ->where('person_event_log.at', '>=', $since)->groupBy(['person_event_log.personid'])
                    ->havingRaw('COUNT(*) ' . ($op === 'at_least' ? '>=' : '>') . ' ?', [(int) $value]);
            };
            if ($op === 'at_least' && (int) $value <= 0) {
                $query->whereRaw('1 = 1', [], $boolean);   // at least none is everybody
            } else {
                $query->whereExists($window, $boolean, $op === 'at_most');
            }

            return;
        }
        $since = time() - (int) $value * 86400;
        match ($op) {
            'ever'              => $query->whereExists($summary(), $boolean),
            'never'             => $query->whereExists($summary(), $boolean, true),
            'at_least'          => (int) $value <= 0
                ? $query->whereRaw('1 = 1', [], $boolean)
                : $query->whereExists($summary(static fn ($q) => $q->where('person_events.occurrences', '>=', (int) $value)), $boolean),
            'at_most'           => $query->whereExists($summary(static fn ($q) => $q->where('person_events.occurrences', '>', (int) $value)), $boolean, true),
            'last_within_days'  => $query->whereExists($summary(static fn ($q) => $q->where('person_events.last_at', '>=', $since)), $boolean),
            'not_within_days'   => $query->whereExists($summary(static fn ($q) => $q->where('person_events.last_at', '>=', $since)), $boolean, true),
            'first_within_days' => $query->whereExists($summary(static fn ($q) => $q->where('person_events.first_at', '>=', $since)), $boolean),
            'first_before'      => $query->whereExists($summary(static fn ($q) => $q->where('person_events.first_at', '<', self::dayStart((string) $value))), $boolean),
            'first_after'       => $query->whereExists($summary(static fn ($q) => $q->where('person_events.first_at', '>=', self::dayStart((string) $value) + 86400)), $boolean),
            'last_before'       => $query->whereExists($summary(static fn ($q) => $q->where('person_events.last_at', '<', self::dayStart((string) $value))), $boolean),
            'last_after'        => $query->whereExists($summary(static fn ($q) => $q->where('person_events.last_at', '>=', self::dayStart((string) $value) + 86400)), $boolean),
        };
    }

    /** The sub-query "this person is in the segment now". */
    private static function inSegment(int $segment): \Closure
    {
        return static function (QueryBuilder $sub) use ($segment): void {
            $sub->table('#PREFIX#segment_members AS segment_members')->select([new Expression('1')])
                ->whereRaw('segment_members.userid = users.userid')
                ->where('segment_members.segmentid', $segment)
                ->whereNull('segment_members.left_at');
        };
    }

    /**
     * A one-to-many condition, as EXISTS so nobody is counted twice.
     *
     * @param list<int|string> $values
     */
    private function applyMany(QueryBuilder $query, Attribute $attribute, string $op, array $values, string $boolean): void
    {
        $exists = function (array $values) use ($attribute): \Closure {
            return function (QueryBuilder $sub) use ($attribute, $values): void {
                $alias = (string) $attribute->alias();
                $sub->table((string) $attribute->table())->select([new Expression('1')])
                    ->whereRaw($alias . '.' . $attribute->foreignKey() . ' = ' . $attribute->matchedWith());
                if ($values !== []) {
                    $sub->whereIn($alias . '.' . $attribute->valueColumn(), $values);
                }
            };
        };

        match ($op) {
            'any_of'    => $query->whereExists($exists($values), $boolean),
            'none_of'   => $query->whereExists($exists($values), $boolean, true),
            'empty'     => $query->whereExists($exists([]), $boolean, true),
            'not_empty' => $query->whereExists($exists([]), $boolean),
            'all_of'    => $query->where(function (QueryBuilder $q) use ($exists, $values): void {
                foreach ($values as $value) {
                    $q->whereExists($exists([$value]));
                }
            }, null, null, $boolean),
        };
    }

    /**
     * @param  array<string, mixed> $group
     * @return array{ok: bool, match: string, conditions: list<array<string, mixed>>}
     */
    private function explainGroup(array $group, int $personId): array
    {
        $items = [];
        foreach ($group['conditions'] as $condition) {
            $items[] = match (true) {
                isset($condition['conditions']) => $this->explainGroup($condition, $personId),
                isset($condition['segment'])    => $this->explainSegment($condition, $personId),
                isset($condition['event'])      => $this->explainEvent($condition, $personId),
                default                         => $this->explainCondition($condition, $personId),
            };
        }
        $oks = array_column($items, 'ok');
        $ok  = $items === [] || ($group['match'] === 'any' ? in_array(true, $oks, true) : !in_array(false, $oks, true));

        return ['ok' => $ok, 'match' => $group['match'], 'conditions' => $items];
    }

    /**
     * @param  array{attribute: string, op: string, value?: mixed} $condition
     * @return array<string, mixed>
     */
    private function explainCondition(array $condition, int $personId): array
    {
        $attribute = self::attribute($condition['attribute'], 'filter');
        $single    = new self(['match' => 'all', 'conditions' => [$condition]], $this->database);
        $query     = $this->database->queryBuilder()->table(Attributes::FROM)->where('users.userid', $personId);
        $single->joinFor($query, [$attribute]);
        $single->applyGroup($query, $single->tree);

        return $condition + [
            'label'  => $attribute->labelText(),
            'actual' => $this->valueOf($attribute, $personId),
            'ok'     => $query->select(['users.userid'])->limit(1)->getAllOrFail() !== [],
        ];
    }

    /**
     * @param  array{segment: int, op: string} $condition
     * @return array<string, mixed>
     */
    private function explainSegment(array $condition, int $personId): array
    {
        $member = $this->database->queryBuilder()->table(Attributes::FROM)->where('users.userid', $personId)
            ->whereExists(self::inSegment($condition['segment']))->select(['users.userid'])->limit(1)->getAllOrFail() !== [];
        $named  = $this->database->queryBuilder()->table('#PREFIX#segments')->select(['name'])
            ->where('segmentid', $condition['segment'])->limit(1)->getAllOrFail();

        return $condition + [
            'label'  => (string) ($named[0]['name'] ?? '#' . $condition['segment']),
            'actual' => $member,
            'ok'     => $member === ($condition['op'] === 'in'),
        ];
    }

    /**
     * An event condition for one person: ✓/✗, and what they did — how many times, the first and
     * the last, or how many in the window asked about.
     *
     * @param  array{event: string, op: string, value?: mixed, days?: int} $condition
     * @return array<string, mixed>
     */
    private function explainEvent(array $condition, int $personId): array
    {
        $query = $this->database->queryBuilder()->table(Attributes::FROM)->where('users.userid', $personId);
        $this->applyEvent($query, $condition, 'and');
        $row = $this->database->queryBuilder()->table('#PREFIX#person_events')->where('personid', $personId)
            ->where('event', $condition['event'])->limit(1)->getAllOrFail()[0] ?? null;
        $actual = ['count' => (int) ($row['occurrences'] ?? 0), 'first' => $row === null ? null : (int) $row['first_at'],
            'last' => $row === null ? null : (int) $row['last_at']];
        if (!empty($condition['days'])) {
            $actual['inWindow'] = (int) ($this->database->queryBuilder()->table('#PREFIX#person_event_log')
                ->select([new Expression('COUNT(*) AS n')])->where('personid', $personId)->where('event', $condition['event'])
                ->where('at', '>=', time() - (int) $condition['days'] * 86400)->getAllOrFail()[0]['n'] ?? 0);
        }

        return $condition + [
            'label'  => PersonEvents::label($condition['event']),
            'actual' => $actual,
            'ok'     => $query->select(['users.userid'])->limit(1)->getAllOrFail() !== [],
        ];
    }

    /** The value a person has: a scalar, or the list of a one-to-many. */
    private function valueOf(Attribute $attribute, int $personId): mixed
    {
        if ($attribute->type() === Attribute::MANY) {
            $alias = (string) $attribute->alias();
            $rows  = $this->database->queryBuilder()->table((string) $attribute->table())
                ->select([$alias . '.' . $attribute->valueColumn() . ' AS v'])
                ->whereExists(static fn (QueryBuilder $person) => $person->table(Attributes::FROM)
                    ->select([new Expression('1')])->where('users.userid', $personId)
                    ->whereRaw($alias . '.' . $attribute->foreignKey() . ' = ' . $attribute->matchedWith()))->getAllOrFail();

            return array_map(static fn (array $row): mixed => $row['v'], $rows);
        }
        $query = $this->database->queryBuilder()->table(Attributes::FROM)->where('users.userid', $personId);
        $this->joinFor($query, [$attribute]);
        $rows = $query->select([new Expression($attribute->sql($this->database->type) . ' AS v')])->limit(1)->getAllOrFail();

        return $rows[0]['v'] ?? null;
    }

    /**
     * Check and normalise a group.
     *
     * @param  array<string, mixed> $group
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    private static function group(array $group, string $path, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException("{$path}: groups nest at most " . self::MAX_DEPTH . ' deep.');
        }
        $match = $group['match'] ?? 'all';
        if ($match !== 'all' && $match !== 'any') {
            throw new \InvalidArgumentException("{$path}: match is all or any.");
        }
        $conditions = [];
        foreach (array_values((array) ($group['conditions'] ?? [])) as $i => $condition) {
            if (!is_array($condition)) {
                throw new \InvalidArgumentException("{$path}.{$i}: not a condition.");
            }
            $conditions[] = match (true) {
                isset($condition['conditions']) => self::group($condition, "{$path}.{$i}", $depth + 1),
                isset($condition['segment'])    => self::segmentCondition($condition, "{$path}.{$i}"),
                isset($condition['event'])      => self::eventCondition($condition, "{$path}.{$i}"),
                default                         => self::condition($condition, "{$path}.{$i}"),
            };
        }

        return ['match' => $match, 'conditions' => $conditions];
    }

    /**
     * Check and normalise one condition: the value cast to what the operator compares.
     *
     * @param  array<string, mixed> $condition
     * @return array{attribute: string, op: string, value?: mixed}
     */
    private static function condition(array $condition, string $path): array
    {
        $key       = (string) ($condition['attribute'] ?? '');
        $attribute = self::attribute($key, $path);
        $op        = (string) ($condition['op'] ?? '');
        if (!in_array($op, $attribute->operators(), true)) {
            throw new \InvalidArgumentException("{$path}: {$key} has no operator {$op}.");
        }
        if (in_array($op, ['empty', 'not_empty', 'true', 'false'], true)) {
            return ['attribute' => $key, 'op' => $op];
        }
        $value = $condition['value'] ?? null;
        $type  = $attribute->type();
        $one   = static function (mixed $value) use ($type, $path, $op): int|float|string {
            if (!is_scalar($value)) {
                throw new \InvalidArgumentException("{$path}: {$op} needs a value.");
            }
            if ($type === Attribute::NUMBER && !is_numeric($value)) {
                throw new \InvalidArgumentException("{$path}: {$value} is not a number.");
            }
            if (in_array($type, [Attribute::NUMBER, Attribute::LIST, Attribute::MANY], true) && is_numeric($value)) {
                return $value + 0;
            }

            return (string) $value;
        };

        $value = match (true) {
            in_array($op, ['one_of', 'not_one_of', 'any_of', 'all_of', 'none_of'], true) => self::nonEmptyList($value, $path, $op, $one),
            $op === 'between' && $type === Attribute::DATE => array_map(static fn ($d) => self::day($d, $path), self::pair($value, $path)),
            $op === 'between'                              => array_map($one, self::pair($value, $path)),
            $op === 'after' || $op === 'before'            => self::day($value, $path),
            str_ends_with($op, '_days_ago')                => self::days($value, $path),
            default                                        => $one($value),
        };

        return ['attribute' => $key, 'op' => $op, 'value' => $value];
    }

    /**
     * "In the segment" or "not in it".
     *
     * @param  array<string, mixed> $condition
     * @return array{segment: int, op: string}
     */
    private static function segmentCondition(array $condition, string $path): array
    {
        $segment = $condition['segment'];
        $op      = (string) ($condition['op'] ?? 'in');
        if (!is_numeric($segment) || (int) $segment < 1) {
            throw new \InvalidArgumentException("{$path}: a segment is named by its id.");
        }
        if ($op !== 'in' && $op !== 'not_in') {
            throw new \InvalidArgumentException("{$path}: a segment condition is in or not_in.");
        }

        return ['segment' => (int) $segment, 'op' => $op];
    }

    /**
     * "Did X": an event by name, an operator, and its value — a count with an optional window of
     * days, a number of days, or a day.
     *
     * @param  array<string, mixed> $condition
     * @return array{event: string, op: string, value?: int|string, days?: int}
     */
    private static function eventCondition(array $condition, string $path): array
    {
        $event = trim((string) $condition['event']);
        $op    = (string) ($condition['op'] ?? '');
        if ($event === '' || mb_strlen($event) > 100) {
            throw new \InvalidArgumentException("{$path}: an event is named, in at most 100 characters.");
        }
        if (!in_array($op, self::EVENT_OPERATORS, true)) {
            throw new \InvalidArgumentException("{$path}: an event has no operator {$op}.");
        }
        $checked = ['event' => $event, 'op' => $op];
        if ($op === 'ever' || $op === 'never') {
            return $checked;
        }
        $value = $condition['value'] ?? null;
        if (in_array($op, ['first_before', 'first_after', 'last_before', 'last_after'], true)) {
            return $checked + ['value' => self::day($value, $path)];
        }
        $counted = $op === 'at_least' || $op === 'at_most';
        if ($counted && (!is_numeric($value) || (int) $value != $value || (int) $value < 0)) {
            throw new \InvalidArgumentException("{$path}: a count is a whole number, zero or more.");
        }
        $checked['value'] = $counted ? (int) $value : self::days($value, $path);
        if ($counted && !empty($condition['days'])) {
            $checked['days'] = self::days($condition['days'], $path);
        }

        return $checked;
    }

    /** The attribute under a key, or a refusal naming where it was asked for. */
    private static function attribute(string $key, string $path): Attribute
    {
        return Attributes::get($key) ?? throw new \InvalidArgumentException("{$path}: no attribute {$key}.");
    }

    /** @return list<int|float|string> */
    private static function nonEmptyList(mixed $value, string $path, string $op, callable $one): array
    {
        if (!is_array($value) || $value === []) {
            throw new \InvalidArgumentException("{$path}: {$op} needs at least one value.");
        }

        return array_values(array_map($one, $value));
    }

    /** @return array{0: mixed, 1: mixed} */
    private static function pair(mixed $value, string $path): array
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new \InvalidArgumentException("{$path}: between needs two values.");
        }

        return array_values($value);
    }

    /** A day as `Y-m-d`. */
    private static function day(mixed $value, string $path): string
    {
        $day = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$path}: a day is written YYYY-MM-DD.");
        }

        return $value;
    }

    /** A whole number of days, zero or more. */
    private static function days(mixed $value, string $path): int
    {
        if (!is_numeric($value) || (int) $value != $value || (int) $value < 0) {
            throw new \InvalidArgumentException("{$path}: days are a whole number, zero or more.");
        }

        return (int) $value;
    }

    /** The first second of a day, in the application's time zone. */
    private static function dayStart(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 00:00:00'))->getTimestamp();
    }

    /** A LIKE pattern's own characters, escaped. */
    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
