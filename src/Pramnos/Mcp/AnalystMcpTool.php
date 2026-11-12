<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * The analyst's own read tools on the MCP endpoint, each behind the scope the application gives
 * it — the same tool the Ask page has, answering for the person the token is theirs:
 *
 * ```php
 * // app/app.php
 * 'mcp' => ['analyst' => [
 *     'visits'  => 'myapp:admin.read',
 *     'metrics' => 'myapp:admin.read',
 *     'goals'   => 'myapp:admin.read',
 * ]],
 * ```
 *
 * Without a scope a tool stays on the Ask page alone, as before. Only the tools that read are
 * offered: changing goals or to-dos waits for a person's approval on the Ask page, which an MCP
 * call has no page for. A tool is offered where the analyst would offer it — the site's traffic
 * where it is counted, metrics where the application declares any, and so on.
 */
final class AnalystMcpTool implements ScopedMcpTool
{
    /** The tools that may be offered, by the key `mcp.analyst` names them with. */
    public const TOOLS = [
        'visits'               => \Pramnos\Llm\Analyst\Tools\VisitsTool::class,
        'metrics'              => \Pramnos\Llm\Analyst\Tools\MetricsTool::class,
        'goals'                => \Pramnos\Llm\Analyst\Tools\GoalsTool::class,
        'todos'                => \Pramnos\Llm\Analyst\Tools\TodosTool::class,
        'conversation_summary' => \Pramnos\Llm\Analyst\Tools\ConversationSummaryTool::class,
    ];

    /**
     * @param class-string<McpToolInterface> $class The analyst's tool
     * @param string                         $scope The scope a token must hold for it
     */
    public function __construct(private readonly string $class, private readonly string $scope)
    {
    }

    /**
     * The tools `mcp.analyst` gives a scope to, and that the analyst would offer here.
     *
     * @param array<string, mixed> $info The application's settings
     * @return list<self>
     */
    public static function configured(array $info): array
    {
        $tools = [];
        foreach ((array) ($info['mcp']['analyst'] ?? []) as $key => $scope) {
            if (!is_string($scope) || trim($scope) === '' || !isset(self::TOOLS[$key]) || !self::offered((string) $key)) {
                continue;
            }
            $tools[] = new self(self::TOOLS[$key], trim($scope));
        }

        return $tools;
    }

    /** Whether the analyst would offer this tool here. */
    private static function offered(string $key): bool
    {
        try {
            return match ($key) {
                'visits'               => \Pramnos\Visits\Visits::sites() !== [],
                'metrics', 'goals'     => \Pramnos\Metrics\Metrics::all() !== [],
                'todos'                => \Pramnos\Todos\Todos::offers('tools'),
                'conversation_summary' => \Pramnos\Conversations\Conversations::installed(),
                default                => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    /** The analyst's tool, for the person the request is: the token's. */
    private function tool(): McpToolInterface
    {
        $person = \Pramnos\Http\RequestIdentity::user();

        return new ($this->class)((int) ($person->userid ?? 0));
    }

    /** The analyst's tool's name. */
    public function name(): string
    {
        return $this->tool()->name();
    }

    /** The analyst's tool's description. */
    public function description(): string
    {
        return $this->tool()->description();
    }

    /** @return array<string, mixed> The analyst's tool's input */
    public function inputSchema(): array
    {
        return $this->tool()->inputSchema();
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): mixed
    {
        return $this->tool()->execute($input);
    }

    /** The scope the application gave it. */
    public function requiredScope(): string
    {
        return $this->scope;
    }
}
