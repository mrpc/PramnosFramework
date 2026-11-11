<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * Something a metric is broken down by — a region, a plan, a sales channel — declared once and
 * shared by every metric that names it.
 *
 * ```php
 * Metrics::dimension(Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South']));
 * Metrics::dimension(Dimension::of('plan', 'Plan')->values(fn (): array => Plan::labels()));
 * ```
 *
 * The values are what the screens and the assistant offer and how a stored value is named; a
 * value a source returns that is not listed is kept and shown as it is.
 */
final class Dimension
{
    /** @var array<string, string>|\Closure(): array<string, string> */
    private array|\Closure $values = [];

    /** The dimension this one is a part of — an area's region — or null. */
    private ?string $parent = null;

    /** Where a value is on a map, or null. */
    private ?\Closure $geo = null;

    /** @var (\Closure(): list<string>)|null The values a total across it counts; null: every one */
    private ?\Closure $base = null;

    /** The ability needed to break down or filter by it; empty for anybody who reads the metric. */
    private string $ability = '';

    private function __construct(public readonly string $name, public readonly string $label)
    {
    }

    /** A dimension by name — the key a source's rows carry — and the words for it. */
    public static function of(string $name, string $label = ''): self
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            throw new \InvalidArgumentException('A dimension\'s name is lower-case letters, digits and _: "' . $name . '" is not.');
        }

        return new self($name, $label !== '' ? $label : $name);
    }

    /**
     * The values it takes, `[value => label]`, or a function answering them when asked.
     *
     * @param array<string, string>|\Closure(): array<string, string> $values
     */
    public function values(array|\Closure $values): self
    {
        $this->read = null;
        $this->values = $values;

        return $this;
    }

    /** Seconds a closure's values are kept before it is asked again. */
    public const KEEP = 60;

    /** @var array{at: int, values: array<string, string>}|null A closure's values, as last read */
    private ?array $read = null;

    /**
     * The values, `[value => label]`. A closure's are read once and kept for {@see KEEP} seconds:
     * a report page asks for them once per card, and a dimension of thousands read from the
     * database each time cost a query per card.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if (!$this->values instanceof \Closure) {
            return array_map('strval', $this->values);
        }
        // ponytail: kept per object for KEEP seconds; a long-running worker sees a new value at most a minute late.
        if ($this->read === null || time() - $this->read['at'] >= self::KEEP) {
            $values     = ($this->values)();
            $this->read = ['at' => time(), 'values' => array_map('strval', is_array($values) ? $values : [])];
        }

        return $this->read['values'];
    }

    /**
     * The dimension this one is a part of: `Dimension::of('area')->parent('region')`. A reader
     * drills from a region to its areas by filtering on the region and breaking down by the area.
     */
    public function parent(string $dimension): self
    {
        $this->parent = $dimension;

        return $this;
    }

    /** The dimension this one is a part of, or null. */
    public function getParent(): ?string
    {
        return $this->parent;
    }

    /**
     * Where each value is, for a map: `fn (string $value): ?array => [$lat, $lng]`, or null where
     * a value has no place.
     */
    public function geo(\Closure $where): self
    {
        $this->geo = $where;

        return $this;
    }

    /** Whether its values have places on a map. */
    public function hasGeo(): bool
    {
        return $this->geo !== null;
    }

    /** Where a value is — `[lat, lng]` — or null. @return array{0: float, 1: float}|null */
    public function geoOf(string $value): ?array
    {
        $where = $this->geo !== null ? ($this->geo)($value) : null;

        return is_array($where) && isset($where[0], $where[1]) && is_numeric($where[0]) && is_numeric($where[1]) ? [(float) $where[0], (float) $where[1]] : null;
    }

    /**
     * Only accounts with this ability break down or filter by it — as an audience attribute's
     * `ability()` guards it — while the metric's totals stay theirs to read.
     */
    public function ability(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * The values a total across this dimension counts — the active channels that are not a
     * competitor's, where competitors are rows of the same table: `->base(fn () => Channels::ownIds())`.
     * A read that does not filter by the dimension reads only these; one that names a value reads it.
     *
     * @param \Closure(): list<string|int> $values
     */
    public function base(\Closure $values): self
    {
        $this->base = $values;

        return $this;
    }

    /** @return list<string>|null The values a total across it counts, or null for every one */
    public function baseValues(): ?array
    {
        return $this->base === null ? null : array_values(array_map('strval', (array) ($this->base)()));
    }

    /** Whether this person may break down or filter by it. */
    public function allows(?object $user): bool
    {
        return $this->ability === '' || \Pramnos\Auth\Gate::forUser($user)->check($this->ability);
    }

    /** One value's words: its label, or the value itself. */
    public function labelOf(string $value): string
    {
        return $this->all()[$value] ?? $value;
    }
}
