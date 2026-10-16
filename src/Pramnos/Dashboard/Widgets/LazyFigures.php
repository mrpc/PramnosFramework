<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

/**
 * Figures worked out only when something reads them — for a dashboard view of the older shape,
 * which reads `$this->healthResults['…']` or loops over it, while the cards of today read their own.
 *
 * Read as an array: an index (`$figures['now'] ?? 0`), a loop, a count. Nothing is asked until
 * then, so a dashboard that never reads them never runs the health checks behind them.
 *
 * @implements \ArrayAccess<string|int, mixed>
 * @implements \IteratorAggregate<string|int, mixed>
 */
final class LazyFigures implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var array<string|int, mixed>|null */
    private ?array $figures = null;

    /** @param \Closure(): array<string|int, mixed> $read What works the figures out */
    public function __construct(private readonly \Closure $read)
    {
    }

    /** @return array<string|int, mixed> The figures, worked out once */
    public function toArray(): array
    {
        return $this->figures ??= (array) ($this->read)();
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->toArray()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    /** Figures are read, not written. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Dashboard figures are read-only.');
    }

    /** Figures are read, not written. */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Dashboard figures are read-only.');
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->toArray());
    }

    public function count(): int
    {
        return count($this->toArray());
    }

    /** @return array<string|int, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
