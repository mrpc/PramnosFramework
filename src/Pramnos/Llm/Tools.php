<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Mcp\McpToolInterface;

/**
 * Tools for {@see Client::askWithTools()}, as the plain arrays it takes.
 *
 * A tool is `name => ['description' => …, 'parameters' => JSON schema, 'run' => callable]`,
 * where `run` gets the decoded arguments and returns text, and what it throws is told to the
 * model as an error rather than ending the answer. A tool may also carry `'tags' => [tag => flag]`,
 * where the flag is `true` or a `Closure(string $result, array $arguments): bool` — see
 * {@see Policy\DataRule}.
 *
 * ```php
 * $tools = Tools::fromMcp(PublicRegistry::visibleTo(['mcp']), skip: ['whoami']);
 * Tools::tag($tools, 'analytics-report', 'google');
 * Tools::tag($tools, 'articles', 'google', fn (string $result, array $args) => ($args['id'] ?? 0) > 0);
 * $answer = (new Client())->askWithTools($prompt, $messages, $tools);
 * ```
 */
final class Tools
{
    /**
     * MCP tools — anything implementing `McpToolInterface`, a `ScopedMcpTool` included.
     *
     * @param iterable<McpToolInterface> $tools
     * @param list<string>               $skip  Names to leave out
     * @return array<string, array<string, mixed>>
     */
    public static function fromMcp(iterable $tools, array $skip = []): array
    {
        $out = [];

        foreach ($tools as $tool) {
            if (!$tool instanceof McpToolInterface || in_array($tool->name(), $skip, true)) {
                continue;
            }

            $out[$tool->name()] = [
                'description' => $tool->description(),
                'parameters'  => self::schema($tool->inputSchema()),
                'run'         => static fn (array $arguments): string => self::text($tool->execute($arguments)),
            ];
        }

        return $out;
    }

    /**
     * Mark a tool's result as carrying data of a kind.
     *
     * @param array<string, array<string, mixed>> $tools
     * @param bool|\Closure(string, array<string, mixed>): bool $when Always, or by what it returned
     */
    public static function tag(array &$tools, string $name, string $tag, bool|\Closure $when = true): void
    {
        if (isset($tools[$name])) {
            $tools[$name]['tags'][$tag] = $when;
        }
    }

    /**
     * A JSON schema as a provider takes it.
     *
     * An empty `properties` must go out as an object — `[]` encodes as a list, and the provider
     * refuses the schema — and the top level must say it is an object.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function schema(array $schema): array
    {
        if (empty((array) ($schema['properties'] ?? []))) {
            $schema['properties'] = new \stdClass();
        }

        return $schema + ['type' => 'object'];
    }

    /** What a tool returned, as the text a model reads. */
    public static function text(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The tags a tool's result carries.
     *
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public static function tagsOf(array $tool, string $result, array $arguments): array
    {
        $carried = [];

        foreach ((array) ($tool['tags'] ?? []) as $tag => $when) {
            if ($when instanceof \Closure ? (bool) $when($result, $arguments) : (bool) $when) {
                $carried[] = (string) $tag;
            }
        }

        return $carried;
    }
}
