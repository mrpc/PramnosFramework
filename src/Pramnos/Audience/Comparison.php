<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\QueryBuilder;

/**
 * One attribute compared inside one condition's parentheses: a column through the query
 * builder, an expression through `whereRaw()` with its value bound.
 *
 * @internal {@see Filter}
 */
final class Comparison
{
    private string $boolean = 'and';

    /** @param string $driver `mysql` or `postgresql` */
    public function __construct(
        private readonly QueryBuilder $query,
        private readonly Attribute $attribute,
        private readonly string $driver,
    ) {
    }

    /** `= <> > < >= <=` against one value. */
    public function compare(string $operator, mixed $value): void
    {
        if ($this->attribute->isExpression()) {
            $this->query->whereRaw($this->sql() . " {$operator} ?", [$value], $this->boolean);
        } else {
            $this->query->where($this->sql(), $operator, $value, $this->boolean);
        }
    }

    /**
     * One of the values, or none of them.
     *
     * @param list<mixed> $values
     */
    public function in(array $values, bool $not = false): void
    {
        if ($this->attribute->isExpression()) {
            $marks = implode(', ', array_fill(0, count($values), '?'));
            $this->query->whereRaw($this->sql() . ($not ? ' NOT IN ' : ' IN ') . "({$marks})", $values, $this->boolean);
        } else {
            $this->query->whereIn($this->sql(), $values, $this->boolean, $not);
        }
    }

    /** A LIKE pattern, ignoring case, or its negation. */
    public function like(string $pattern, bool $not = false): void
    {
        if (!$not && !$this->attribute->isExpression()) {
            $this->query->whereLike($this->sql(), $pattern, $this->boolean);

            return;
        }
        $like = ($not ? 'NOT ' : '') . ($this->driver === 'postgresql' ? 'ILIKE' : 'LIKE');
        if ($this->attribute->isExpression()) {
            $this->query->whereRaw($this->sql() . " {$like} ?", [$pattern], $this->boolean);
        } else {
            $this->query->where($this->sql(), $like, $pattern, $this->boolean);
        }
    }

    /** From one value to another, both included. */
    public function between(mixed $from, mixed $to): void
    {
        $this->compare('>=', $from);
        $this->compare('<=', $to);
    }

    /** No value: NULL, and also '' for text and 0 for a date kept as seconds. */
    public function empty(): void
    {
        $this->compareNull(false);
        $blank = $this->blank();
        if ($blank !== null) {
            $this->or(fn () => $this->compare('=', $blank));
        }
    }

    /** A value. */
    public function notEmpty(): void
    {
        $this->compareNull(true);
        $blank = $this->blank();
        if ($blank !== null) {
            $this->compare('<>', $blank);
        }
    }

    /** What `$compare` adds, or no value — "is not X" includes the people with none. */
    public function orEmpty(callable $compare): void
    {
        $compare();
        $this->or(fn () => $this->empty());
    }

    /** A value, and what `$compare` adds — "before X" leaves out the people with none. */
    public function notEmptyAnd(callable $compare): void
    {
        $this->notEmpty();
        $compare();
    }

    /** A moment as the attribute stores it. */
    public function dateValue(int $timestamp): int|string
    {
        return match ($this->attribute->dateFormat()) {
            'datetime' => date('Y-m-d H:i:s', $timestamp),
            'date'     => date('Y-m-d', $timestamp),
            default    => $timestamp,
        };
    }

    /** Run `$add` with its clauses joined by OR. */
    private function or(callable $add): void
    {
        $this->boolean = 'or';
        $add();
        $this->boolean = 'and';
    }

    private function compareNull(bool $not): void
    {
        if ($this->attribute->isExpression()) {
            $this->query->whereRaw($this->sql() . ($not ? ' IS NOT NULL' : ' IS NULL'), [], $this->boolean);
        } else {
            $this->query->whereNull($this->sql(), $this->boolean, $not);
        }
    }

    /** The stored value that also means "none", if the type has one. */
    private function blank(): int|string|null
    {
        return match ($this->attribute->type()) {
            Attribute::TEXT => '',
            Attribute::DATE => $this->attribute->dateFormat() === 'unix' ? 0 : null,
            default         => null,
        };
    }

    private function sql(): string
    {
        return $this->attribute->sql($this->driver);
    }
}
