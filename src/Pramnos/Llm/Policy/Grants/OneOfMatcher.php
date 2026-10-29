<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy\Grants;

/** One of these values exactly — a status, a list's name. A list of values matches when each does. */
final class OneOfMatcher implements ArgumentMatcher
{
    /** @var list<scalar> */
    private array $values;

    /** @param scalar ...$values */
    public function __construct(string|int|float|bool ...$values)
    {
        $this->values = array_values($values);
    }

    public function matches(mixed $value): bool
    {
        $values = is_array($value) ? $value : [$value];

        return $values !== [] && array_filter($values, fn ($v): bool => !in_array($v, $this->values, true)) === [];
    }
}
