<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * The tools an application offers to an authenticated caller outside this machine.
 *
 * Deliberately **not** the same collection the internal server uses. That one is populated by
 * `McpServiceProvider` with nineteen development tools — coverage, docs, the style checker — and
 * filtering a shared list would mean every future tool is public until somebody remembers to
 * exclude it. The default has to be the safe one, so these are two collections and a tool is in
 * this one only because an application put it here.
 *
 * ```php
 * // app/providers or a ServiceProvider
 * PublicRegistry::add(new \App\Mcp\OrdersTool());
 * ```
 *
 * ### What it refuses
 *
 * A tool that does not implement {@see ScopedMcpTool} cannot be added. There is no flag to
 * override that: a tool with no declared scope has no answer to "who may call this", and the
 * only safe answer to an unanswered question at an authenticated endpoint is no.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class PublicRegistry
{
    /** @var array<string, ScopedMcpTool> */
    private static array $tools = [];

    /**
     * Offer a tool publicly.
     *
     * @throws \InvalidArgumentException when the tool declares no scope
     */
    public static function add(ScopedMcpTool $tool): void
    {
        $scope = trim($tool->requiredScope());

        if ($scope === '') {
            /*
             * Thrown rather than skipped.
             *
             * A tool quietly dropped at registration is a tool that is absent at run time for a
             * reason nobody can see — the endpoint answers "no such tool" and the author goes
             * looking in the wrong place. This is a programming error and it fails at boot.
             */
            throw new \InvalidArgumentException(
                $tool->name() . ' declares no scope, so nothing can decide who may call it.'
            );
        }

        self::$tools[$tool->name()] = $tool;
    }

    /**
     * The tools a caller holding these scopes may see.
     *
     * @param  list<string> $scopes The scopes on the caller's token
     * @return list<ScopedMcpTool>
     */
    public static function visibleTo(array $scopes): array
    {
        return array_values(array_filter(
            self::$tools,
            static fn (ScopedMcpTool $tool): bool => self::permits($scopes, $tool->requiredScope())
        ));
    }

    /** Is anything offered at all? */
    public static function hasTools(): bool
    {
        return self::$tools !== [];
    }

    /** Forget everything. For tests, and for an application that rebuilds its own registry. */
    public static function reset(): void
    {
        self::$tools = [];
    }

    /**
     * Does this set of scopes satisfy the one a tool asks for?
     *
     * `*` is the wildcard a same-origin session carries, which `UnifiedAuthMiddleware` grants to
     * a cookie rather than a bearer token. Inheritance is resolved through `Scopes`, so a token
     * holding a parent scope satisfies a tool asking for a child — the same rule the router
     * applies, rather than a second one that drifts from it.
     *
     * @param list<string> $scopes
     */
    private static function permits(array $scopes, string $required): bool
    {
        if (in_array('*', $scopes, true)) {
            return true;
        }

        if (in_array($required, $scopes, true)) {
            return true;
        }

        try {
            return in_array($required, \Pramnos\Auth\Scopes::resolveInheritedScopes($scopes), true);
        } catch (\Throwable) {
            // A scope table that cannot be read is not permission to proceed.
            return false;
        }
    }
}
