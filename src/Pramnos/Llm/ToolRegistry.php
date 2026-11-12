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
 * | `numeric` | false | answers with figures (a report, a count): the answer's numbers are held to them — {@see Figures} |
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

    /** @var array<string, string> A skill's instructions, by name: the method its use_skill hands the model */
    private array $skillBodies = [];

    /** @var array<string, list<string>> Tools a skill opens beside those whose metadata names it, by skill */
    private array $skillTools = [];

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
        // A tool that answers with figures says so: `public function numeric(): bool` ({@see Figures}).
        $meta += method_exists($tool, 'numeric') && $tool->numeric() === true ? ['numeric' => true] : [];

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
            $flags = ['writes', 'idempotent', 'parallel', 'untrusted', 'numeric', 'gated', 'permissions', 'skills', 'tags', 'cost_weight', 'latency_tier'];
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

    /**
     * Declare a skill as a file declares one: what it is for, the tools it opens beside those whose
     * metadata names it, and its instructions — handed to the model by `use_skill` when the skill is
     * turned on, so a method is in the context only when it is needed. A skill with instructions
     * and no tool this person may use is still offered; one with neither is not.
     *
     * The instructions are the application's own configuration, trusted as a system prompt is.
     *
     * @param list<string> $tools Tool names; one not declared here is ignored
     */
    public function defineSkill(string $skill, string $description = '', array $tools = [], string $instructions = ''): static
    {
        if ($description !== '') {
            $this->skillNotes[$skill] = $description;
        }
        $this->skillTools[$skill]  = array_values(array_unique(array_merge($this->skillTools[$skill] ?? [], array_map('strval', $tools))));
        $this->skillBodies[$skill] = trim($instructions);

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
            'numeric'      => (bool) ($meta['numeric'] ?? false),
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
        // A declared skill's own list, and a skill of instructions only, with no tool at all.
        foreach ($this->skillTools as $skill => $names) {
            $skills[$skill] = array_values(array_unique(array_merge($skills[$skill] ?? [], array_filter($names, fn (string $n): bool => isset($this->tools[$n])))));
        }
        ksort($skills);

        return $skills;
    }

    /** The skills a tool is in: its metadata's, and those that list it. @return list<string> */
    private function skillsOf(string $name): array
    {
        $skills = $this->metadata($name)['skills'];
        foreach ($this->skillTools as $skill => $names) {
            if (in_array($name, $names, true)) {
                $skills[] = $skill;
            }
        }

        return array_values(array_unique($skills));
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
            // A tool its metadata puts in no skill is offered always, a skill that lists it
            // notwithstanding: listing it says what the skill opens, not that it is now held back.
            if (!$this->permitted($meta['permissions'], $context)
                || ($skills !== null && $meta['skills'] !== [] && array_intersect($this->skillsOf($name), $skills) === [])) {
                continue;
            }
            $out[$name] = array_filter([
                'description' => $tool['description'],
                'parameters'  => $tool['parameters'],
                'run'         => $tool['run'],
                'writes'      => $meta['writes'] ?: null,
                'parallel'    => $meta['parallel'] ?: null,
                'untrusted'   => $meta['untrusted'] ?: null,
                'numeric'     => $meta['numeric'] ?: null,
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
            $lines[] = '- ' . $skill . ($note !== '' ? ': ' . $note : '') . ($names !== [] ? ' (' . implode(', ', $names) . ')' : '');
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
            'run'         => fn (array $arguments): string => isset($available[(string) ($arguments['skill'] ?? '')])
                ? $this->turnedOn((string) $arguments['skill'], $available[(string) $arguments['skill']])
                : 'Error: there is no skill called ' . ($arguments['skill'] ?? '') . '.',
            // The skill's own tools: the ones of no skill are offered already.
            'expands'     => fn (array $arguments): array => isset($available[(string) ($arguments['skill'] ?? '')])
                ? array_intersect_key($this->tools($context, [(string) $arguments['skill']]), array_flip($available[(string) $arguments['skill']]))
                : [],
        ]];
    }

    /**
     * What `use_skill` answers: the tools turned on, then the skill's instructions, if it has any.
     *
     * @param list<string> $tools
     */
    private function turnedOn(string $skill, array $tools): string
    {
        $said = 'Turned on ' . $skill . ($tools !== [] ? ': ' . implode(', ', $tools) : '') . '.';
        $body = $this->skillBodies[$skill] ?? '';

        return $body !== '' ? $said . "\n\n" . $body : $said;
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
            // Instructions alone are worth turning on — a method needs no tool; neither is not.
            if ($mine !== [] || ($this->skillBodies[$skill] ?? '') !== '') {
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
