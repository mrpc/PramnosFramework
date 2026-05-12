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
 * @package     PramnosFramework
 * @subpackage  Application\Orm\Relations
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
     * Create a new instance of the related model bound to a dummy controller.
     * We pass the parent's controller so the model can reach the database.
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
