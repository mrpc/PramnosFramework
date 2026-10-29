<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy\Grants;

/**
 * A path under this directory, after `.` and `..` are worked out — so `/var/safe/../etc` is not
 * under `/var/safe/`. Read as text: the file need not exist, and a link is not followed.
 */
final class PrefixPathMatcher implements ArgumentMatcher
{
    private string $prefix;

    public function __construct(string $prefix)
    {
        $this->prefix = rtrim(self::normal($prefix), '/') . '/';
    }

    public function matches(mixed $value): bool
    {
        return is_string($value) && !str_contains($value, "\0") && str_starts_with(self::normal($value), $this->prefix);
    }

    /** The path with `.`, `..` and repeated slashes worked out. */
    private static function normal(string $path): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '' && $segment !== '.') {
                $out[] = $segment;
            }
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $out) . (str_ends_with($path, '/') ? '/' : '');
    }
}
