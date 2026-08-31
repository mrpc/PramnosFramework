<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * The conditions of one JOIN, when there is more than one of them.
 *
 * `join($table, $first, $operator, $second)` covers the common case and reads
 * well for it. It cannot express a join on two columns, which is what a
 * composite key or a scoped membership table needs:
 *
 * ```sql
 * LEFT JOIN user_organizations uo
 *        ON uo.userid = ur.userid
 *       AND uo.organization_id = rd.organization_id
 * ```
 *
 * Passing a closure instead of `$first` hands it one of these:
 *
 * ```php
 * $qb->leftJoin('authserver.user_organizations uo', function (JoinClause $join) {
 *     $join->on('uo.userid', '=', 'ur.userid')
 *          ->on('uo.organization_id', '=', 'rd.organization_id');
 * });
 * ```
 *
 * Both sides of every condition are **column references**, never values — that
 * is what a JOIN condition is. There is deliberately no `where()` here: a
 * comparison against a value belongs in the query's WHERE clause, where it is
 * bound as a parameter. Putting one here would mean interpolating it into the
 * ON clause, and a join builder that quietly accepts user input is the shape of
 * bug this class exists to avoid.
 */
class JoinClause
{
    /**
     * The collected conditions.
     *
     * @var list<array{first:string,operator:string,second:string,boolean:string}>
     */
    protected array $conditions = [];

    /**
     * Add a condition, ANDed with the ones before it.
     *
     * @param string      $first    Left-hand column reference (e.g. `uo.userid`).
     * @param string|null $operator Comparison operator; defaults to `=` when the
     *                              two-argument form is used.
     * @param string|null $second   Right-hand column reference.
     * @return $this
     */
    public function on(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->addCondition($first, $operator, $second, 'and');
    }

    /**
     * Add a condition, ORed with the ones before it.
     *
     * @param string      $first    Left-hand column reference.
     * @param string|null $operator Comparison operator.
     * @param string|null $second   Right-hand column reference.
     * @return $this
     */
    public function orOn(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->addCondition($first, $operator, $second, 'or');
    }

    /**
     * The conditions collected so far, in the order they were added.
     *
     * @return list<array{first:string,operator:string,second:string,boolean:string}>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Record one condition, filling in `=` for the two-argument form.
     *
     * @param string      $first
     * @param string|null $operator
     * @param string|null $second
     * @param string      $boolean 'and' | 'or'
     * @return $this
     */
    protected function addCondition(
        string $first,
        ?string $operator,
        ?string $second,
        string $boolean
    ): static {
        // on('a.x', 'b.y') — the operator was omitted, not the column.
        if ($second === null) {
            $second   = (string) $operator;
            $operator = '=';
        }

        $this->conditions[] = [
            'first'    => $first,
            'operator' => (string) $operator,
            'second'   => $second,
            'boolean'  => $boolean,
        ];

        return $this;
    }
}
