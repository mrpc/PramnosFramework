<?php

namespace Pramnos\Database;

/**
 * Wraps a raw SQL fragment that should be embedded verbatim (not bound as a parameter).
 *
 * @package     PramnosFramework
 * @subpackage  Database
 */
class Expression
{
    protected $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function __toString()
    {
        return (string)$this->getValue();
    }
}
