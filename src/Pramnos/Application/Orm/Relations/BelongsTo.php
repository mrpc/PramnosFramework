<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Relations;

use Pramnos\Application\OrmModel;
use Pramnos\Database\Database;

/**
 * BelongsTo relationship.
 *
 * THIS model holds the foreign key that points to the related model's
 * owner key (usually the primary key).  Returns a single OrmModel or null.
 *
 * ```php
 * // Post belongs to User (posts.user_id → users.id)
 * public function author(): BelongsTo {
 *     return $this->belongsTo(User::class, 'user_id', 'id');
 * }
 * ```
 *
 */
class BelongsTo extends Relation
{
    /**
     * In BelongsTo:
     * - $foreignKey = column on *this* model's table
     * - $localKey   = owner key on the *related* model's table
     */
    public function getResults(): ?OrmModel
    {
        $foreignVal = $this->parent->{$this->foreignKey};

        if ($foreignVal === null) {
            return null;
        }

        $related = $this->newRelatedInstance();
        $db      = Database::getInstance();
        $table   = $related->getFullTableName();

        $result = $db->queryBuilder()
            ->from($table)
            ->where($this->localKey, $foreignVal)
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
