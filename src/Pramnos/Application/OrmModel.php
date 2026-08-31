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

            // The physical shape of a soft delete is an UPDATE; its meaning is a delete.
            // Left alone, the base class would announce `updated` on the change feed and
            // a subscriber would keep showing a row the application considers gone — the
            // sort of disagreement that reads as a caching bug and is not one.
            //
            // So the write is silenced and the truthful event emitted here. The primary
            // key is passed explicitly because it is what the row was, and a listener
            // needs it to know which row to stop showing.
            $this->withoutChangeEmission(function () use ($table, $key) {
                parent::_save($table, $key);
            });
            $this->emitChange(\Pramnos\Event\ModelChange::DELETED, array(), $primaryKey);

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
    // Soft-delete + scopes, for every list path
    // -------------------------------------------------------------------------

    /**
     * Merge the soft-delete filter and the scopes into a caller's filter.
     *
     * One helper for the three list entry points below, because they used not to
     * agree. `_getList()` applied both; `_getPaginated()` and
     * `_datatablesRecordsTotal()` applied neither — and those two are what
     * `_getApiList()` calls the moment a page is requested, which is what every REST
     * endpoint, generated CRUD list and datatable does. So a scope registered as
     * `addGlobalScope('tenant', …)`, the use this trait's own documentation shows,
     * held on the unpaginated list and vanished on the paginated one: one tenant's
     * API returning another's rows, and the reported total counting them.
     *
     * The `where` handling is the second half of the same story.
     * {@see \Pramnos\Application\ApiList\ApiListSqlBuilder::combineFilters()} hands
     * these methods a filter that already begins with `where`, and wrapping that in
     * parentheses produced `(where x = 1) AND (deleted_at IS NULL)` — a syntax error,
     * returned as an empty result set with the message buried in the response
     * envelope's `error` key. A soft-deleting model listed with any filter at all
     * came back empty and said nothing about why.
     *
     * @param mixed $filter The caller's filter: null, '', a bare condition, or one
     *                      already prefixed with `where`.
     * @return string|null The merged filter, keyword-free, or null when empty — the
     *                     shape the list methods have always accepted.
     */
    protected function mergeListConditions($filter): ?string
    {
        $filter = is_string($filter) ? trim($filter) : '';

        if (stripos($filter, 'where') === 0) {
            $filter = trim(substr($filter, 5));
        }

        $filter = $this->mergeSoftDeleteFilter($filter === '' ? null : $filter);
        $filter = $this->applyGlobalScopes($filter);
        $filter = $this->applyPendingScopes($filter);

        return $filter === '' ? null : $filter;
    }

    /**
     * The paginated list, with the same conditions {@see _getList()} applies.
     *
     * This is the path `_getApiList()` takes whenever a page is requested — every
     * REST list endpoint, every generated CRUD screen, every datatable. Without this
     * override it went straight to the base implementation and neither the
     * soft-delete filter nor a single global scope reached the query.
     *
     * The parameter list mirrors {@see \Pramnos\Application\Model::_getPaginated()}
     * exactly, because that is the contract callers already have.
     */
    protected function _getPaginated($items = 10, $page = 1,
        $filter = null, $order = null, $table = null,
        $key = null, $debug = false,
        $join = '',
        $queryFields = null,
        $group = '', $returnAsModels = true, $useGetData = false,
        $customGetListMethod = false, $addedfields = array())
    {
        return parent::_getPaginated(
            $items, $page, $this->mergeListConditions($filter), $order, $table,
            $key, $debug, $join, $queryFields, $group, $returnAsModels,
            $useGetData, $customGetListMethod, $addedfields
        );
    }

    /**
     * The row count behind a datatable, with the same conditions applied.
     *
     * Without this the count is over the whole table while the page is over one
     * tenant's rows: a pager offering pages that come back empty, and a disclosure of
     * how many records the other tenants have.
     *
     * It merges, then calls `parent::_getPaginated()` rather than
     * `parent::_datatablesRecordsTotal()`. The base version would route back through
     * the override above and merge a second time — harmless for a soft-delete filter,
     * but it would apply every global scope twice, and a scope that is not a pure
     * condition (one that appends a join, say) would not survive that.
     *
     * Known limit: a *local* scope queued with `applyScope()` is consumed by whichever
     * of the two calls runs first, so on a datatables request it reaches the page and
     * not the count. Global scopes and soft deletes — the ones that carry tenant
     * isolation — are unaffected, being re-derived on each call.
     */
    protected function _datatablesRecordsTotal(
        $baseFilter, $table, $key, $join, $selectFields, $group, $addedfields
    ): int {
        $counted = parent::_getPaginated(
            1, 1, $this->mergeListConditions($baseFilter), '', $table, $key, false,
            $join, $selectFields, $group, false, false, false, $addedfields
        );

        return (int) ($counted['total'] ?? 0);
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
        $filter = $this->mergeListConditions($filter);

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
     * Persist this model to the database.
     *
     * Wraps the protected _save() so that factories and external callers
     * can persist a model without needing to know the internal API.
     *
     * @return static
     */
    public function save(): static
    {
        $this->_save();
        return $this;
    }

    /**
     * Return a factory instance for this model class.
     *
     * By convention, the factory class is named `{ModelClass}Factory`.
     * Override the protected static $factory property in the model to
     * specify a different class:
     *
     *     protected static string $factory = MyCustomUserFactory::class;
     *
     * @return \Pramnos\Support\ModelFactory
     * @throws \RuntimeException When no factory class is found.
     */
    public static function factory(): \Pramnos\Support\ModelFactory
    {
        // Allow models to declare a custom factory class
        if (property_exists(static::class, 'factory') && static::$factory !== '') {
            /** @var class-string<\Pramnos\Support\ModelFactory> */
            $class = static::$factory;
            return new $class();
        }

        // Convention: {ModelClass}Factory (same namespace)
        $defaultClass = static::class . 'Factory';
        if (class_exists($defaultClass)) {
            return new $defaultClass();
        }

        throw new \RuntimeException(
            'Factory class not found for ' . static::class . '. '
            . 'Create ' . $defaultClass . ' extending \\Pramnos\\Support\\ModelFactory, '
            . 'or set protected static string $factory = YourFactory::class; on the model.'
        );
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
