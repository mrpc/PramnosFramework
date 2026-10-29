<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * A model's save found the row changed, since the model loaded it, in a column it was writing.
 *
 * Thrown only by a model that declares `protected $_checkWriteConflicts = true;`. Nothing was
 * written. The usual answers are to load the row again and redo the change (a background job),
 * or to tell the person their copy is out of date (a form).
 *
 * The message names nothing, so it is safe to show; the columns are in {@see $columns}, for a
 * log or for code that wants to merge.
 */
class WriteConflictException extends \RuntimeException
{
    /**
     * @param string       $model      The model's class
     * @param mixed        $primaryKey The row's primary key
     * @param list<string> $columns    The written columns whose value in the database is no longer
     *                                 the loaded one; empty when the row is gone
     */
    public function __construct(
        public readonly string $model,
        public readonly mixed $primaryKey,
        public readonly array $columns
    ) {
        parent::__construct('This record was changed by someone else after it was loaded. Nothing was saved.');
    }
}
