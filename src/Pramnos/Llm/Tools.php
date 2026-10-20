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
     * A JSON schema as a provider — or an MCP client — takes it.
     *
     * An empty `properties` must go out as an object, at the top and in every nested object
     * schema: `[]` encodes as a list, and the reader refuses the schema. An MCP client refuses
     * the whole `tools/list` for one such schema, and shows no tools at all. The top level must
     * also say it is an object.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function schema(array $schema): array
    {
        $schema['properties'] ??= [];

        return self::objects($schema) + ['type' => 'object'];
    }

    /**
     * Every `properties` under this schema node as an object, empty ones included, down through
     * the properties and the `items` of an array.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function objects(array $node): array
    {
        if (array_key_exists('properties', $node)) {
            $properties         = (array) $node['properties'];
            $node['properties'] = $properties === []
                ? new \stdClass()
                : array_map(static fn ($p) => is_array($p) ? self::objects($p) : $p, $properties);
        }
        if (is_array($node['items'] ?? null)) {
            $node['items'] = self::objects($node['items']);
        }

        return $node;
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
