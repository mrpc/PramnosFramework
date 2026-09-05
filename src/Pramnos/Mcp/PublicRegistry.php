<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * The tools an application offers to an authenticated caller outside this machine.
 *
 * Deliberately **not** the same collection the internal server uses. That one is populated by
 * `McpServiceProvider` with twenty development tools — coverage, docs, the style checker, the
 * local database — and filtering a shared list would mean every future tool is public until
 * somebody remembers to exclude it. The default has to be the safe one, so these are two
 * collections and a tool is in this one only because an application put it here.
 *
 * With one exception, registered by {@see \Pramnos\Application\Application::init()}:
 * {@see \Pramnos\Mcp\Tools\WhoAmITool}, which reports the id and scopes of the token the
 * call arrived with. It discloses nothing the caller did not already present, and it is
 * what makes the endpoint testable — an empty tool list cannot be told apart from a broken
 * one. `PublicRegistry::remove('whoami')` withdraws it.
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
     * Offer a tool without writing a class for it.
     *
     * The short door. {@see ScopedMcpTool} is five methods, and most of what an application wants
     * to offer is a name, a sentence and a closure — a class file for three lines of logic is the
     * reason capabilities do not get offered.
     *
     * ```php
     * PublicRegistry::offer(
     *     name:        'station-health',
     *     scope:       'user',
     *     description: 'Report the last successful stream check for a station.',
     *     input:       ['station_id' => 'integer', 'verbose' => 'boolean?'],
     *     handler:     fn (array $in) => Station::health((int) $in['station_id']),
     * );
     * ```
     *
     * Write the class instead when the tool has state, needs injecting, or is worth unit-testing
     * on its own. Both doors lead to the same registry and neither is the "real" one.
     *
     * @param array<string, mixed>          $input   Compact spec, or a full JSON Schema
     * @param callable(array<string, mixed>): mixed $handler
     */
    public static function offer(
        string $name,
        string $scope,
        string $description,
        array $input,
        callable $handler
    ): void {
        self::add(new class ($name, $scope, $description, $input, $handler) implements ScopedMcpTool {
            /** @param array<string, mixed> $input */
            public function __construct(
                private string $toolName,
                private string $scope,
                private string $description,
                private array $input,
                private $handler
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->description;
            }

            public function inputSchema(): array
            {
                return PublicRegistry::schema($this->input);
            }

            public function execute(array $input): mixed
            {
                return ($this->handler)($input);
            }

            public function requiredScope(): string
            {
                return $this->scope;
            }
        });
    }

    /**
     * A compact input spec as JSON Schema — or a JSON Schema, unchanged.
     *
     * `['station_id' => 'integer', 'verbose' => 'boolean?']` is the same document as fourteen
     * lines of nested arrays, and the fourteen lines are where people stop. A trailing `?` marks
     * a parameter optional; everything else is required, because required-by-default is the
     * safer mistake — a model omitting a parameter the tool needs gets a clear refusal, where a
     * tool silently running without one gets a wrong answer nobody questions.
     *
     * **A spec carrying a top-level `type` is passed through untouched**, so anything the compact
     * form cannot say — nested objects, enums, patterns — is written as ordinary JSON Schema and
     * nothing here interferes. There is no wall to hit.
     *
     * A value that is itself an array is a full property definition and is kept as it is, so one
     * awkward parameter does not force the whole spec into longhand.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function schema(array $input): array
    {
        if (isset($input['type'])) {
            return $input;
        }

        $properties = [];
        $required   = [];

        foreach ($input as $property => $spec) {
            if (is_array($spec)) {
                $properties[$property] = $spec;

                continue;
            }

            $type = trim((string) $spec);

            if (str_ends_with($type, '?')) {
                $type = substr($type, 0, -1);
            } else {
                $required[] = $property;
            }

            $properties[$property] = ['type' => $type];
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
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

    /**
     * The scopes the currently-offered tools ask for.
     *
     * Read by {@see \Pramnos\Auth\Scopes} so that an MCP scope is grantable — and
     * advertised in `scopes_supported` — **only while a tool actually asks for it**.
     *
     * That matters because `scopes_supported` is served from
     * `/.well-known/oauth-authorization-server`, to anybody, with the scope's
     * description attached. A permanently-registered `mcp:db_read` would announce to
     * the internet that this site has a tool which runs SELECTs against its live
     * database, on every installation, including the ones that never offered it.
     * Deriving the list from what is registered means an installation discloses a
     * capability exactly when it has one.
     *
     * @return list<string>
     */
    public static function scopesInUse(): array
    {
        $scopes = array();

        foreach (self::$tools as $tool) {
            $scope = trim($tool->requiredScope());

            if ($scope !== '') {
                $scopes[$scope] = true;
            }
        }

        return array_keys($scopes);
    }

    /** Is anything offered at all? */
    public static function hasTools(): bool
    {
        return self::$tools !== [];
    }

    /**
     * Withdraw a tool by name.
     *
     * For an application that wants one of the framework's public tools gone —
     * `whoami` is the only one — without {@see reset()}, which would take its own
     * with it.
     *
     * @param  string $name The tool's `name()`.
     * @return bool   Whether there was one to withdraw.
     */
    public static function remove(string $name): bool
    {
        if (!isset(static::$tools[$name])) {
            return false;
        }

        unset(static::$tools[$name]);

        return true;
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
