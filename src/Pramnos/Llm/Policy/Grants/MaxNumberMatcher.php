<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy\Grants;

/** A number no greater than the limit — and a number at all: text that is not one does not match. */
final class MaxNumberMatcher implements ArgumentMatcher
{
    public function __construct(private readonly float $max)
    {
    }

    public function matches(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && is_finite((float) $value) && (float) $value <= $this->max;
    }
}
