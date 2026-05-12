<?php

declare(strict_types=1);

namespace Pramnos\Application;

use Pramnos\Application\Orm\Collection;
use Pramnos\Application\Orm\Concerns\HasAttributes;
use Pramnos\Application\Orm\Concerns\HasEvents;
use Pramnos\Application\Orm\Concerns\HasRelationships;
use Pramnos\Application\Orm\Concerns\HasScopes;
use Pramnos\Application\Orm\Concerns\HasSoftDeletes;
use Pramnos\Application\Orm\Concerns\HasTimestamps;
use Pramnos\Application\Orm\Relations\Relation;

/**
 * Extended ORM base model for Pramnos Framework v1.2.
 *
 * Extends the existing `Model` class with the full ORM feature set:
 *
 * | Feature                | Trait / Class            |
 * |------------------------|--------------------------|
 * | Mass Assignment        | HasAttributes            |
 * | Casting                | HasAttributes            |
 * | Accessors / Mutators   | HasAttributes            |
 * | Timestamps             | HasTimestamps            |
 * | Soft Deletes           | HasSoftDeletes           |
 * | Model Events           | HasEvents                |
 * | Scopes (local+global)  | HasScopes                |
 * | Relationships          | HasRelationships         |
 * | Eager Loading          | HasRelationships         |
 * | Collections            | Orm\Collection           |
 *
 * ## Quick Start
 *
 * ```php
 * class User extends OrmModel {
 *     protected $_dbtable   = 'users';
 *     protected array $fillable  = ['name', 'email'];
 *     protected array $casts     = ['is_admin' => 'bool', 'prefs' => 'json'];
 *     protected bool  $softDelete = true;
 *
 *     public function posts(): \Pramnos\Application\Orm\Relations\HasMany {
 *         return $this->hasMany(Post::class, 'user_id');
 *     }
 *
 *     public function getFullNameAttribute(string $value): string {
 *         return strtoupper($value);
 *     }
 * }
 *
 * // Create / update
 * $user = new User($controller);
 * $user->fill(['name' => 'Alice', 'email' => 'alice@example.com']);
 * $user->_save();
 *
 * // Load with eager-loaded relation
 * $user->with('posts')->_load(1);
 *
 * // Soft delete / restore
 * $user->delete();
 * $user->restore();
 *
 * // Scoped list
 * $list = $user->applyScope('active')->_getList();
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Application
 */
abstract class OrmModel extends Model
{
    use HasAttributes;
    use HasTimestamps;
    use HasSoftDeletes;
    use HasEvents;
    use HasScopes;
    use HasRelationships;

    /**
     * Pending local scope calls accumulated by applyScope().
     * @var array<int, array{string, array}>
     */
    protected array $_pendingScopes = [];

    // -------------------------------------------------------------------------
    // Magic attribute access with cast + accessor + relationship support
    // -------------------------------------------------------------------------

    /**
     * Read a model attribute.
     *
     * Resolution order:
     * 1. Already-loaded relation result (from $loadedRelations).
     * 2. A relationship method returning a Relation object (lazy-load).
     * 3. Accessor method (`getXxxAttribute`).
     * 4. Cast applied to raw value from parent $_data.
     */
    public function __get($name): mixed
    {
        // 1. Cached relation
        if (array_key_exists($name, $this->loadedRelations)) {
            return $this->loadedRelations[$name];
        }

        // 2. Relation method (lazy load)
        if (method_exists($this, $name)) {
            $result = $this->$name();
            if ($result instanceof Relation) {
                $resolved = $result->getResults();
                $this->loadedRelations[$name] = $resolved;
                return $resolved;
            }
        }

        // 3. Raw value from parent storage
        $rawValue = parent::__get($name);

        // 4. Accessor
        [$hasAccessor, $value] = $this->getAccessorValue($name, $rawValue);
        if ($hasAccessor) {
            return $value;
        }

        // 5. Cast
        if ($this->hasCast($name)) {
            return $this->castAttribute($name, $rawValue);
        }

        return $rawValue;
    }

    /**
     * Support `isset()` / `empty()` checks on model attributes.
     *
     * Without __isset(), PHP's empty() treats undeclared properties as
     * non-existent and returns true regardless of what __get() would return.
     * Delegates to the raw $_data store and loaded relations.
     */
    public function __isset($name): bool
    {
        if (array_key_exists($name, $this->loadedRelations)) {
            return $this->loadedRelations[$name] !== null;
        }
        return isset($this->_data[$name]);
    }

    /**
     * Write a model attribute.
     *
     * Resolution order:
     * 1. Mutator method (`setXxxAttribute`).
     * 2. Reverse cast (e.g. array → JSON string before storage).
     * 3. Parent storage via $_data.
     */
    public function __set($name, $value): void
    {
        // 1. Mutator
        [$hasMutator, $transformed] = $this->getMutatorValue($name, $value);
        $value = $hasMutator ? $transformed : $value;

        // 2. Reverse cast for storage types that differ from PHP representation
        if ($this->hasCast($name)) {
            $value = $this->decastAttribute($name, $value);
        }

        parent::__set($name, $value);
    }

    // -------------------------------------------------------------------------
    // Override _save() — add timestamps + events
    // -------------------------------------------------------------------------

    protected function _save(
        $table        = null,
        $key          = null,
        $autoGetValues = false,
        $debug        = false,
        $force        = false
    ) {
        $isNew = $this->_isnew;

        // Fire before-event; return $this (not false) to maintain BC
        $event = $isNew ? 'creating' : 'updating';
        if (!$this->fireEvent($event)) {
            return $this;
        }

        // Auto-set timestamps
        $this->touchTimestamps($isNew);

        // Call parent implementation for actual DB write
        $result = parent::_save($table, $key, $autoGetValues, $debug, $force);

        // Fire after-event
        $this->fireEvent($isNew ? 'created' : 'updated');

        return $result;
    }

    // -------------------------------------------------------------------------
    // Override _delete() — add soft deletes + events
    // -------------------------------------------------------------------------

    protected function _delete($primaryKey, $table = null, $key = null)
    {
        // Fire before-event
        if (!$this->fireEvent('deleting')) {
            return $this;
        }

        // Soft delete: update deleted_at instead of hard DELETE
        if ($this->softDelete) {
            $col        = $this->deletedAtColumn;
            $this->$col = date('Y-m-d H:i:s');
            parent::_save($table, $key);
            $this->fireEvent('deleted');
            return $this;
        }

        // Hard delete
        $result = parent::_delete($primaryKey, $table, $key);
        $this->fireEvent('deleted');
        return $result;
    }

    // -------------------------------------------------------------------------
    // Override _load() — apply soft-delete filter
    // -------------------------------------------------------------------------

    protected function _load(
        $primaryKey,
        $table    = null,
        $key      = null,
        $debug    = false,
        $useCache = true
    ) {
        parent::_load($primaryKey, $table, $key, $debug, $useCache);

        // If soft-delete is active, null out a loaded record that is trashed
        if ($this->softDelete && !$this->withTrashedFlag && !$this->onlyTrashedFlag) {
            $col = $this->deletedAtColumn;
            if (!empty($this->$col)) {
                // Record is soft-deleted — treat as not found
                $this->_isnew = true;
                $this->_initialData = [];
            }
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Override _getList() — add soft-delete + scopes
    // -------------------------------------------------------------------------

    public function _getList(
        $filter              = null,
        $order               = null,
        $table               = null,
        $key                 = null,
        $debug               = false,
        $join                = '',
        $queryFields         = null,
        $group               = '',
        $returnAsModels      = true,
        $useGetData          = false,
        $displayerroroutput  = true,
        $customGetListMethod = false,
        $addedfields         = false
    ) {
        // Apply soft-delete filter
        $filter = $this->mergeSoftDeleteFilter(is_string($filter) ? $filter : null);

        // Apply global scopes
        $filter = $this->applyGlobalScopes($filter ?? '');
        if ($filter === '') {
            $filter = null;
        }

        // Apply local scopes accumulated via applyScope()
        $filter = $this->applyPendingScopes($filter ?? '');
        if ($filter === '') {
            $filter = null;
        }

        $results = parent::_getList(
            $filter, $order, $table, $key, $debug, $join,
            $queryFields, $group, $returnAsModels, $useGetData,
            $displayerroroutput, $customGetListMethod, $addedfields
        );

        // Eager loading: only when results are OrmModel instances
        if (!empty($this->eagerLoad) && is_array($results)) {
            $models = array_filter($results, fn($r) => $r instanceof self);
            if (!empty($models)) {
                $this->eagerLoadRelations(array_values($models));
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Public helpers
    // -------------------------------------------------------------------------

    /**
     * Expose `_isnew` state so Relation classes can set it after loading.
     */
    public function setIsNew(bool $value): void
    {
        $this->_isnew = $value;
    }

    /**
     * Return a Collection wrapping the result of _getList().
     * Useful when you want functional collection methods on the result set.
     *
     * @return Collection<static>
     */
    public function getCollection(
        ?string $filter = null,
        ?string $order  = null
    ): Collection {
        $items = $this->_getList($filter, $order, null, null, false, '', null, null, true);
        return new Collection(is_array($items) ? $items : []);
    }

    /**
     * Return a plain array representation of this model's stored attributes.
     * Applies casts and accessors.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::getData();
        $result = [];
        foreach ((array) $data as $key => $value) {
            [$hasAccessor, $val] = $this->getAccessorValue($key, $value);
            $val = $hasAccessor ? $val : ($this->hasCast($key) ? $this->castAttribute($key, $value) : $value);
            $result[$key] = $val;
        }
        // Append loaded relations
        foreach ($this->loadedRelations as $name => $rel) {
            $result[$name] = method_exists($rel, 'toArray') ? $rel->toArray() : $rel;
        }
        return $result;
    }
}
