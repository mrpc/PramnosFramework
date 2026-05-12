<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Relations;

use Pramnos\Application\OrmModel;
use Pramnos\Application\Orm\Collection;
use Pramnos\Database\Database;

/**
 * HasMany relationship.
 *
 * The related model holds the foreign key that points to this model's
 * primary key.  Returns a Collection of OrmModel instances.
 *
 * ```php
 * // User has many Posts
 * public function posts(): HasMany {
 *     return $this->hasMany(Post::class, 'user_id');
 * }
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Application\Orm\Relations
 */
class HasMany extends Relation
{
    public function getResults(): Collection
    {
        $related  = $this->newRelatedInstance();
        $localVal = $this->parent->{$this->localKey};

        if ($localVal === null) {
            return new Collection();
        }

        $db     = Database::getInstance();
        $table  = $related->getFullTableName();
        $result = $db->queryBuilder()
            ->from($table)
            ->where($this->foreignKey, $localVal)
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
}
