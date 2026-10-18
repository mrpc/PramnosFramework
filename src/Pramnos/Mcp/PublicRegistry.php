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

    /** @var list<callable(?object, ScopedMcpTool): bool> Who may see which tool, beside its scope */
    private static array $gates = [];

    /** @var list<callable(ScopedMcpTool, array<string, mixed>, callable(array<string, mixed>): mixed, ?object): mixed> Around every call */
    private static array $around = [];

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

    /**
     * The MCP scopes app.php declares, by name, with their descriptions:
     *
     * ```php
     * 'mcp' => ['scopes' => [
     *     'mcp:diagnostics', 'mcp:logs',                                 // the framework's, described by it
     *     'mcp:people' => 'Find and read the accounts of the people here.', // the application's own
     * ]],
     * ```
     *
     * For an installation whose authorization server and MCP endpoint are two processes: the one
     * that serves consent and the metadata holds none of the tools, and would refuse their scopes
     * as unknown. Both read app.php, so both know them. Only `mcp` and `mcp:*` are taken.
     *
     * @return array<string, string> Name to description, '' where the framework describes it
     */
    public static function declaredScopes(): array
    {
        $app      = \Pramnos\Application\Application::currentInstance();
        $declared = is_object($app) ? ($app->applicationInfo['mcp']['scopes'] ?? []) : [];
        $scopes   = [];
        foreach (is_array($declared) ? $declared : [] as $key => $value) {
            [$name, $description] = is_int($key) ? [(string) $value, ''] : [(string) $key, is_string($value) ? $value : ''];
            $name = trim($name);
            if ($name === 'mcp' || str_starts_with($name, 'mcp:')) {
                $scopes[$name] = trim($description);
            }
        }

        return $scopes;
    }

    /**
     * Every MCP scope this installation grants: those its registered tools ask for, and those
     * app.php declares — {@see declaredScopes()}.
     *
     * @return list<string>
     */
    public static function scopesOffered(): array
    {
        return array_values(array_unique([...self::scopesInUse(), ...array_keys(self::declaredScopes())]));
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
        self::$tools  = [];
        self::$gates  = [];
        self::$around = [];
    }

    /**
     * Decide, beside a tool's scope, whether a user sees it at all — the role, an organisation,
     * a flag. Every gate must allow it. A tool a gate refuses is not listed and answers as no
     * tool; one the gates allow but the token's scopes do not is listed nowhere, and a call to it
     * is told which scope it needs.
     *
     * ```php
     * PublicRegistry::gate(static fn (?object $user, ScopedMcpTool $tool): bool =>
     *     $tool->name() !== 'people' || Roles::atLeast($user, Roles::ROOT));
     * ```
     *
     * A {@see ScopedTool} wrapped with `minRole:` is gated by that role without one.
     *
     * @param callable(?object, ScopedMcpTool): bool $gate
     */
    public static function gate(callable $gate): void
    {
        self::$gates[] = $gate;
    }

    /**
     * Run something around every call of every public tool — redaction, an audit row — instead
     * of each application wrapping each tool. Hooks nest in the order added, the first outermost.
     *
     * ```php
     * PublicRegistry::around(PublicRegistry::audited());
     * PublicRegistry::around(PublicRegistry::redacting());
     * ```
     *
     * @param callable(ScopedMcpTool, array<string, mixed>, callable(array<string, mixed>): mixed, ?object): mixed $hook
     */
    public static function around(callable $hook): void
    {
        self::$around[] = $hook;
    }

    /**
     * The tools a user may reach, whatever the token's scopes: those every gate, and the tool's
     * own least role, allow.
     *
     * @return list<ScopedMcpTool>
     */
    public static function reachableBy(?object $user): array
    {
        return array_values(array_filter(self::$tools, static function (ScopedMcpTool $tool) use ($user): bool {
            if ($tool instanceof ScopedTool && $tool->minRole() !== null && !\Pramnos\User\Roles::atLeast($user, $tool->minRole())) {
                return false;
            }
            foreach (self::$gates as $gate) {
                if (!$gate($user, $tool)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The tools a user holding these scopes may call: reachable by them, permitted by the
     * scopes, each running through the {@see around()} hooks.
     *
     * @param list<string> $scopes
     * @return list<ScopedMcpTool>
     */
    public static function availableTo(array $scopes, ?object $user): array
    {
        $tools = [];
        foreach (self::reachableBy($user) as $tool) {
            if (self::permits($scopes, $tool->requiredScope())) {
                $tools[] = self::$around === [] ? $tool : self::hooked($tool, $user);
            }
        }

        return $tools;
    }

    /**
     * The scope a reachable tool needs that these scopes lack — for telling a caller that
     * reconnecting would help, where "no such tool" says nothing. Null when the tool is not
     * reachable by this user at all, or the scopes already permit it.
     *
     * @param list<string> $scopes
     */
    public static function missingScope(string $name, array $scopes, ?object $user): ?string
    {
        foreach (self::reachableBy($user) as $tool) {
            if ($tool->name() === $name) {
                return self::permits($scopes, $tool->requiredScope()) ? null : $tool->requiredScope();
            }
        }

        return null;
    }

    /**
     * A hook that redacts what a tool returns, as the analyst's tools are — email addresses to
     * their shape, tokens, cards, phone numbers and IP addresses replaced — through every string
     * in the result.
     *
     * @return callable(ScopedMcpTool, array<string, mixed>, callable(array<string, mixed>): mixed, ?object): mixed
     */
    public static function redacting(): callable
    {
        $clean = static function (mixed $value) use (&$clean): mixed {
            return match (true) {
                is_string($value) => \Pramnos\Llm\Analyst\Redactor::clean($value),
                is_array($value)  => array_map($clean, $value),
                default           => $value,
            };
        };

        // A tool that redacts itself — `user`, sparing the email it was asked for — is left as it answers.
        return static fn (ScopedMcpTool $tool, array $input, callable $next, ?object $user): mixed => in_array($tool->name(), \Pramnos\Llm\Analyst\Redactor::SELF_REDACTING, true)
            ? $next($input)
            : $clean($next($input));
    }

    /**
     * A hook that writes an audit row for every call: the event (`mcp.tool_call`), the user, the
     * account the call was about, the tool, the client the token was issued to, the names of the
     * parameters — not their values, which may be anything the model typed — and whether it
     * succeeded. Written once the call is over, whichever way it ended.
     *
     * The account it was about is what an audit screen filters "who looked at this person" by:
     * by default the input's `userid`, or its `id`; `$target` decides otherwise, from the tool and
     * the input, null for none.
     *
     * @param (callable(ScopedMcpTool, array<string, mixed>): ?int)|null $target
     * @return callable(ScopedMcpTool, array<string, mixed>, callable(array<string, mixed>): mixed, ?object): mixed
     */
    public static function audited(string $event = 'mcp.tool_call', ?callable $target = null): callable
    {
        $target ??= static function (ScopedMcpTool $tool, array $input): ?int {
            foreach (['userid', 'id'] as $key) {
                if (isset($input[$key]) && is_numeric($input[$key])) {
                    return (int) $input[$key];
                }
            }

            return null;
        };

        return static function (ScopedMcpTool $tool, array $input, callable $next, ?object $user) use ($event, $target): mixed {
            $ok = false;
            try {
                $result = $next($input);
                $ok     = true;

                return $result;
            } finally {
                $token  = \Pramnos\Http\Request::accessToken();
                $claims = $token !== null ? \Pramnos\Auth\JWT::decodeUnverified($token) : false;
                \Pramnos\Auth\AuditLog::record($event, isset($user->userid) ? (int) $user->userid : null, $target($tool, $input), [
                    'tool'       => $tool->name(),
                    'client'     => is_object($claims) ? (string) (is_array($claims->aud ?? null) ? ($claims->aud[0] ?? '') : ($claims->aud ?? '')) : '',
                    'parameters' => array_keys($input),
                    'ok'         => $ok,
                ], 'mcp_tool', $tool->name());
            }
        };
    }

    /** A tool whose calls run through the hooks, the first added outermost. */
    private static function hooked(ScopedMcpTool $tool, ?object $user): ScopedMcpTool
    {
        $hooks = self::$around;

        return new class ($tool, $hooks, $user) implements ScopedMcpTool {
            /** @param list<callable> $hooks */
            public function __construct(private ScopedMcpTool $tool, private array $hooks, private ?object $user)
            {
            }

            /** The tool's own name. */
            public function name(): string
            {
                return $this->tool->name();
            }

            /** The tool's own description. */
            public function description(): string
            {
                return $this->tool->description();
            }

            /** The tool's own schema. */
            public function inputSchema(): array
            {
                return $this->tool->inputSchema();
            }

            /** The tool's own scope. */
            public function requiredScope(): string
            {
                return $this->tool->requiredScope();
            }

            /** The call, through every hook. */
            public function execute(array $input): mixed
            {
                $next = fn (array $input): mixed => $this->tool->execute($input);
                foreach (array_reverse($this->hooks) as $hook) {
                    $inner = $next;
                    $next  = fn (array $input): mixed => $hook($this->tool, $input, $inner, $this->user);
                }

                return $next($input);
            }
        };
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
