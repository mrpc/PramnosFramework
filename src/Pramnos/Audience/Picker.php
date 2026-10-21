<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;

/**
 * A send's audience as an administrator picks it: in any of these segments and groups, except
 * these, narrowed by a filter for this send alone, and the consent list it respects.
 *
 * ```php
 * $state = [
 *     'include' => [['segment' => 4], ['group' => 2]],   // in any of these
 *     'exclude' => [['segment' => 9]],                   // and none of these
 *     'filter'  => ['conditions' => [...]],              // and this, for this send alone
 *     'consent' => 3,                                    // and in this consent list
 * ];
 * Picker::final($state)->ids();      // who it goes to
 * Picker::counts($state);            // ['included' => 4416, 'excluded' => 812, 'consent' => 640, 'final' => 2964]
 * ```
 *
 * Stored as this state, not as the people it meant, so a send that waits for its hour reaches the
 * audience as it is then. Everything compiles to one {@see Filter}, so the counts, the sample and
 * the send read the same query.
 */
final class Picker
{
    /**
     * The state checked and shaped: segments and groups by id, the filter checked, the consent
     * list an id or null.
     *
     * @param  array<string, mixed>|string|null $state Or its JSON
     * @return array{include: list<array{segment?: int, group?: int}>, exclude: list<array{segment?: int, group?: int}>, filter: array<string, mixed>, consent: ?int}
     * @throws \InvalidArgumentException
     */
    public static function normalise(array|string|null $state): array
    {
        if (is_string($state)) {
            $state = $state === '' ? [] : json_decode($state, true);
            if (!is_array($state)) {
                throw new \InvalidArgumentException('The audience is not valid JSON.');
            }
        }
        $state ??= [];
        $consent = $state['consent'] ?? null;

        return [
            'include' => self::picks($state['include'] ?? [], 'include'),
            'exclude' => self::picks($state['exclude'] ?? [], 'exclude'),
            'filter'  => Filter::fromArray((array) ($state['filter'] ?? []), self::db())->toArray(),
            'consent' => $consent === null || $consent === '' || (int) $consent === 0 ? null : (int) $consent,
        ];
    }

    /**
     * Who is picked before anything is taken out: in any of the included, and the filter.
     *
     * @param array<string, mixed>|string|null $state
     */
    public static function included(array|string|null $state): Filter
    {
        $state      = self::normalise($state);
        $conditions = [];
        if ($state['include'] !== []) {
            $conditions[] = ['match' => 'any', 'conditions' => array_map(self::condition(...), $state['include'])];
        }
        if ($state['filter']['conditions'] !== []) {
            $conditions[] = $state['filter'];
        }

        return Filter::fromArray(['match' => 'all', 'conditions' => $conditions], self::db());
    }

    /**
     * The picked, without the excluded — the audience before consent.
     *
     * @param array<string, mixed>|string|null $state
     */
    public static function audience(array|string|null $state): Filter
    {
        $state      = self::normalise($state);
        $conditions = [self::included($state)->toArray()];
        foreach ($state['exclude'] as $pick) {
            $conditions[] = self::condition($pick, true);
        }

        return Filter::fromArray(['match' => 'all', 'conditions' => $conditions], self::db());
    }

    /**
     * Who the send reaches: the audience in its consent list.
     *
     * @param array<string, mixed>|string|null $state
     */
    public static function final(array|string|null $state): Filter
    {
        $state = self::normalise($state);

        return $state['consent'] === null ? self::audience($state) : ConsentLists::narrow(self::audience($state), $state['consent']);
    }

    /**
     * The picker's numbers: picked, taken out by the exceptions, taken out by consent, and
     * reached.
     *
     * @param  array<string, mixed>|string|null $state
     * @return array{included: int, excluded: int, consent: int, final: int}
     */
    public static function counts(array|string|null $state): array
    {
        $state    = self::normalise($state);
        $included = self::included($state)->count();
        $audience = $state['exclude'] === [] ? $included : self::audience($state)->count();
        $final    = $state['consent'] === null ? $audience : self::final($state)->count();

        return ['included' => $included, 'excluded' => $included - $audience, 'consent' => $audience - $final, 'final' => $final];
    }

    /**
     * A pick as a condition: in the segment, or in the group — or, excluded, not.
     *
     * @param  array{segment?: int, group?: int} $pick
     * @return array<string, mixed>
     */
    private static function condition(array $pick, bool $not = false): array
    {
        if (isset($pick['segment'])) {
            return ['segment' => $pick['segment'], 'op' => $not ? 'not_in' : 'in'];
        }

        return ['attribute' => 'groups', 'op' => $not ? 'none_of' : 'any_of', 'value' => [(int) $pick['group']]];
    }

    /**
     * @param  mixed $picks
     * @return list<array{segment?: int, group?: int}>
     */
    private static function picks(mixed $picks, string $path): array
    {
        $checked = [];
        foreach (array_values((array) $picks) as $i => $pick) {
            $kind = is_array($pick) ? (isset($pick['segment']) ? 'segment' : (isset($pick['group']) ? 'group' : null)) : null;
            $id   = $kind === null ? 0 : (int) $pick[$kind];
            if ($kind === null || $id < 1) {
                throw new \InvalidArgumentException("{$path}.{$i}: a segment or a group, by id.");
            }
            $checked[] = [$kind => $id];
        }

        return array_values(array_unique($checked, SORT_REGULAR));
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
