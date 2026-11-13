<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

/**
 * One of the analyst's own ceilings — rows, rounds, cost — read from `'llm' => ['analyst' =>
 * [$key => …]]` as either a plain number or a function of the person asking:
 *
 * ```php
 * 'llm' => ['analyst' => [
 *     'sql_rows'    => 500,                                           // everybody
 *     'export_rows' => fn (\Pramnos\User\User $u): int => $u->usertype >= 99 ? 500000 : 20000,
 * ]],
 * ```
 *
 * A callable is read with the account asking — {@see ReadOnlySql::seconds()}'s own
 * `sql_seconds` is the one of these that already existed; this is the same idea, generalised so
 * `sql_rows`/`export_rows`/{@see \Pramnos\Llm\Analyst\Tools\DeepAnalysisTool}'s own rounds and
 * cost ceiling can all be set the same way, once, per role rather than per feature.
 */
final class Limits
{
    /**
     * `$key`'s value for `$userId` (the floor's own account when 0): `$default` when the
     * application set nothing, or set something that is not a number once a callable was
     * asked for one.
     */
    public static function of(string $key, int|float $default, int $userId = 0): int|float
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);
        $value  = is_array($config) ? ($config[$key] ?? null) : null;

        if ($value === null) {
            return $default;
        }

        if (is_callable($value)) {
            try {
                $user  = $userId > 0 ? new \Pramnos\User\User($userId) : \Pramnos\User\User::getCurrentUser();
                $value = $value($user);
            } catch (\Throwable $exception) {
                // A per-role ceiling is a convenience; failing to resolve the role must not
                // break the tool it was meant to narrow.
                \Pramnos\Logs\Logger::log('Could not resolve the analyst\'s "' . $key . '" limit: ' . $exception->getMessage(), 'llm');

                return $default;
            }
        }

        return is_numeric($value) ? $value : $default;
    }
}
