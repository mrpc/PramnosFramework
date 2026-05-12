<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Relations;

use Pramnos\Application\OrmModel;
use Pramnos\Application\Orm\Collection;
use Pramnos\Database\Database;

/**
 * BelongsToMany relationship (many-to-many via pivot table).
 *
 * ```php
 * // User belongs to many Roles via user_roles pivot
 * public function roles(): BelongsToMany {
 *     return $this->belongsToMany(
 *         Role::class,
 *         'user_roles',   // pivot table
 *         'user_id',      // FK for this model in pivot
 *         'role_id'       // FK for related model in pivot
 *     );
 * }
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Application\Orm\Relations
 */
class BelongsToMany extends Relation
{
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $relatedKey;

    public function __construct(
        OrmModel $parent,
        string $relatedClass,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $localKey,
        string $relatedKey
    ) {
        parent::__construct($parent, $relatedClass, $foreignPivotKey, $localKey);
        $this->pivotTable      = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->relatedKey      = $relatedKey;
    }

    public function getResults(): Collection
    {
        $localVal = $this->parent->{$this->localKey};

        if ($localVal === null) {
            return new Collection();
        }

        $related      = $this->newRelatedInstance();
        $relatedTable = $related->getFullTableName();
        $db           = Database::getInstance();

        // JOIN pivot → related table
        $result = $db->queryBuilder()
            ->from("{$this->pivotTable} AS _pivot")
            ->join($relatedTable . ' AS _related', "_pivot.{$this->relatedPivotKey} = _related.{$this->relatedKey}")
            ->where("_pivot.{$this->foreignPivotKey}", $localVal)
            ->select('_related.*')
            ->get();

        $items = [];
        while ($result->fetch()) {
            $instance = $this->newRelatedInstance();
            foreach (array_keys($result->fields) as $field) {
                $instance->$field = $result->fields[$field];
            }
            $instance->setIsNew(false);
            $items[] = $instance;
        }

        return new Collection($items);
    }

    public function getPivotTable(): string      { return $this->pivotTable; }
    public function getForeignPivotKey(): string { return $this->foreignPivotKey; }
    public function getRelatedPivotKey(): string { return $this->relatedPivotKey; }
    public function getRelatedKey(): string      { return $this->relatedKey; }
}
