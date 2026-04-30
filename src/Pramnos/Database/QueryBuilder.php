<?php

namespace Pramnos\Database;

/**
 * Fluent Query Builder for DML operations.
 * Supports multiple dialects (MySQL, PostgreSQL, TimescaleDB).
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
        'select' => [],
        'from' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'order' => [],
        'values' => [],
    ];

    /**
     * Constructor
     * 
     * @param Database $db
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
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
     * @return string
     */
    public function toSql()
    {
        switch ($this->type) {
            case 'INSERT': return $this->compileInsert();
            case 'UPDATE': return $this->compileUpdate();
            case 'DELETE': return $this->compileDelete();
            default: return $this->compileSelect();
        }
    }

    /**
     * Compile a select query.
     * 
     * @return string
     */
    protected function compileSelect()
    {
        $sql = "SELECT ";
        if ($this->distinct) $sql .= "DISTINCT ";
        $sql .= implode(', ', $this->columns);
        $sql .= " FROM " . $this->from;

        foreach ($this->joins as $join) {
            $sql .= " " . strtoupper($join['type']) . " JOIN " . $join['table'] . " ON " . $join['first'] . " " . $join['operator'] . " " . $join['second'];
        }

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . $this->compileWheres();
        }

        if (!empty($this->groups)) {
            $sql .= " GROUP BY " . implode(', ', $this->groups);
        }

        if (!empty($this->havings)) {
            $sql .= " HAVING " . $this->compileHavings();
        }

        if (!empty($this->orders)) {
            $sql .= " ORDER BY ";
            $orders = [];
            foreach ($this->orders as $order) {
                $orders[] = $order['column'] . " " . strtoupper($order['direction']);
            }
            $sql .= implode(', ', $orders);
        }

        if (isset($this->limit)) {
            $sql .= " LIMIT " . (int)$this->limit;
        }

        if (isset($this->offset)) {
            $sql .= " OFFSET " . (int)$this->offset;
        }

        return str_replace('#PREFIX#', $this->db->prefix, $sql);
    }

    /**
     * Compile where clauses.
     * 
     * @return string
     */
    protected function compileWheres()
    {
        $parts = [];
        foreach ($this->wheres as $where) {
            $part = "";
            if (!empty($parts)) {
                $part .= strtoupper($where['boolean']) . " ";
            }

            switch ($where['type']) {
                case 'Basic':
                    $part .= $where['column'] . " " . $where['operator'] . " " . $this->getPlaceholder($where['value']);
                    break;
                case 'In':
                case 'NotIn':
                    $placeholders = [];
                    foreach ($where['values'] as $v) {
                        $placeholders[] = $this->getPlaceholder($v);
                    }
                    $placeholdersStr = implode(', ', $placeholders);
                    $operator = $where['type'] === 'In' ? 'IN' : 'NOT IN';
                    $part .= $where['column'] . " " . $operator . " (" . $placeholdersStr . ")";
                    break;
                case 'Nested':
                    $part .= "(" . $where['query']->compileWheres() . ")";
                    break;
            }
            $parts[] = $part;
        }
        return implode(' ', $parts);
    }

    /**
     * Compile having clauses.
     * 
     * @return string
     */
    protected function compileHavings()
    {
        $parts = [];
        foreach ($this->havings as $having) {
            $part = "";
            if (!empty($parts)) {
                $part .= strtoupper($having['boolean']) . " ";
            }

            $part .= $having['column'] . " " . $having['operator'] . " " . $this->getPlaceholder($having['value']);
            $parts[] = $part;
        }
        return implode(' ', $parts);
    }

    /**
     * Get the placeholder for a value.
     * 
     * @param mixed $value
     * @return string
     */
    protected function getPlaceholder($value)
    {
        if (is_int($value)) return '%i';
        if (is_float($value)) return '%d';
        if (is_bool($value)) return '%b';
        return '%s';
    }

    /**
     * Execute the query and return the result.
     * 
     * @return Result
     */
    public function get()
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();
        
        return $this->db->execute($sql, ...$bindings);
    }

    /**
     * Get the first record.
     * 
     * @return mixed
     */
    public function first()
    {
        $this->limit(1);
        $result = $this->get();
        return $result && $result->numRows > 0 ? $result->fields : null;
    }

    /**
     * Get all bindings.
     * 
     * @return array
     */
    public function getBindings()
    {
        return array_merge(
            $this->bindings['select'],
            $this->bindings['values'],
            $this->bindings['from'],
            $this->bindings['join'],
            $this->bindings['where'],
            $this->bindings['having'],
            $this->bindings['order']
        );
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
        $this->bindings['values'] = []; // Clear previous values
        $this->addBinding(array_values($values), 'values');
        
        $columns = implode(', ', array_keys($values));
        $placeholders = [];
        foreach ($values as $v) {
            $placeholders[] = $this->getPlaceholder($v);
        }
        $placeholdersStr = implode(', ', $placeholders);
        
        $sql = "INSERT INTO " . $this->from . " (" . $columns . ") VALUES (" . $placeholdersStr . ")";
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
        $this->bindings['values'] = []; // Clear previous values
        $this->addBinding(array_values($values), 'values');

        $sets = [];
        foreach ($values as $column => $value) {
            $sets[] = $column . " = " . $this->getPlaceholder($value);
        }
        
        $sql = "UPDATE " . $this->from . " SET " . implode(', ', $sets);
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . $this->compileWheres();
        }
        
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
        $sql = "DELETE FROM " . $this->from;
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . $this->compileWheres();
        }
        
        $sql = str_replace('#PREFIX#', $this->db->prefix, $sql);
        
        return $this->db->execute($sql, ...$this->getBindings());
    }

    /**
     * Compile an insert statement.
     * (Currently handled directly in insert() but kept for toSql() consistency)
     * 
     * @return string
     */
    protected function compileInsert()
    {
        // This is a placeholder since insert() handles its own compilation for now
        return "";
    }

    /**
     * Compile an update statement.
     * 
     * @return string
     */
    protected function compileUpdate()
    {
        $sets = [];
        // Note: we can't easily know values here without passing them, 
        // so toSql() might be limited for UPDATE if values aren't set yet.
        return "";
    }

    /**
     * Compile a delete statement.
     * 
     * @return string
     */
    protected function compileDelete()
    {
        $sql = "DELETE FROM " . $this->from;
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . $this->compileWheres();
        }
        return $sql;
    }
}
