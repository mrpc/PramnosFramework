<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Concerns;

use Pramnos\Application\Orm\Collection;
use Pramnos\Application\Orm\Relations\BelongsTo;
use Pramnos\Application\Orm\Relations\BelongsToMany;
use Pramnos\Application\Orm\Relations\HasMany;
use Pramnos\Application\Orm\Relations\HasOne;

/**
 * Relationship methods and eager-loading support.
 *
 * ## Defining Relationships
 *
 * ```php
 * class User extends OrmModel {
 *     public function profile(): HasOne {
 *         return $this->hasOne(Profile::class, 'user_id');
 *     }
 *     public function posts(): HasMany {
 *         return $this->hasMany(Post::class, 'user_id');
 *     }
 * }
 *
 * class Post extends OrmModel {
 *     public function author(): BelongsTo {
 *         return $this->belongsTo(User::class, 'user_id');
 *     }
 *     public function tags(): BelongsToMany {
 *         return $this->belongsToMany(Tag::class, 'post_tags', 'post_id', 'tag_id');
 *     }
 * }
 * ```
 *
 * ## Lazy Loading
 * Access a relation via the property name — OrmModel::__get() detects the
 * relationship method and calls getResults() automatically.
 *
 * ```php
 * $user->profile;   // triggers hasOne query
 * $user->posts;     // triggers hasMany query
 * ```
 *
 * ## Eager Loading
 * ```php
 * $users = User::with('posts')->getList();
 * ```
 *
 */
trait HasRelationships
{
    /**
     * Loaded (cached) relation results, keyed by relation name.
     *
     * @var array<string, mixed>
     */
    protected array $loadedRelations = [];

    /**
     * Relations to eager-load in the next getList() call.
     * Keyed by relation name.
     *
     * @var string[]
     */
    protected array $eagerLoad = [];

    // -------------------------------------------------------------------------
    // Relation factories
    // -------------------------------------------------------------------------

    /**
     * Define a one-to-one relationship (this model IS the "one" side).
     * The related table holds $foreignKey that points to $this->$localKey.
     *
     * @param  string      $related     FQCN of the related OrmModel.
     * @param  string|null $foreignKey  FK column on the related table (defaults to {thisClass}_id).
     * @param  string|null $localKey    PK on this model's table (defaults to $_primaryKey).
     */
    public function hasOne(
        string  $related,
        ?string $foreignKey = null,
        ?string $localKey   = null
    ): HasOne {
        $localKey   ??= $this->_primaryKey;
        $foreignKey ??= $this->guessForeignKey();
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     * The related table holds $foreignKey that points to $this->$localKey.
     *
     * @param  string      $related
     * @param  string|null $foreignKey  FK column on the related table.
     * @param  string|null $localKey    PK on this model's table.
     */
    public function hasMany(
        string  $related,
        ?string $foreignKey = null,
        ?string $localKey   = null
    ): HasMany {
        $localKey   ??= $this->_primaryKey;
        $foreignKey ??= $this->guessForeignKey();
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     * THIS model holds $foreignKey that points to the related model's $ownerKey.
     *
     * @param  string      $related
     * @param  string|null $foreignKey  FK column on *this* table.
     * @param  string|null $ownerKey    PK on the related table (defaults to $_primaryKey of related).
     */
    public function belongsTo(
        string  $related,
        ?string $foreignKey = null,
        ?string $ownerKey   = null
    ): BelongsTo {
        $instance   = new $related($this->controller);
        $ownerKey   ??= $instance->_primaryKey;
        $foreignKey ??= $this->guessForeignKeyFor($related);
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many relationship via a pivot table.
     *
     * @param  string      $related
     * @param  string|null $pivotTable       Name of the join/pivot table.
     * @param  string|null $foreignPivotKey  FK for this model in the pivot table.
     * @param  string|null $relatedPivotKey  FK for the related model in the pivot table.
     * @param  string|null $localKey         PK on this model's table.
     * @param  string|null $relatedKey       PK on the related model's table.
     */
    public function belongsToMany(
        string  $related,
        ?string $pivotTable      = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $localKey        = null,
        ?string $relatedKey      = null
    ): BelongsToMany {
        $instance        = new $related($this->controller);
        $localKey        ??= $this->_primaryKey;
        $relatedKey      ??= $instance->_primaryKey;
        $foreignPivotKey ??= $this->guessForeignKey();
        $relatedPivotKey ??= $this->guessForeignKeyFor($related);
        $pivotTable      ??= $this->guessPivotTable($related);

        return new BelongsToMany(
            $this, $related, $pivotTable,
            $foreignPivotKey, $relatedPivotKey,
            $localKey, $relatedKey
        );
    }

    // -------------------------------------------------------------------------
    // Eager loading
    // -------------------------------------------------------------------------

    /**
     * Request eager loading of one or more named relations.
     * Returns $this for fluent chaining with _getList() / _getPaginated().
     *
     * @param  string|string[] $relations
     * @return static
     */
    public function with(string|array $relations): static
    {
        $this->eagerLoad = array_merge(
            $this->eagerLoad,
            (array) $relations
        );
        return $this;
    }

    /**
     * Apply eager loading to a flat array of model instances.
     * Called by OrmModel after _getList() returns results.
     *
     * @param  object[] $models  Array of OrmModel instances.
     * @return object[]
     */
    protected function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $relation) {
            if (method_exists($this, $relation)) {
                // Collect all parent key values for one batch query per relation
                $this->loadRelationForModels($models, $relation);
            }
        }
        $this->eagerLoad = [];
        return $models;
    }

    /**
     * Load a relation for multiple model instances in as few queries as possible.
     *
     * @param  object[] $models
     */
    private function loadRelationForModels(array $models, string $relation): void
    {
        if (empty($models)) {
            return;
        }

        // Get relation definition from first model (all share same class)
        $first    = $models[0];
        $relObj   = $first->$relation();
        $localKey = $relObj->getLocalKey();

        // Collect unique local key values
        $keys = array_unique(array_filter(
            array_map(fn($m) => $m->$localKey, $models)
        ));

        if (empty($keys)) {
            return;
        }

        // Build index of local-key → related results
        $relatedClass  = $relObj->getRelatedClass();
        $instance      = new $relatedClass($this->controller);
        $db            = \Pramnos\Database\Database::getInstance();
        $table         = $instance->getFullTableName();
        $foreignKey    = $relObj->getForeignKey();

        $result = $db->queryBuilder()
            ->from($table)
            ->whereIn($foreignKey, $keys)
            ->get();

        // Group by foreign key value
        $byKey = [];
        while ($result->fetch()) {
            $rel = new $relatedClass($this->controller);
            foreach (array_keys($result->fields) as $field) {
                $rel->$field = $result->fields[$field];
            }
            $rel->setIsNew(false);
            $fkVal = $result->fields[$foreignKey] ?? null;
            if ($fkVal !== null) {
                $byKey[$fkVal][] = $rel;
            }
        }

        // Assign to each model
        $isSingular = $relObj instanceof \Pramnos\Application\Orm\Relations\HasOne
                   || $relObj instanceof \Pramnos\Application\Orm\Relations\BelongsTo;

        foreach ($models as $model) {
            $localVal = $model->$localKey;
            $related  = $byKey[$localVal] ?? [];
            $model->loadedRelations[$relation] = $isSingular
                ? ($related[0] ?? null)
                : new Collection($related);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Derive foreign key name from this model's table/class: e.g. "posts" → "post_id". */
    protected function guessForeignKey(): string
    {
        $table = $this->_dbtable ?? '';
        $base  = rtrim(str_replace('#PREFIX#', '', $table), 's');
        return $base . '_id';
    }

    /** Derive foreign key for a given related class. */
    protected function guessForeignKeyFor(string $relatedClass): string
    {
        $parts = explode('\\', $relatedClass);
        $name  = strtolower(end($parts));
        return $name . '_id';
    }

    /**
     * Guess the pivot table name from two class names (alphabetical order,
     * snake_case, joined by underscore — identical to Laravel's convention).
     */
    protected function guessPivotTable(string $relatedClass): string
    {
        $parts   = explode('\\', $relatedClass);
        $related = strtolower(end($parts));

        $parts  = explode('\\', static::class);
        $local  = strtolower(end($parts));

        $names = [$local, $related];
        sort($names);
        return implode('_', $names);
    }
}
