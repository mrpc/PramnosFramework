<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy\Grants;

/**
 * Every email address in the value is at one of these domains. The value is an address, a list of
 * them, or one string of several (`a@x.test, B <b@x.test>`); a value with no address in it, or one
 * address outside, does not match.
 */
final class DomainMatcher implements ArgumentMatcher
{
    /** @var list<string> */
    private array $domains;

    public function __construct(string ...$domains)
    {
        $this->domains = array_map(static fn (string $d): string => strtolower(ltrim(trim($d), '@')), $domains);
    }

    public function matches(mixed $value): bool
    {
        $parts = [];
        foreach (is_array($value) ? $value : [$value] as $one) {
            if (!is_string($one)) {
                return false;
            }
            foreach (preg_split('/[,;]/', $one) ?: [] as $part) {
                if (trim($part) !== '') {
                    $parts[] = trim($part);
                }
            }
        }

        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            // "Name <a@b>": the address is what is in the brackets.
            $address = preg_match('/<([^>]*)>/', $part, $m) === 1 ? trim($m[1]) : $part;
            $at      = strrpos($address, '@');
            if ($at === false || filter_var($address, FILTER_VALIDATE_EMAIL) === false
                || !in_array(strtolower(substr($address, $at + 1)), $this->domains, true)) {
                return false;
            }
        }

        return true;
    }
}
