<?php

// NOTE: no declare(strict_types=1) — these helpers were extracted verbatim from
// Pramnos\Application\Model (which runs in coercive mode), and callers rely on
// scalar coercion (e.g. a bool false $join/$order coerced to ''). Enabling strict
// types here would change behaviour versus the pre-extraction code.
namespace Pramnos\Application\ApiList;

/**
 * Stateless SQL-fragment builder for the model list-query engine.
 *
 * Phase 2 of the ApiListQuery extraction: these helpers were previously private
 * methods on {@see \Pramnos\Application\Model}. They are pure with respect to
 * model state — they depend only on their arguments (and the shared
 * {@see \Pramnos\Database\Database} singleton for the active driver flavour, so
 * PostgreSQL vs MySQL identifier quoting is chosen correctly). Extracting them
 * here lets both {@see \Pramnos\Application\Model} and, in later phases, a
 * standalone list engine (and {@see \Pramnos\User\User}) share one
 * implementation instead of maintaining parallel copies.
 *
 * Model keeps thin private wrappers that delegate here, so behaviour and every
 * public/protected signature are unchanged (CLAUDE.md §6).
 */
final class ApiListSqlBuilder
{
    /**
     * Extract the result column name from a SQL field expression.
     * Handles: table prefix ("a.id" → "id"), aliases ("id as uid" → "uid"),
     * identifier quotes (backticks, double-quotes, single-quotes), and functions.
     *
     * @param string $field The field expression.
     * @return string The bare result column name.
     */
    public static function resolveFieldResultName(string $field): string
    {
        $field = trim($field);
        // Alias takes priority: "expr AS alias" → alias
        if (preg_match('/\bAS\s+["`]?(\w+)["`]?\s*$/i', $field, $m)) {
            return $m[1];
        }
        // Strip table/alias prefix: "a.id" → "id"
        if (strpos($field, '.') !== false) {
            $field = substr($field, strrpos($field, '.') + 1);
        }
        // Strip identifier quotes
        return trim($field, '"`\'');
    }

    /**
     * Strip a leading SQL keyword (e.g. WHERE / ORDER BY / GROUP BY) from a
     * fragment, case-insensitively, so callers can pass either form.
     *
     * @param string $sql     The SQL fragment.
     * @param string $keyword The keyword to strip if it leads the fragment.
     * @return string The fragment without the leading keyword.
     */
    public static function stripSqlKeyword(string $sql, string $keyword): string
    {
        return preg_replace(
            '/^\s*' . preg_quote($keyword, '/') . '\s+/i',
            '',
            $sql
        );
    }

    /**
     * Ensure the primary key column is always included in the SELECT list so
     * that result rows can be indexed by primary key in the fetch loop.
     * When $queryFields is null or '*' the original value is returned unchanged.
     *
     * @param string|null $queryFields The requested select list.
     * @param string      $primaryKey  The primary-key column name.
     * @return string The select list guaranteed to contain the primary key.
     */
    public static function ensurePrimaryKeyInSelect(?string $queryFields, string $primaryKey): string
    {
        if ($queryFields === null || $queryFields === '' || $queryFields === '*') {
            return $queryFields ?? '*';
        }
        // Normalise the primary key for comparison: drop any table prefix,
        // surrounding identifier quotes (backticks / double quotes) and space.
        // Without stripping the quotes, an already-present but quoted/qualified PK
        // (e.g. a.`id`) would not be recognised and a duplicate bare `id` would be
        // prepended — producing an ambiguous column when the query JOINs a table
        // that shares the PK column name.
        $barePk = strtolower(
            trim(preg_replace('/^[a-zA-Z0-9_]+\./', '', $primaryKey), " `\"")
        );
        $listed = array_map('trim', explode(',', $queryFields));
        foreach ($listed as $f) {
            $bare = preg_replace('/^[a-zA-Z0-9_]+\./', '', $f); // strip table prefix
            $bare = preg_replace('/\s+as\s+.+$/i', '', $bare);  // strip alias
            $bare = strtolower(trim($bare, " `\""));             // strip quotes/space
            if ($bare === $barePk) {
                return $queryFields; // already present
            }
        }
        return $primaryKey . ', ' . $queryFields;
    }

    /**
     * Build the SELECT field list, quoting per driver and aliasing duplicate
     * joined column names so a JOIN sharing a column name does not collide.
     *
     * @param array  $fields Field names (bare, "table.field", or "expr AS alias").
     * @param string $join   The JOIN clause (determines whether the main-table
     *                       alias `a.` is prepended to unqualified fields).
     * @return string The comma-separated SELECT list.
     */
    public static function buildSelectFields($fields, $join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $selectFields = array();
        $hasJoin = !empty(trim($join));
        $fieldNames = array(); // Track field names to detect duplicates

        foreach ($fields as $field) {
            $originalField = $field;
            $fieldAlias = '';

            // Check if field already has an AS clause
            if (stripos($field, ' as ') !== false) {
                $selectFields[] = $field;
                continue;
            }

            if (strpos($field, '.') === false && $hasJoin) {
                // Add table alias for fields without explicit table reference when using joins
                if ($database->type == 'postgresql') {
                    $selectFields[] = 'a."' . $field . '"';
                } else {
                    $selectFields[] = 'a.`' . $field . '`';
                }
                $fieldNames[] = $field;
            } elseif (strpos($field, '.') === false) {
                // No join, no alias needed
                if ($database->type == 'postgresql') {
                    $selectFields[] = '"' . $field . '"';
                } else {
                    $selectFields[] = '`' . $field . '`';
                }
                $fieldNames[] = $field;
            } else {
                // Field already has table reference (e.g., a.status, b.status)
                $parts = explode('.', $field);
                if (count($parts) == 2) {
                    $tableAlias = $parts[0];
                    $fieldName = $parts[1];

                    // For PostgreSQL, we need to quote the field name properly
                    if ($database->type == 'postgresql') {
                        $quotedField = $tableAlias . '."' . $fieldName . '"';
                    } else {
                        $quotedField = $tableAlias . '.`' . $fieldName . '`';
                    }

                    // Check if this field name already exists
                    if (in_array($fieldName, $fieldNames)) {
                        // Create alias to avoid duplicate field names
                        $alias = $tableAlias . '_' . $fieldName;
                        if ($database->type == 'postgresql') {
                            $selectFields[] = $quotedField . ' AS "' . $alias . '"';
                        } else {
                            $selectFields[] = $quotedField . ' AS `' . $alias . '`';
                        }
                    } else {
                        $selectFields[] = $quotedField;
                        $fieldNames[] = $fieldName;
                    }
                } else {
                    $selectFields[] = $field;
                }
            }
        }

        return implode(', ', $selectFields);
    }

    /**
     * Validate and build the ORDER BY clause from a comma list of order tokens.
     * Each token may carry a +/- prefix or an ASC/DESC suffix; unknown or
     * malformed fields are skipped, and when nothing valid remains it falls back
     * to the primary key DESC (the same default as an empty order).
     *
     * @param string|null $order           The requested order specification.
     * @param array       $availableFields The whitelist of orderable fields.
     * @param string      $join            The JOIN clause (alias handling).
     * @param string      $primaryKey      The primary key for the default order.
     * @return string The ORDER BY clause (including the ORDER BY keyword).
     */
    public static function validateAndBuildOrder($order, $availableFields, $join, string $primaryKey)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $orderParts = array();
        $hasJoin = !empty(trim($join));

        // Create a mapping of field names without table prefixes to their full references
        $fieldMapping = array();
        $mainTableFields = array();
        $joinedTableFields = array();

        foreach ($availableFields as $field) {
            if (strpos($field, '.') !== false) {
                // Extract field name after the dot
                $fieldName = substr($field, strrpos($field, '.') + 1);

                // Store in joined fields array - prioritize joined table fields over main table
                if (!isset($joinedTableFields[$fieldName])) {
                    $joinedTableFields[$fieldName] = array();
                }
                $joinedTableFields[$fieldName][] = $field;

                // Also add to general mapping, but joined fields take precedence
                if (!isset($fieldMapping[$fieldName]) || !isset($mainTableFields[$fieldName])) {
                    $fieldMapping[$fieldName] = $field;
                }
            } else {
                // Main table field
                $mainTableFields[$field] = $field;
                // Only add to mapping if not already occupied by a joined field
                if (!isset($fieldMapping[$field])) {
                    $fieldMapping[$field] = $field;
                }
            }
        }

        if (empty(trim($order))) {
            // Default order by primary key DESC
            if ($hasJoin) {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY a."' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY a.`' . $primaryKey . '` DESC';
                }
            } else {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY "' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY `' . $primaryKey . '` DESC';
                }
            }
        }

        // Split by comma and process each field
        $fields = array_map('trim', explode(',', $order));

        foreach ($fields as $field) {
            $field = trim($field);
            if (empty($field)) {
                continue;
            }

            $direction = 'ASC';
            $fieldName = $field;

            // Check for +/- prefix
            if (substr($field, 0, 1) === '+') {
                $direction = 'ASC';
                $fieldName = substr($field, 1);
            } elseif (substr($field, 0, 1) === '-') {
                $direction = 'DESC';
                $fieldName = substr($field, 1);
            } else {
                // Check for explicit ASC/DESC suffix
                $parts = preg_split('/\s+/', $field);
                if (count($parts) >= 2) {
                    $fieldName = $parts[0];
                    $lastPart = strtoupper(end($parts));
                    if ($lastPart === 'ASC' || $lastPart === 'DESC') {
                        $direction = $lastPart;
                    }
                }
            }

            $fieldName = trim($fieldName);

            // Sanitize field name - only allow alphanumeric, underscore, and dot
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $fieldName)) {
                continue; // Skip invalid field names
            }

            // Use field mapping to validate and resolve field names
            $targetField = null;

            // First try exact match in available fields (handles table.field format)
            if (in_array($fieldName, $availableFields)) {
                $targetField = $fieldName;
            } else {
                // Try to find field by name without table prefix using field mapping
                if (isset($fieldMapping[$fieldName])) {
                    $targetField = $fieldMapping[$fieldName];
                } else {
                    // If field not found in mapping, check if it's a joined table field
                    // and try to find the first available match
                    if (isset($joinedTableFields[$fieldName]) && !empty($joinedTableFields[$fieldName])) {
                        $targetField = $joinedTableFields[$fieldName][0]; // Use first available joined field
                    }
                }
            }

            if ($targetField !== null) {
                $fieldRef = $targetField;
                if (strpos($targetField, '.') === false && $hasJoin) {
                    // Field from main table, add table alias
                    $fieldRef = 'a.' . ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
                } elseif (strpos($targetField, '.') === false) {
                    // Field from main table, no join
                    $fieldRef = ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
                } else {
                    // Field already has table reference (joined table), validate and quote properly
                    $parts = explode('.', $targetField);
                    if (count($parts) === 2) {
                        $tableAlias = $parts[0];
                        $field = $parts[1];

                        if ($database->type == 'postgresql') {
                            $fieldRef = $tableAlias . '."' . $field . '"';
                        } else {
                            $fieldRef = $tableAlias . '.`' . $field . '`';
                        }
                    }
                }

                $orderParts[] = $fieldRef . ' ' . $direction;
            }
        }

        if (empty($orderParts)) {
            // If no valid fields found, use default primary key order
            if ($hasJoin) {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY a."' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY a.`' . $primaryKey . '` DESC';
                }
            } else {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY "' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY `' . $primaryKey . '` DESC';
                }
            }
        }

        return 'ORDER BY ' . implode(', ', $orderParts);
    }

    /**
     * Build a safe SQL WHERE fragment from a structured conditions array.
     *
     * Top-level conditions are joined with AND. Each entry is either a single
     * condition (['field'=>, 'op'=>, 'value'=>]), an OR group (['or'=>[...]]),
     * or a raw fragment (['raw'=>'...'], app-generated SQL only). Fields are
     * validated against $availableFields and quoted; values escaped via
     * prepareInput(). Unknown fields are silently skipped.
     *
     * @param array  $conditions      Structured conditions.
     * @param array  $availableFields Whitelist of valid field names.
     * @param string $join            JOIN clause (used to decide alias quoting).
     * @return string Raw SQL WHERE body (without the WHERE keyword).
     */
    public static function buildFilterFromConditions(array $conditions, array $availableFields, string $join = ''): string
    {
        $database = \Pramnos\Database\Database::getInstance();
        $hasJoin  = !empty(trim($join));
        $parts    = [];

        // Build a field-name → full reference map (same pattern as _buildSearchConditions)
        $fieldMapping = [];
        foreach ($availableFields as $f) {
            $fieldName = strpos($f, '.') !== false
                ? substr($f, strrpos($f, '.') + 1)
                : $f;
            $fieldMapping[$fieldName] = $f;
        }

        foreach ($conditions as $condition) {
            // OR group: ['or' => [...conditions...]]
            if (isset($condition['or']) && is_array($condition['or'])) {
                $orParts = [];
                foreach ($condition['or'] as $orCondition) {
                    $expr = self::buildSingleCondition($orCondition, $availableFields, $fieldMapping, $hasJoin, $database);
                    if ($expr !== null) {
                        $orParts[] = $expr;
                    }
                }
                if (!empty($orParts)) {
                    $parts[] = '(' . implode(' OR ', $orParts) . ')';
                }
                continue;
            }

            // Raw SQL fragment — caller is responsible for safety (app-generated SQL only)
            // Usage: ['raw' => 'a.`locationid` = 5']
            if (isset($condition['raw']) && is_string($condition['raw'])) {
                $raw = trim($condition['raw']);
                if ($raw !== '') {
                    $parts[] = $raw;
                }
                continue;
            }

            // Regular single condition
            $expr = self::buildSingleCondition($condition, $availableFields, $fieldMapping, $hasJoin, $database);
            if ($expr !== null) {
                $parts[] = $expr;
            }
        }

        return implode(' AND ', $parts);
    }

    /**
     * Build a single SQL condition expression from a condition array.
     * Returns null if the condition is invalid or the field is unknown.
     *
     * @param array  $condition
     * @param array  $availableFields
     * @param array  $fieldMapping     field-name → full reference map
     * @param bool   $hasJoin
     * @param object $database
     * @return string|null
     */
    public static function buildSingleCondition(array $condition, array $availableFields, array $fieldMapping, bool $hasJoin, $database): ?string
    {
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];

        if (!isset($condition['field'], $condition['op'])) {
            return null;
        }

        $fieldName = $condition['field'];
        $op        = strtoupper(trim($condition['op']));

        if (!in_array($op, $allowedOps, true)) {
            return null;
        }

        // Resolve and validate field reference
        $targetField = null;
        if (in_array($fieldName, $availableFields, true)) {
            $targetField = $fieldName;
        } elseif (isset($fieldMapping[$fieldName])) {
            $targetField = $fieldMapping[$fieldName];
        }

        if ($targetField === null) {
            return null; // Unknown field — skip silently
        }

        // Quote the field reference
        if (strpos($targetField, '.') === false) {
            if ($hasJoin) {
                $fieldRef = $database->type === 'postgresql'
                    ? 'a."' . $targetField . '"'
                    : 'a.`' . $targetField . '`';
            } else {
                $fieldRef = $database->type === 'postgresql'
                    ? '"' . $targetField . '"'
                    : '`' . $targetField . '`';
            }
        } else {
            $fieldRef = $targetField; // Already has table prefix
        }

        // Operators that take no value
        if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            return $fieldRef . ' ' . $op;
        }

        if (!array_key_exists('value', $condition)) {
            return null;
        }

        $value = $condition['value'];

        // IN / NOT IN — value must be a non-empty array
        if ($op === 'IN' || $op === 'NOT IN') {
            if (!is_array($value) || empty($value)) {
                return null;
            }
            $escaped = array_map(function ($v) use ($database) {
                return "'" . $database->prepareInput((string)$v) . "'";
            }, $value);
            return $fieldRef . ' ' . $op . ' (' . implode(', ', $escaped) . ')';
        }

        // LIKE / ILIKE — normalise to the correct operator for the DB engine
        if ($op === 'LIKE' || $op === 'ILIKE') {
            $actualOp = ($database->type === 'postgresql') ? 'ILIKE' : 'LIKE';
            return $fieldRef . ' ' . $actualOp . " '" . $database->prepareInput((string)$value) . "'";
        }

        // Scalar comparisons: =  !=  <>  <  >  <=  >=
        if (is_null($value)) {
            return $fieldRef . ' ' . ($op === '=' ? 'IS NULL' : 'IS NOT NULL');
        } elseif (is_int($value) || is_float($value)) {
            return $fieldRef . ' ' . $op . ' ' . $value;
        } else {
            return $fieldRef . ' ' . $op . " '" . $database->prepareInput((string)$value) . "'";
        }
    }

    /**
     * Combine a base WHERE filter with search conditions, emitting a fragment
     * that starts with the `where` keyword (or '' when both are empty). A
     * leading `where` in the base filter is stripped first so it is not doubled.
     *
     * @param string $baseFilter       Base WHERE filter (with or without keyword).
     * @param string $searchConditions Search conditions (without keyword).
     * @return string The combined `where ...` fragment, or '' when both empty.
     */
    public static function combineFilters($baseFilter, $searchConditions)
    {
        $baseFilter = trim($baseFilter);
        $searchConditions = trim($searchConditions);

        // Remove 'where' keyword if present
        if (stripos($baseFilter, 'where') === 0) {
            $baseFilter = trim(substr($baseFilter, 5));
        }

        if (empty($baseFilter) && empty($searchConditions)) {
            return '';
        } elseif (empty($baseFilter)) {
            return 'where ' . $searchConditions;
        } elseif (empty($searchConditions)) {
            return 'where ' . $baseFilter;
        } else {
            return 'where ' . $baseFilter . ' AND ' . $searchConditions;
        }
    }
}
