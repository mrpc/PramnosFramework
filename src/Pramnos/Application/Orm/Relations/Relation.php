<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Relations;

use Pramnos\Application\OrmModel;
use Pramnos\Application\Orm\Collection;

/**
 * Abstract base for all ORM relationship types.
 *
 * A Relation encapsulates the query needed to retrieve related model(s)
 * for a given parent instance.  Subclasses implement `getResults()` which
 * is called by `OrmModel::__get()` on first access of the relation name.
 *
 */
abstract class Relation
{
    protected OrmModel $parent;
    protected string   $relatedClass;
    protected string   $foreignKey;
    protected string   $localKey;

    public function __construct(
        OrmModel $parent,
        string $relatedClass,
        string $foreignKey,
        string $localKey
    ) {
        $this->parent       = $parent;
        $this->relatedClass = $relatedClass;
        $this->foreignKey   = $foreignKey;
        $this->localKey     = $localKey;
    }

    /**
     * Execute the relationship query and return the result.
     *
     * @return OrmModel|Collection<OrmModel>|null
     */
    abstract public function getResults(): mixed;

    /**
     * Create a new instance of the related model, carrying the parent's controller.
     *
     * The reason is **not** database access, which the previous version of this
     * comment gave: `Model::__construct()` calls `Database::getInstance()` itself and
     * never touches the controller for it. The controller is passed because
     * `Model::__construct()` requires one, and because a model that shares its
     * parent's controller can resolve sibling models through `getModel()`.
     *
     * A wrong reason in a comment is worse than none: it makes the dependency look
     * load-bearing, and anybody trying to use models outside an MVC request reads
     * this and concludes they need a request. They need
     * {@see \Pramnos\Application\ServiceController}, which costs 1.54 µs.
     *
     * @return OrmModel
     */
    protected function newRelatedInstance(): OrmModel
    {
        $class = $this->relatedClass;
        return new $class($this->parent->controller);
    }

    public function getRelatedClass(): string  { return $this->relatedClass; }
    public function getForeignKey(): string    { return $this->foreignKey; }
    public function getLocalKey(): string      { return $this->localKey; }
}
