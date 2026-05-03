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
