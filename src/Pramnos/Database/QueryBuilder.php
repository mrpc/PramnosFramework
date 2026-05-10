<?php

namespace Pramnos\Database;

use Pramnos\Database\Grammar\GrammarInterface;
use Pramnos\Database\Grammar\MySQLGrammar;
use Pramnos\Database\Grammar\PostgreSQLGrammar;
use Pramnos\Database\Grammar\TimescaleDBGrammar;

/**
 * Fluent Query Builder for DML operations.
 * Supports multiple dialects (MySQL, PostgreSQL, TimescaleDB).
 *
 * SQL compilation is delegated to a Grammar instance so dialect-specific
 * logic lives in one place rather than scattered if-checks.
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class QueryBuilder
{
    /**
     * @var Database
     */
    protected $db;

    /**
     * @var GrammarInterface
     */
    protected $grammar;

    /**
     * @var string
     */
    protected $type = 'SELECT';

    /**
     * @var array
     */
    protected $columns = ['*'];

    /**
     * @var bool
     */
    protected $distinct = false;

    /**
     * @var string
     */
    protected $from;

    /**
     * @var array
     */
    protected $joins = [];

    /**
     * @var array
     */
    protected $wheres = [];

    /**
     * @var array
     */
    protected $groups = [];

    /**
     * @var array
     */
    protected $havings = [];

    /**
     * @var array
     */
    protected $orders = [];

    /**
     * @var int
     */
    protected $limit;

    /**
     * @var int
     */
    protected $offset;

    /**
     * @var array
     */
    protected $bindings = [
        'cte'    => [],   // bindings from CTE sub-queries — must precede main query bindings
        'select' => [],
        'from'   => [],
        'join'   => [],
        'where'  => [],
        'having' => [],
        'order'  => [],
        'values' => [],
    ];

    /**
     * @var array
     */
    protected $returning = [];

    /**
     * @var array
     */
    protected $unions = [];

    /**
     * Common Table Expressions — each entry: ['name'=>string, 'query'=>string, 'recursive'=>bool]
     * @var array
     */
    protected $ctes = [];

    /**
     * Row-locking mode: 'update' (FOR UPDATE), 'share' (FOR SHARE / LOCK IN SHARE MODE), or null.
     * @var string|null
     */
    protected ?string $lock = null;

    /**
     * Constructor
     *
     * @param Database          $db
     * @param GrammarInterface|null $grammar  Override the auto-detected grammar (useful in tests).
     */
    public function __construct(Database $db, ?GrammarInterface $grammar = null)
    {
        $this->db      = $db;
        $this->grammar = $grammar ?? $this->makeGrammar();
    }

    /**
     * Instantiate the Grammar matching the current database driver.
     */
    private function makeGrammar(): GrammarInterface
    {
        if ($this->db->type === 'postgresql') {
            return $this->db->timescale
                ? new TimescaleDBGrammar()
                : new PostgreSQLGrammar();
        }
        return new MySQLGrammar();
    }

    /**
     * Replace the active grammar (useful for testing or custom dialects).
     */
    public function setGrammar(GrammarInterface $grammar): self
    {
        $this->grammar = $grammar;
        return $this;
    }

    /**
     * Return the active grammar.
     */
    public function getGrammar(): GrammarInterface
    {
        return $this->grammar;
    }

    // -------------------------------------------------------------------------
    // State accessors (used by Grammar — read-only view of builder state)
    // -------------------------------------------------------------------------

    public function getFrom(): ?string          { return $this->from; }
    public function getColumns(): array         { return $this->columns; }
    public function isDistinct(): bool          { return $this->distinct; }
    public function getJoins(): array           { return $this->joins; }
    public function getWheres(): array          { return $this->wheres; }
    public function getGroups(): array          { return $this->groups; }
    public function getHavings(): array         { return $this->havings; }
    public function getOrders(): array          { return $this->orders; }
    public function getLimit(): ?int            { return $this->limit ?? null; }
    public function getOffset(): ?int           { return $this->offset ?? null; }
    public function getUnions(): array          { return $this->unions; }
    public function getReturning(): array       { return $this->returning; }
    public function getCtes(): array            { return $this->ctes; }
    public function getLock(): ?string          { return $this->lock; }

    /**
     * Create a raw SQL expression.
     *
     * @param string $value
     * @return Expression
     */
    public function raw($value)
    {
        return new Expression($value);
    }

    /**
     * Return a dialect-appropriate time-bucket expression.
     *
     * The returned Expression can be used anywhere a column reference is accepted:
     * as a SELECT column, in GROUP BY, ORDER BY, or WHERE clauses.
     *
     *   $qb->select([$qb->timeBucket('1 hour', 'recorded_at'), 'AVG(value)'])
     *      ->groupBy([$qb->timeBucket('1 hour', 'recorded_at')])
     *
     * Dialect translation:
     *   TimescaleDB  → time_bucket('1 hour', recorded_at)
     *   PostgreSQL   → date_trunc('hour', recorded_at)
     *   MySQL        → FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600)
     *
     * @param  string          $interval  Human-readable interval: "1 hour", "15 minutes", etc.
     * @param  string|Expression $column  Column name or Expression
     * @return Expression
     */
    public function timeBucket(string $interval, $column): Expression
    {
        $col = $column instanceof Expression ? (string)$column : $column;
        return $this->raw($this->grammar->compileTimeBucket($interval, $col));
    }

    /**
     * Set columns to select.
     * 
     * @param array|mixed $columns
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $this->type = 'SELECT';
        $this->columns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    /**
     * Force the query to only return distinct results.
     * 
     * @return $this
     */
    public function distinct()
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Set the table to query from.
     * 
     * @param string $table
     * @return $this
     */
    public function from($table)
    {
        // On MySQL, schema.table notation (e.g. authserver.foo) is a cross-database
        // reference rather than a schema namespace. Resolve it so authserver.foo
        // becomes prefix_authserver_foo within the current database.
        // Guards:
        //  - PostgreSQL/TimescaleDB support schema.table natively — no transformation.
        //  - Aliased expressions contain a space (e.g. "tbl a") — skip to preserve alias.
        //  - Subquery fragments from fromSub() bypass from() entirely — not a concern.
        if (is_string($table)
            && strpos($table, '.') !== false
            && strpos($table, ' ') === false
            && $this->db->type !== 'postgresql'
        ) {
            $table = $this->db->schema()->resolveTableName($table);
        }
        $this->from = $table;
        return $this;
    }

    /**
     * Alias for from().
     * 
     * @param string $table
     * @return $this
     */
    public function table($table)
    {
        return $this->from($table);
    }

    /**
     * Add a where clause.
     * 
     * @param string|callable $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column instanceof \Closure) {
            return $this->whereNested($column, $boolean);
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'Basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add an "or where" clause.
     *
     * @param string|callable $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a "where in" clause.
     * 
     * @param string $column
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereIn($column, array $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';
        $this->wheres[] = compact('type', 'column', 'values', 'boolean');
        $this->addBinding($values, 'where');
        return $this;
    }

    /**
     * Add a "where null" clause.
     *
     * @param string $column
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';
        $this->wheres[] = compact('type', 'column', 'boolean');
        return $this;
    }

    /**
     * Add a "where not null" clause.
     *
     * @param string $column
     * @param string $boolean
     * @return $this
     */
    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add an "or where null" clause.
     *
     * @param string $column
     * @return $this
     */
    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * Add an "or where not null" clause.
     *
     * @param string $column
     * @return $this
     */
    public function orWhereNotNull($column)
    {
        return $this->whereNull($column, 'or', true);
    }

    /**
     * Add a "where between" clause.
     *
     * @param string $column
     * @param array $values  Two-element array: [min, max]
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotBetween' : 'Between';
        $this->wheres[] = compact('type', 'column', 'values', 'boolean');
        $this->addBinding($values[0], 'where');
        $this->addBinding($values[1], 'where');
        return $this;
    }

    /**
     * Add a "where not between" clause.
     *
     * @param string $column
     * @param array $values  Two-element array: [min, max]
     * @param string $boolean
     * @return $this
     */
    public function whereNotBetween($column, array $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add an "or where between" clause.
     *
     * @param string $column
     * @param array $values
     * @return $this
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * Add an "or where not between" clause.
     *
     * @param string $column
     * @param array $values
     * @return $this
     */
    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'or', true);
    }

    /**
     * Add a raw where clause.
     *
     * @param string $sql
     * @param array $bindings
     * @param string $boolean
     * @return $this
     */
    public function whereRaw($sql, array $bindings = [], $boolean = 'and')
    {
        if ($sql === '' || $sql === null) {
            return $this;
        }

        $this->wheres[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'boolean' => $boolean
        ];

        if (!empty($bindings)) {
            $this->addBinding($bindings, 'where');
        }

        return $this;
    }

    /**
     * Add a nested where clause.
     * 
     * @param \Closure $callback
     * @param string $boolean
     * @return $this
     */
    protected function whereNested(\Closure $callback, $boolean = 'and')
    {
        $query = new static($this->db);
        $callback($query);

        $this->wheres[] = [
            'type' => 'Nested',
            'query' => $query,
            'boolean' => $boolean
        ];

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add a join clause.
     * 
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @param string $type
     * @return $this
     */
    public function join($table, $first, $operator = null, $second = null, $type = 'inner')
    {
        if (is_string($table)
            && strpos($table, '.') !== false
            && strpos($table, ' ') === false
            && $this->db->type !== 'postgresql'
        ) {
            $table = $this->db->schema()->resolveTableName($table);
        }
        $this->joins[] = compact('table', 'first', 'operator', 'second', 'type');
        return $this;
    }

    /**
     * Add a raw join clause.
     * 
     * @param string $sql
     * @return $this
     */
    public function joinRaw($sql)
    {
        $this->joins[] = [
            'type' => 'Raw',
            'sql' => $sql
        ];
        return $this;
    }

    /**
     * Add a left join clause.
     *
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @return $this
     */
    public function leftJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a right join clause.
     *
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @return $this
     */
    public function rightJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a cross join clause (no ON condition).
     *
     * @param string $table
     * @return $this
     */
    public function crossJoin($table)
    {
        if (is_string($table)
            && strpos($table, '.') !== false
            && strpos($table, ' ') === false
            && $this->db->type !== 'postgresql'
        ) {
            $table = $this->db->schema()->resolveTableName($table);
        }
        $this->joins[] = ['table' => $table, 'type' => 'cross'];
        return $this;
    }

    /**
     * Add an order by clause.
     * 
     * @param string $column
     * @param string $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->orders[] = compact('column', 'direction');
        return $this;
    }

    /**
     * Add a raw order by clause.
     * 
     * @param string $sql
     * @return $this
     */
    public function orderByRaw($sql)
    {
        $this->orders[] = [
            'type' => 'Raw',
            'sql' => $sql
        ];
        return $this;
    }

    /**
     * Add a group by clause.
     * 
     * @param array|string $columns
     * @return $this
     */
    public function groupBy($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->groups = array_merge($this->groups, $columns);
        return $this;
    }

    /**
     * Add a raw group by clause.
     * 
     * @param string $sql
     * @return $this
     */
    public function groupByRaw($sql)
    {
        $this->groups[] = $this->raw($sql);
        return $this;
    }

    /**
     * Add a having clause.
     * 
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = compact('column', 'operator', 'value', 'boolean');
        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * Add a raw having clause.
     * 
     * @param string $sql
     * @param array $bindings
     * @param string $boolean
     * @return $this
     */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        $this->havings[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'boolean' => $boolean
        ];

        if (!empty($bindings)) {
            $this->addBinding($bindings, 'having');
        }

        return $this;
    }

    /**
     * Add a returning clause.
     * 
     * @param array|string $columns
     * @return $this
     */
    public function returning($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->returning = array_merge($this->returning, $columns);
        return $this;
    }

    /**
     * Set the limit.
     * 
     * @param int $value
     * @return $this
     */
    public function limit($value)
    {
        $this->limit = $value;
        return $this;
    }

    /**
     * Set the offset.
     *
     * @param int $value
     * @return $this
     */
    public function offset($value)
    {
        $this->offset = $value;
        return $this;
    }

    /**
     * Remove all ORDER BY, LIMIT, and OFFSET clauses.
     * Useful when building a COUNT subquery from a cloned builder.
     *
     * @return $this
     */
    public function clearOrderingAndPaging()
    {
        $this->orders = [];
        unset($this->limit);
        unset($this->offset);
        $this->bindings['order'] = [];
        return $this;
    }

    /**
     * Order by the given column descending — sugar for orderBy($col, 'desc').
     *
     * @param string $col
     * @return $this
     */
    public function latest(string $col = 'created_at')
    {
        return $this->orderBy($col, 'desc');
    }

    /**
     * Order by the given column ascending — sugar for orderBy($col, 'asc').
     *
     * @param string $col
     * @return $this
     */
    public function oldest(string $col = 'created_at')
    {
        return $this->orderBy($col, 'asc');
    }

    /**
     * Set LIMIT and OFFSET for a given page number.
     * Pages are 1-indexed: page 1 starts at offset 0.
     *
     * @param int $page     1-based page number
     * @param int $perPage  Rows per page
     * @return $this
     */
    public function forPage(int $page, int $perPage)
    {
        return $this->limit($perPage)->offset(max(0, $page - 1) * $perPage);
    }

    /**
     * Add a UNION clause.
     *
     * @param QueryBuilder $query
     * @param bool $all  true for UNION ALL, false for UNION (deduplicates)
     * @return $this
     */
    public function union(QueryBuilder $query, $all = false)
    {
        $this->unions[] = compact('query', 'all');
        return $this;
    }

    /**
     * Add a UNION ALL clause (preserves duplicates).
     *
     * @param QueryBuilder $query
     * @return $this
     */
    public function unionAll(QueryBuilder $query)
    {
        return $this->union($query, true);
    }

    // -------------------------------------------------------------------------
    // CTEs — WITH / WITH RECURSIVE
    // -------------------------------------------------------------------------

    /**
     * Add a Common Table Expression (CTE) to the query.
     *
     * The CTE will be prepended as WITH name AS (...) before the main SELECT.
     * Multiple calls chain additional CTEs in declaration order.
     *
     *   $qb->with('ranked', function (QueryBuilder $sub) {
     *       $sub->select(['id', 'ROW_NUMBER() OVER (ORDER BY score DESC) AS rn'])
     *           ->from('entries');
     *   })->select('*')->from('ranked')->where('rn', '<=', 10);
     *
     * @param  string                        $name      CTE name (bare identifier)
     * @param  QueryBuilder|callable|string  $query     Sub-builder, closure, or raw SQL string
     * @param  bool                          $recursive true = WITH RECURSIVE
     * @return $this
     */
    public function with(string $name, $query, bool $recursive = false): self
    {
        if ($query instanceof \Closure) {
            $sub = new static($this->db, $this->grammar);
            ($query)($sub);
            $query = $sub;
        }

        if ($query instanceof QueryBuilder) {
            // Merge the sub-query's bindings into our own 'cte' slot so that
            // Database::prepare() finds the values in the correct left-to-right
            // order (CTE SQL is emitted before the main SELECT).
            foreach ($query->getBindings() as $binding) {
                $this->bindings['cte'][] = $binding;
            }
            $sql = $this->grammar->compileSelect($query);
        } else {
            $sql = (string)$query;
        }

        $this->ctes[] = ['name' => $name, 'sql' => $sql, 'recursive' => $recursive];
        return $this;
    }

    /**
     * Add a recursive CTE (WITH RECURSIVE name AS …).
     *
     * @param  string                       $name
     * @param  QueryBuilder|callable|string $query
     * @return $this
     */
    public function withRecursive(string $name, $query): self
    {
        return $this->with($name, $query, true);
    }

    // -------------------------------------------------------------------------
    // Subqueries — selectSub() / fromSub()
    // -------------------------------------------------------------------------

    /**
     * Materialise a sub-query parameter into a [sql, bindings] pair.
     *
     * Accepts a Closure (which receives a fresh QueryBuilder) or an already-built
     * QueryBuilder instance.  The grammar from the outer builder is reused so
     * both share the same dialect.
     *
     * @param  QueryBuilder|\Closure $query
     * @return array{0:string,1:array}  [$wrappedSql, $bindings]
     */
    private function createSub($query): array
    {
        if ($query instanceof \Closure) {
            $sub = new static($this->db, $this->grammar);
            ($query)($sub);
            $query = $sub;
        }

        $sql = '(' . str_replace('#PREFIX#', $this->db->prefix, $this->grammar->compileSelect($query)) . ')';
        return [$sql, $query->getBindings()];
    }

    /**
     * Add a correlated or uncorrelated subquery as a SELECT column.
     *
     * The subquery is wrapped in parentheses and aliased:
     *   SELECT ..., (SELECT ...) AS alias
     *
     * The default '*' is dropped when this is the first explicit column added.
     * Bindings from the subquery go into the 'select' slot so they appear
     * before WHERE bindings when Database::prepare() flattens the list.
     *
     * @param  QueryBuilder|\Closure $query
     * @param  string                $alias  Column alias for the sub-result
     * @return $this
     */
    public function selectSub($query, string $alias): static
    {
        [$sql, $bindings] = $this->createSub($query);

        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns[] = new Expression($sql . ' AS ' . $this->grammar->quoteColumn($alias));

        foreach ($bindings as $b) {
            $this->bindings['select'][] = $b;
        }

        return $this;
    }

    /**
     * Use a subquery as the FROM source (derived table).
     *
     * The subquery is wrapped in parentheses and given a table alias:
     *   FROM (SELECT ...) AS alias
     *
     * Bindings from the subquery go into the 'from' slot — they appear after
     * 'select' bindings but before 'join' / 'where' bindings in the
     * flattened list used by Database::prepare().
     *
     * @param  QueryBuilder|\Closure $query
     * @param  string                $alias  Alias for the derived table
     * @return $this
     */
    public function fromSub($query, string $alias): static
    {
        [$sql, $bindings] = $this->createSub($query);
        $this->from = $sql . ' AS ' . $alias;

        foreach ($bindings as $b) {
            $this->bindings['from'][] = $b;
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Window functions — over()
    // -------------------------------------------------------------------------

    /**
     * Build an OVER (...) window function expression.
     *
     * Returns an Expression that can be passed to select(), groupBy(), orderBy(),
     * or used inside a CTE sub-query.  The partition and order columns are quoted
     * automatically by the active grammar, so backtick / double-quote quoting is
     * handled transparently.
     *
     * @param  string|Expression  $function   The function call fragment, e.g. 'RANK()',
     *                                         'ROW_NUMBER()', 'SUM(price)', 'LAG(score, 1)'.
     *                                         Passed verbatim — not escaped.
     * @param  string|null        $alias       Optional AS alias appended to the expression.
     * @param  array|string       $partition   Column(s) for PARTITION BY.  Quoted automatically.
     *                                         Pass a string for a single column or an array for multiple.
     * @param  array              $order       ORDER BY spec.
     *                                         Associative: ['col' => 'asc|desc', ...]
     *                                         Indexed:     ['col1', 'col2'] (defaults to ASC)
     * @param  string             $frame       Optional ROWS/RANGE frame clause, e.g.
     *                                         'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'.
     * @return Expression
     */
    public function over(
        string|Expression $function,
        ?string $alias = null,
        array|string $partition = [],
        array $order = [],
        string $frame = ''
    ): Expression {
        $fn  = (string)$function;
        $sql = $this->grammar->compileWindowOver($fn, (array)$partition, $order, $frame);

        if ($alias !== null) {
            $sql .= ' AS ' . $this->grammar->quoteColumn($alias);
        }

        return new Expression($sql);
    }

    /**
     * Add a binding value.
     *
     * @param mixed $value
     * @param string $type
     * @return $this
     */
    protected function addBinding($value, $type = 'where')
    {
        if (!isset($this->bindings[$type])) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}");
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                $this->bindings[$type][] = $v;
            }
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    /**
     * Compile the query into SQL.
     *
     * SELECT and DELETE are supported; INSERT and UPDATE are not compilable
     * without the values array — use insert() / update() directly.
     *
     * @return string
     */
    public function toSql(): string
    {
        if ($this->type === 'DELETE') {
            return str_replace('#PREFIX#', $this->db->prefix, $this->grammar->compileDelete($this));
        }
        return str_replace('#PREFIX#', $this->db->prefix, $this->grammar->compileSelect($this));
    }

    /**
     * Execute the query and return the result.
     * 
     * @return Result
     */
    public function get($cache = false, $cachetime = 60, $category = "")
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();

        if ($cache) {
            $cacheKey = $sql . serialize($bindings);
            $cacheData = $this->db->cacheRead($cacheKey, $category);
            
            if ($cacheData !== false) {
                $obj = new \Pramnos\Database\Result($this->db);
                $obj->cursor = -1;
                $obj->isCached = true;
                $obj->result = $cacheData;
                $obj->numRows = is_array($cacheData) ? count($cacheData) : 0;
                
                if ($obj->numRows > 0) {
                    $obj->eof = false;
                    if (isset($cacheData[0]) && is_array($cacheData[0])) {
                        foreach ($cacheData[0] as $key => $value) {
                            $obj->fields[$key] = $value;
                        }
                    }
                } else {
                    $obj->eof = true;
                }
                return $obj;
            }
        }

        $result = $this->db->execute($sql, ...$bindings);

        if ($cache && $result) {
            $data = $result->fetchAll();
            $result->result = $data;
            $result->isCached = true;
            $result->cursor = -1;
            $result->numRows = count($data);
            
            if ($result->numRows > 0 && isset($data[0])) {
                $result->fields = $data[0];
                $result->eof = false;
            }

            if ($this->db->shouldCacheResult($data)) {
                $cacheKey = $sql . serialize($bindings);
                $this->db->cacheStore($cacheKey, $data, $category, $cachetime);
            }
        }

        return $result;
    }



    /**
     * Add LIMIT 1 and execute. Returns the Result object (check numRows before accessing fields).
     *
     * @return Result
     */
    public function first()
    {
        $this->limit(1);
        return $this->get();
    }

    /**
     * Execute a COUNT(*) aggregate and return the row count as an integer.
     *
     * Clones the builder (preserving WHERE, JOIN, GROUP BY, HAVING, and their bindings),
     * replaces SELECT with COUNT(*) AS aggregate, and strips ORDER BY / LIMIT / OFFSET
     * since they are meaningless for aggregate queries.
     *
     * @return int
     */
    public function count(): int
    {
        $counter = clone $this;
        $counter->select(['COUNT(*) AS aggregate'])
                ->clearOrderingAndPaging();
        $result = $counter->get();
        return (int) ($result->fields['aggregate'] ?? 0);
    }

    /**
     * Internal helper — run a single aggregate function and return the raw result value.
     *
     * @param  string $function  SQL aggregate function name (SUM, AVG, MIN, MAX)
     * @param  string $col       Column expression
     * @return mixed
     */
    private function runAggregate(string $function, string $col): mixed
    {
        $clone = clone $this;
        $clone->select(["{$function}({$col}) AS aggregate"])
              ->clearOrderingAndPaging();
        $result = $clone->get();
        return $result->fields['aggregate'] ?? null;
    }

    /**
     * Execute a SUM aggregate and return the result as a float.
     * Returns 0.0 when no rows match.
     *
     * @param string $col Column to sum
     * @return float
     */
    public function sum(string $col): float
    {
        return (float)($this->runAggregate('SUM', $col) ?? 0.0);
    }

    /**
     * Execute an AVG aggregate and return the result as a float.
     * Returns 0.0 when no rows match.
     *
     * @param string $col Column to average
     * @return float
     */
    public function avg(string $col): float
    {
        return (float)($this->runAggregate('AVG', $col) ?? 0.0);
    }

    /**
     * Execute a MIN aggregate and return the minimum value.
     * Returns null when no rows match.
     *
     * @param string $col Column to find minimum of
     * @return mixed
     */
    public function min(string $col): mixed
    {
        return $this->runAggregate('MIN', $col);
    }

    /**
     * Execute a MAX aggregate and return the maximum value.
     * Returns null when no rows match.
     *
     * @param string $col Column to find maximum of
     * @return mixed
     */
    public function max(string $col): mixed
    {
        return $this->runAggregate('MAX', $col);
    }

    /**
     * Check if any row matches the current WHERE conditions.
     * Issues SELECT EXISTS(SELECT 1 FROM … WHERE …) — more efficient than count() > 0.
     *
     * @return bool
     */
    public function exists(): bool
    {
        $sub = clone $this;
        $sub->select(['1'])->clearOrderingAndPaging();
        $innerSql = str_replace('#PREFIX#', $this->db->prefix, $sub->toSql());
        $sql      = 'SELECT EXISTS(' . $innerSql . ') AS exists_flag';
        $result   = $this->db->execute($sql, ...$sub->getBindings());
        if (!$result) {
            return false;
        }
        $val = $result->fields['exists_flag'] ?? 0;
        // MySQL: 0/1 (int), PostgreSQL: 't'/'f' (string) or true/false (bool)
        return $val === true || $val === 1 || $val === '1' || $val === 't';
    }

    /**
     * Inverse of exists() — returns true when no rows match the conditions.
     *
     * @return bool
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Execute the query with LIMIT 1 and return a single column value.
     * Returns null when no rows match or the column is absent.
     *
     * Does not mutate the current builder.
     *
     * @param string $col Column name (or qualified table.col)
     * @return mixed
     */
    public function value(string $col): mixed
    {
        $clone  = clone $this;
        $result = $clone->select([$col])->limit(1)->get();
        if (!$result || $result->numRows === 0) {
            return null;
        }
        // Strip optional table prefix (table.col → col) for field lookup
        $key = str_contains($col, '.') ? substr(strrchr($col, '.'), 1) : $col;
        return $result->fields[$key] ?? null;
    }

    /**
     * Execute the query and return a flat array of one column's values.
     * Does not mutate the current builder.
     *
     * @param string $col Column name (or qualified table.col)
     * @return array
     */
    public function pluck(string $col): array
    {
        $clone  = clone $this;
        $result = $clone->select([$col])->get();
        if (!$result || $result->numRows === 0) {
            return [];
        }
        $key  = str_contains($col, '.') ? substr(strrchr($col, '.'), 1) : $col;
        $rows = $result->fetchAll();
        return array_column($rows, $key);
    }

    /**
     * Increment a column by a given step and return the number of affected rows.
     *
     * @param string    $col   Column to increment
     * @param int|float $step  Amount to add (default 1)
     * @return int  Number of affected rows
     */
    public function increment(string $col, int|float $step = 1): int
    {
        $quoted = $this->grammar->quoteColumn($col);
        $result = $this->update([$col => $this->raw("{$quoted} + {$step}")]);
        return $result ? (int)$result->getAffectedRows() : 0;
    }

    /**
     * Decrement a column by a given step and return the number of affected rows.
     *
     * @param string    $col   Column to decrement
     * @param int|float $step  Amount to subtract (default 1)
     * @return int  Number of affected rows
     */
    public function decrement(string $col, int|float $step = 1): int
    {
        $quoted = $this->grammar->quoteColumn($col);
        $result = $this->update([$col => $this->raw("{$quoted} - {$step}")]);
        return $result ? (int)$result->getAffectedRows() : 0;
    }

    /**
     * Process the result in chunks of the given size.
     *
     * The callback receives an array of rows (associative arrays) and the
     * current 1-based page number.  Returning false from the callback stops
     * processing early.
     *
     * @param int     $size      Rows per chunk
     * @param \Closure $callback fn(array $rows, int $page): bool|void
     * @return void
     */
    public function chunk(int $size, \Closure $callback): void
    {
        $page = 1;
        do {
            $clone  = clone $this;
            $result = $clone->forPage($page, $size)->get();

            if (!$result || $result->numRows === 0) {
                break;
            }

            $rows    = $result->fetchAll();
            $fetched = count($rows);

            if ($callback($rows, $page) === false) {
                break;
            }

            $page++;
        } while ($fetched === $size);
    }

    /**
     * Apply the given callback only when the condition is truthy.
     *
     * If $condition is a Closure it is evaluated first; its return value is
     * used as the truthiness check and also passed as the second argument to
     * $callback / $default.
     *
     * When $condition is falsy and no $default is provided, the builder is
     * returned unchanged — making this safe to chain in a fluent expression.
     *
     * @param mixed         $condition  Scalar or Closure($this): mixed
     * @param \Closure      $callback   fn(QueryBuilder $qb, mixed $value): void
     * @param \Closure|null $default    fn(QueryBuilder $qb, mixed $value): void
     * @return $this
     */
    public function when(mixed $condition, \Closure $callback, ?\Closure $default = null)
    {
        $value = $condition instanceof \Closure ? $condition($this) : $condition;

        if ($value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    /**
     * Lock the selected rows for update (pessimistic locking).
     * MySQL: FOR UPDATE  |  PostgreSQL: FOR UPDATE
     *
     * @return $this
     */
    public function lockForUpdate()
    {
        $this->lock = 'update';
        return $this;
    }

    /**
     * Lock the selected rows in share mode.
     * MySQL: LOCK IN SHARE MODE  |  PostgreSQL: FOR SHARE
     *
     * @return $this
     */
    public function sharedLock()
    {
        $this->lock = 'share';
        return $this;
    }

    /**
     * Add a WHERE EXISTS (subquery) condition.
     *
     * @param \Closure $callback  fn(QueryBuilder $sub): void — build the sub-query
     * @param string   $boolean   'and' or 'or'
     * @param bool     $not       true for NOT EXISTS
     * @return $this
     */
    public function whereExists(\Closure $callback, string $boolean = 'and', bool $not = false)
    {
        $sub = new static($this->db, $this->grammar);
        $callback($sub);

        $type = $not ? 'NotExists' : 'Exists';
        $this->wheres[] = compact('type', 'sub', 'boolean');

        // Merge the sub-query's bindings into our where slot (left-to-right order)
        foreach ($sub->getBindings() as $binding) {
            $this->bindings['where'][] = $binding;
        }

        return $this;
    }

    /**
     * Add a WHERE NOT EXISTS (subquery) condition.
     *
     * @param \Closure $callback
     * @param string   $boolean
     * @return $this
     */
    public function whereNotExists(\Closure $callback, string $boolean = 'and')
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add an OR WHERE EXISTS condition.
     *
     * @param \Closure $callback
     * @return $this
     */
    public function orWhereExists(\Closure $callback)
    {
        return $this->whereExists($callback, 'or');
    }

    /**
     * Add an OR WHERE NOT EXISTS condition.
     *
     * @param \Closure $callback
     * @return $this
     */
    public function orWhereNotExists(\Closure $callback)
    {
        return $this->whereExists($callback, 'or', true);
    }

    // -------------------------------------------------------------------------
    // Date-part WHERE conditions
    // -------------------------------------------------------------------------

    /**
     * Shared internal helper for all date-part WHERE conditions.
     *
     * @param string $part     One of: date, year, month, day, time
     * @param string $col      Column name
     * @param string $operator Comparison operator
     * @param mixed  $value    Comparison value
     * @param string $boolean  'and' | 'or'
     * @return $this
     */
    protected function addDatePartWhere(string $part, string $col, string $operator, mixed $value, string $boolean)
    {
        $this->wheres[] = [
            'type'     => 'DatePart',
            'part'     => $part,
            'column'   => $col,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => $boolean,
        ];
        $this->addBinding($value, 'where');
        return $this;
    }

    /**
     * Add a WHERE DATE(col) condition — matches rows by date portion only.
     *
     * @param string $col
     * @param mixed  $operator  Operator or value (when called with 2 args)
     * @param mixed  $value
     * @param string $boolean
     * @return $this
     */
    public function whereDate(string $col, mixed $operator, mixed $value = null, string $boolean = 'and')
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }
        return $this->addDatePartWhere('date', $col, (string)$operator, $value, $boolean);
    }

    /**
     * Add a WHERE YEAR(col) condition.
     *
     * @param string $col
     * @param mixed  $operator
     * @param mixed  $value
     * @param string $boolean
     * @return $this
     */
    public function whereYear(string $col, mixed $operator, mixed $value = null, string $boolean = 'and')
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }
        return $this->addDatePartWhere('year', $col, (string)$operator, $value, $boolean);
    }

    /**
     * Add a WHERE MONTH(col) condition.
     *
     * @param string $col
     * @param mixed  $operator
     * @param mixed  $value
     * @param string $boolean
     * @return $this
     */
    public function whereMonth(string $col, mixed $operator, mixed $value = null, string $boolean = 'and')
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }
        return $this->addDatePartWhere('month', $col, (string)$operator, $value, $boolean);
    }

    /**
     * Add a WHERE DAY(col) condition.
     *
     * @param string $col
     * @param mixed  $operator
     * @param mixed  $value
     * @param string $boolean
     * @return $this
     */
    public function whereDay(string $col, mixed $operator, mixed $value = null, string $boolean = 'and')
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }
        return $this->addDatePartWhere('day', $col, (string)$operator, $value, $boolean);
    }

    /**
     * Add a WHERE TIME(col) condition.
     *
     * @param string $col
     * @param mixed  $operator
     * @param mixed  $value
     * @param string $boolean
     * @return $this
     */
    public function whereTime(string $col, mixed $operator, mixed $value = null, string $boolean = 'and')
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }
        return $this->addDatePartWhere('time', $col, (string)$operator, $value, $boolean);
    }

    /**
     * Get all bindings in SQL placeholder order.
     *
     * @return array
     */
    public function getBindings()
    {
        $bindings = array_merge(
            $this->bindings['cte'],    // CTE sub-query values — must come first (CTE SQL is emitted first)
            $this->bindings['select'],
            $this->bindings['values'],
            $this->bindings['from'],
            $this->bindings['join'],
            $this->bindings['where'],
            $this->bindings['having'],
            $this->bindings['order']
        );

        foreach ($this->unions as $union) {
            $bindings = array_merge($bindings, $union['query']->getBindings());
        }

        return $bindings;
    }

    /**
     * Insert a new record.
     *
     * @param array $values
     * @return bool
     */
    public function insert(array $values)
    {
        $this->type = 'INSERT';
        $this->bindings['values'] = [];
        $this->addBinding(
            array_values(array_filter($values, fn($v) => !($v instanceof Expression))),
            'values'
        );

        $sql = $this->grammar->compileInsert($this, $values);
        $sql = str_replace('#PREFIX#', $this->db->prefix, $sql);
        return $this->db->execute($sql, ...$this->getBindings());
    }

    /**
     * Update records.
     *
     * @param array $values
     * @return bool
     */
    public function update(array $values)
    {
        $this->type = 'UPDATE';
        $this->bindings['values'] = [];
        $this->addBinding(
            array_values(array_filter($values, fn($v) => !($v instanceof Expression))),
            'values'
        );

        $sql = $this->grammar->compileUpdate($this, $values);
        $sql = str_replace('#PREFIX#', $this->db->prefix, $sql);
        return $this->db->execute($sql, ...$this->getBindings());
    }

    /**
     * Delete records.
     *
     * @return bool
     */
    public function delete()
    {
        $this->type = 'DELETE';
        $sql = str_replace('#PREFIX#', $this->db->prefix, $this->grammar->compileDelete($this));
        return $this->db->execute($sql, ...$this->getBindings());
    }

    /**
     * Truncate the table (removes all rows, resets auto-increment).
     *
     * @return Result
     */
    public function truncate()
    {
        $sql = str_replace('#PREFIX#', $this->db->prefix, $this->grammar->compileTruncate($this));
        return $this->db->execute($sql);
    }

    /**
     * Insert a record, silently ignoring duplicate-key conflicts.
     * MySQL: INSERT IGNORE — skips the row on any key violation.
     * PostgreSQL: INSERT ... ON CONFLICT DO NOTHING.
     *
     * @param array $values
     * @return Result
     */
    public function insertOrIgnore(array $values)
    {
        $this->type = 'INSERT';
        $this->bindings['values'] = [];
        $this->addBinding(
            array_values(array_filter($values, fn($v) => !($v instanceof Expression))),
            'values'
        );

        $sql = $this->grammar->compileInsertOrIgnore($this, $values);
        $sql = str_replace('#PREFIX#', $this->db->prefix, $sql);
        return $this->db->execute($sql, ...$this->getBindings());
    }

    /**
     * Insert a record, updating specified columns on conflict (upsert).
     *
     * MySQL:      INSERT INTO ... ON DUPLICATE KEY UPDATE col = VALUES(col), ...
     * PostgreSQL: INSERT INTO ... ON CONFLICT (conflictCols) DO UPDATE SET col = EXCLUDED.col, ...
     *
     * @param array  $values           Column → value map for the INSERT
     * @param array  $conflictColumns  Columns that define the conflict target (PG) / trigger (MySQL)
     * @param array  $updateValues     Columns to update on conflict (empty = INSERT IGNORE semantics)
     * @return Result
     */
    public function upsert(array $values, array $conflictColumns, array $updateValues = [])
    {
        $this->type = 'INSERT';
        $this->bindings['values'] = [];
        // $updateValues is always a list of column names (e.g. ['name', 'qty']).
        $this->addBinding(
            array_values(array_filter($values, fn($v) => !($v instanceof Expression))),
            'values'
        );

        $sql = $this->grammar->compileUpsert($this, $values, $conflictColumns, $updateValues);
        $sql = str_replace('#PREFIX#', $this->db->prefix, $sql);
        return $this->db->execute($sql, ...$this->getBindings());
    }
}
