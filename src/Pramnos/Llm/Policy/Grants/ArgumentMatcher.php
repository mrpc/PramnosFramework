<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy\Grants;

/**
 * One argument's test in a {@see ToolGrant}: typed, on the decoded value — never a pattern over the
 * call's JSON, which spacing, key order or an escape would get past.
 */
interface ArgumentMatcher
{
    /** Whether this value is within the grant. */
    public function matches(mixed $value): bool;
}
