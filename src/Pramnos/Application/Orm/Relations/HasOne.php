<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Relations;

use Pramnos\Application\OrmModel;
use Pramnos\Database\Database;

/**
 * HasOne relationship.
 *
 * The related model holds the foreign key that points to this model's
 * primary key.  Returns a single OrmModel instance or null.
 *
 * ```php
 * // User has one Profile
 * public function profile(): HasOne {
 *     return $this->hasOne(Profile::class, 'user_id');
 * }
 * ```
 *
 */
class HasOne extends Relation
{
    public function getResults(): ?OrmModel
    {
        $related  = $this->newRelatedInstance();
        $localVal = $this->parent->{$this->localKey};

        if ($localVal === null) {
            return null;
        }

        $db     = Database::getInstance();
        $table  = $related->getFullTableName();
        $result = $db->queryBuilder()
            ->from($table)
            ->where($this->foreignKey, $localVal)
            ->limit(1)
            ->get();

        if ($result->numRows === 0) {
            return null;
        }

        foreach (array_keys($result->fields) as $field) {
            $related->$field = $result->fields[$field];
        }
        $related->setIsNew(false);

        return $related;
    }
}
