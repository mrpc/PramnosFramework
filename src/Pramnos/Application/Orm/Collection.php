<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm;

/**
 * Typed collection for OrmModel result sets.
 *
 * Wraps an array of objects (typically OrmModel instances) and exposes
 * functional helpers: filter, map, pluck, groupBy, each, etc.
 *
 * @package     PramnosFramework
 * @subpackage  Application\Orm
 * @template    T
 */
class Collection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var T[] */
    private array $items;

    /** @param T[] $items */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    // -------------------------------------------------------------------------
    // Inspection
    // -------------------------------------------------------------------------

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return T|null */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /** @return T|null */
    public function last(): mixed
    {
        return empty($this->items) ? null : $this->items[count($this->items) - 1];
    }

    public function contains(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Transformation (return new Collection, never mutate)
    // -------------------------------------------------------------------------

    /** @return static<T> */
    public function filter(callable $callback): static
    {
        return new static(array_values(array_filter($this->items, $callback)));
    }

    /** @return static */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Extract a single property/key from each item.
     *
     * @return static<mixed>
     */
    public function pluck(string $key): static
    {
        return new static(array_map(
            static fn($item) => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $this->items
        ));
    }

    /**
     * Group items by the value of $key. Returns a plain array keyed by
     * group value (not a nested Collection, to keep it simple).
     *
     * @return array<string|int, static<T>>
     */
    public function groupBy(string $key): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $groupKey = is_array($item) ? ($item[$key] ?? '') : ($item->$key ?? '');
            $groups[(string) $groupKey][] = $item;
        }
        return array_map(fn(array $g) => new static($g), $groups);
    }

    /** @return static<T> */
    public function sortBy(string $key, bool $descending = false): static
    {
        $copy = $this->items;
        usort($copy, static function ($a, $b) use ($key, $descending) {
            $va = is_array($a) ? ($a[$key] ?? null) : ($a->$key ?? null);
            $vb = is_array($b) ? ($b[$key] ?? null) : ($b->$key ?? null);
            return $descending ? ($vb <=> $va) : ($va <=> $vb);
        });
        return new static($copy);
    }

    /** Apply $callback to each item for side effects and return $this. */
    public function each(callable $callback): static
    {
        foreach ($this->items as $item) {
            $callback($item);
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Conversion
    // -------------------------------------------------------------------------

    /** @return T[] */
    public function all(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return array_map(
            static fn($item) => method_exists($item, 'toArray') ? $item->toArray() : (array) $item,
            $this->items
        );
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
