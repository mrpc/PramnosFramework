<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Mcp\McpToolInterface;

/**
 * Tools declared once, with what the runtime needs to know about each — and handed to
 * {@see Client::askWithTools()} as the plain arrays it takes, for one person and the skills in use.
 *
 * ```php
 * $registry = (new ToolRegistry())
 *     ->registerMcp(new ExportCsvTool(), ['idempotent' => true, 'permissions' => ['admin.reports'], 'skills' => ['reporting'], 'latency_tier' => 'slow'])
 *     ->register('send_email', $send, ['writes' => true, 'skills' => ['mail']], 'Send an email', $schema)
 *     ->describeSkill('reporting', 'Reports and exports of the site\'s figures')
 *     ->describeSkill('mail', 'Writing and sending email');
 *
 * $tools = $registry->tools(['userid' => 7], skills: ['reporting']);
 * ```
 *
 * | Metadata | Default | What reads it |
 * |---|---|---|
 * | `writes` | false | changes something: held after untrusted reads, made once per answer, gated by a `GrantGate` |
 * | `idempotent` | false | the same call gives the same answer and changes nothing; with `writes` false it runs at once with others (`parallel`) |
 * | `parallel` | `!writes && idempotent` | said outright, over the default |
 * | `untrusted` | false | returns words somebody else wrote: data, not instructions |
 * | `gated` | false | needs a grant even though it does not write |
 * | `permissions` | none | abilities the person must hold, every one (`AdminAccess::allows()`, or the registry's own check) |
 * | `skills` | none | offered only while one of these skills is in use; a tool with none is always offered |
 * | `tags` | none | data tags for the data rules, as {@see Tools::tag()} |
 * | `cost_weight`, `latency_tier` | `low`, `fast` | kept on the tool's `meta`, for an application's own estimates |
 *
 * **Skills, a few at a time.** {@see skillTool()} is a `use_skill` tool the model calls to turn a
 * skill on: its tools are added to the answer from the next round. A prompt names what there is
 * with {@see manifest()}. So a model starts with a short list and asks for more, rather than
 * reading every tool's schema on every round.
 */
final class ToolRegistry
{
    /** @var array<string, array{run: callable, description: string, parameters: array<string, mixed>, meta: array<string, mixed>, own?: array<string, mixed>}> */
    private array $tools = [];

    /** @var array<string, string> What each skill is for, by name */
    private array $skillNotes = [];

    /** @var (callable(string, array<string, mixed>): bool)|null */
    private $can;

    /**
     * @param (callable(string $ability, array<string, mixed> $context): bool)|null $can Whether the
     *        context's person holds an ability; null for the administration's permissions
     */
    public function __construct(?callable $can = null)
    {
        $this->can = $can;
    }

    /**
     * Declare a tool: `$run` gets the decoded arguments and returns text, or anything JSON can carry.
     *
     * @param array<string, mixed> $meta       See the table above
     * @param array<string, mixed> $parameters JSON schema of the arguments
     */
    public function register(string $name, callable $run, array $meta = [], string $description = '', array $parameters = []): static
    {
        $this->tools[$name] = ['run' => $run, 'description' => $description, 'parameters' => Tools::schema($parameters), 'meta' => $meta];

        return $this;
    }

    /**
     * Declare an MCP tool under its own name, description and schema.
     *
     * @param array<string, mixed> $meta
     */
    public function registerMcp(McpToolInterface $tool, array $meta = []): static
    {
        return $this->register($tool->name(), static fn (array $arguments): mixed => $tool->execute($arguments), $meta, $tool->description(), $tool->inputSchema());
    }

    /**
     * Declare tools already in the client's shape — an assistant's, or `Tools::fromMcp()`'s —
     * their own flags kept, under `$meta` for what they do not say.
     *
     * @param array<string, array<string, mixed>> $tools
     * @param array<string, mixed>                $meta
     */
    public function add(array $tools, array $meta = []): static
    {
        foreach ($tools as $name => $tool) {
            if (!is_callable($tool['run'] ?? null)) {
                continue;
            }
            $flags = ['writes', 'idempotent', 'parallel', 'untrusted', 'gated', 'permissions', 'skills', 'tags', 'cost_weight', 'latency_tier'];
            $own   = array_intersect_key($tool, array_flip($flags));
            $this->tools[(string) $name] = [
                'run'         => $tool['run'],
                'description' => (string) ($tool['description'] ?? ''),
                'parameters'  => Tools::schema((array) ($tool['parameters'] ?? [])),
                'meta'        => $own + (array) ($tool['meta'] ?? []) + $meta,
                // What the registry does not read itself — `expands`, a tag's closure — goes back out as it came.
                'own'         => array_diff_key($tool, array_flip([...$flags, 'run', 'description', 'parameters', 'meta'])),
            ];
        }

        return $this;
    }

    /** Say what a skill is for, for {@see manifest()}. */
    public function describeSkill(string $skill, string $description): static
    {
        $this->skillNotes[$skill] = $description;

        return $this;
    }

    /** Whether a tool of this name is declared. */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * A tool's metadata, its defaults filled in.
     *
     * @return array<string, mixed>
     */
    public function metadata(string $name): array
    {
        $meta   = (array) ($this->tools[$name]['meta'] ?? []);
        $writes = (bool) ($meta['writes'] ?? false);

        return [
            'writes'       => $writes,
            'idempotent'   => (bool) ($meta['idempotent'] ?? false),
            'parallel'     => (bool) ($meta['parallel'] ?? (!$writes && !empty($meta['idempotent']))),
            'untrusted'    => (bool) ($meta['untrusted'] ?? false),
            'gated'        => (bool) ($meta['gated'] ?? false),
            'permissions'  => array_values(array_map('strval', (array) ($meta['permissions'] ?? []))),
            'skills'       => array_values(array_map('strval', (array) ($meta['skills'] ?? []))),
            'tags'         => (array) ($meta['tags'] ?? []),
            'cost_weight'  => (string) ($meta['cost_weight'] ?? 'low'),
            'latency_tier' => (string) ($meta['latency_tier'] ?? 'fast'),
        ];
    }

    /**
     * Every skill, with the tools in it.
     *
     * @return array<string, list<string>>
     */
    public function skills(): array
    {
        $skills = [];
        foreach (array_keys($this->tools) as $name) {
            foreach ($this->metadata($name)['skills'] as $skill) {
                $skills[$skill][] = $name;
            }
        }
        ksort($skills);

        return $skills;
    }

    /**
     * The tools this person may use, as the client takes them: without any whose permissions they
     * lack, and — when `$skills` is given — only those of these skills, beside the ones of none.
     *
     * @param array<string, mixed> $context The call's context: `userid` above all
     * @param list<string>|null    $skills  The skills in use; null for every tool
     * @return array<string, array<string, mixed>>
     */
    public function tools(array $context = [], ?array $skills = null): array
    {
        $out = [];
        foreach ($this->tools as $name => $tool) {
            $meta = $this->metadata($name);
            if (!$this->permitted($meta['permissions'], $context)
                || ($skills !== null && $meta['skills'] !== [] && array_intersect($meta['skills'], $skills) === [])) {
                continue;
            }
            $out[$name] = array_filter([
                'description' => $tool['description'],
                'parameters'  => $tool['parameters'],
                'run'         => $tool['run'],
                'writes'      => $meta['writes'] ?: null,
                'parallel'    => $meta['parallel'] ?: null,
                'untrusted'   => $meta['untrusted'] ?: null,
                'gated'       => $meta['gated'] ?: null,
                'tags'        => $meta['tags'] ?: null,
            ], static fn ($v): bool => $v !== null) + ['meta' => $meta] + ($tool['own'] ?? []);
        }

        return $out;
    }

    /**
     * What a prompt says about the skills this person can turn on: one line each, with what it is
     * for. Empty when there is none.
     *
     * @param array<string, mixed> $context
     */
    public function manifest(array $context = []): string
    {
        $lines = [];
        foreach ($this->available($context) as $skill => $names) {
            $note    = $this->skillNotes[$skill] ?? '';
            $lines[] = '- ' . $skill . ($note !== '' ? ': ' . $note : '') . ' (' . implode(', ', $names) . ')';
        }

        return $lines === [] ? '' : "Skills you can turn on with use_skill, when a question needs them:\n" . implode("\n", $lines);
    }

    /**
     * The `use_skill` tool: called with a skill's name, it adds that skill's tools to the answer
     * from the next round (`expands`, read by the client), and says which.
     *
     * @param array<string, mixed> $context
     * @return array<string, array<string, mixed>> `['use_skill' => …]`, or nothing when there is no skill to turn on
     */
    public function skillTool(array $context = []): array
    {
        $available = $this->available($context);
        if ($available === []) {
            return [];
        }

        return ['use_skill' => [
            'description' => 'Turn on a skill: its tools are yours from the next step. Skills: ' . implode('; ', array_map(
                fn (string $skill): string => $skill . (($this->skillNotes[$skill] ?? '') !== '' ? ' (' . $this->skillNotes[$skill] . ')' : ''),
                array_keys($available)
            )) . '.',
            'parameters'  => Tools::schema(['properties' => ['skill' => ['type' => 'string', 'enum' => array_keys($available)]], 'required' => ['skill']]),
            'run'         => static fn (array $arguments): string => isset($available[(string) ($arguments['skill'] ?? '')])
                ? 'Turned on ' . $arguments['skill'] . ': ' . implode(', ', $available[(string) $arguments['skill']]) . '.'
                : 'Error: there is no skill called ' . ($arguments['skill'] ?? '') . '.',
            // The skill's own tools: the ones of no skill are offered already.
            'expands'     => fn (array $arguments): array => isset($available[(string) ($arguments['skill'] ?? '')])
                ? array_intersect_key($this->tools($context, [(string) $arguments['skill']]), array_flip($available[(string) $arguments['skill']]))
                : [],
        ]];
    }

    /**
     * The skills this person may turn on — each with the tools of it they may use.
     *
     * @param array<string, mixed> $context
     * @return array<string, list<string>>
     */
    private function available(array $context): array
    {
        $available = [];
        foreach ($this->skills() as $skill => $names) {
            $mine = array_values(array_filter($names, fn (string $name): bool => $this->permitted($this->metadata($name)['permissions'], $context)));
            if ($mine !== []) {
                $available[$skill] = $mine;
            }
        }

        return $available;
    }

    /**
     * Whether the context's person holds every one of these abilities.
     *
     * @param list<string>         $abilities
     * @param array<string, mixed> $context
     */
    private function permitted(array $abilities, array $context): bool
    {
        foreach ($abilities as $ability) {
            if (!($this->can ?? self::administrationCan(...))($ability, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The administration's permissions, for the context's person — closed when there is nobody.
     *
     * @param array<string, mixed> $context
     */
    private static function administrationCan(string $ability, array $context): bool
    {
        $userId = (int) ($context['userid'] ?? 0);
        if ($userId < 2) {
            return false;
        }

        return \Pramnos\Auth\AdminAccess::allows(new \Pramnos\User\User($userId), $ability, \Pramnos\Auth\AdminAccess::defaultUsertype());
    }
}
