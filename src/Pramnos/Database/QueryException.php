<?php

namespace Pramnos\Database;

/**
 * Thrown by Database::execute()/query() on a genuine SQL error when the
 * connection's `throwOnError` mode is enabled.
 *
 * The framework's historical default is fail-soft: a failing query is logged and
 * returns false, so callers that do not inspect the return value proceed as if it
 * succeeded (silently swallowing errors). Enabling `throwOnError` turns those
 * failures into exceptions so bugs surface instead of hiding. Default stays off
 * for backward compatibility.
 */
class QueryException extends \RuntimeException
{
    /** The SQL that failed (may be long; not shown to end users). */
    protected string $query;

    public function __construct(string $error, string $query = '', ?\Throwable $previous = null)
    {
        $this->query = $query;
        parent::__construct($error, 0, $previous);
    }

    public function getQuery(): string
    {
        return $this->query;
    }
}
