<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * MCP tool: every command this CLI has, with its arguments.
 *
 * There are seventy-odd, and twenty of them generate code — `create:crud`, `create:screen`,
 * `create:api-client`, `create:policy`, `create:webhook`. The reason this tool exists is that
 * an assistant working in the codebase for a whole day does not find out: it writes a
 * controller by hand rather than running `create:controller`, because nothing told it the
 * command was there. `--help` on seventy commands is not a discovery mechanism.
 *
 * Read from the **live** console definition rather than a list kept here. A second catalogue
 * of commands would be a second thing to forget to update, and this project has already been
 * bitten by exactly that: `mcp:serve` once carried its own copy of the MCP tool list and went
 * stale while looking correct.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ConsoleCommandsTool implements McpToolInterface
{
    /**
     * Prefixes whose commands **write files into the project**.
     *
     * Worth flagging on its own line, because it changes whether a command can be run to see
     * what it does. Everything else here either reports or acts on the database, and the
     * difference between "shows me something" and "writes eleven files" should not have to be
     * inferred from a one-line description.
     *
     * @var list<string>
     */
    private const GENERATOR_PREFIXES = ['create', 'scaffold', 'init', 'project'];

    /**
     * Commands that generate code without a `create:` name.
     *
     * @var list<string>
     */
    private const ALSO_GENERATORS = ['api:docs', 'theme:build', 'spa:build'];

    public function name(): string
    {
        return 'console-commands';
    }

    public function description(): string
    {
        return 'List this application\'s CLI commands with their arguments, options and '
            . 'whether they generate files. Covers the twenty `create:*` code generators '
            . '(controller, model, migration, crud, api, api-client, screen, policy, '
            . 'webhook, test, seeder …) plus the build, migration, queue and maintenance '
            . 'commands. Ask this before writing a class by hand — there is probably a '
            . 'generator for it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Only commands whose name or description contains this. '
                        . 'A prefix like `create` narrows to the generators.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'One command, in full — returns its arguments, options '
                        . 'and help text.',
                ],
                'generators' => [
                    'type' => 'boolean',
                    'description' => 'Only the commands that write files.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $filter     = strtolower(trim((string) ($input['filter'] ?? '')));
        $wanted     = trim((string) ($input['name'] ?? ''));
        $generators = (bool) ($input['generators'] ?? false);

        try {
            $commands = $this->commands();
        } catch (\Throwable $exception) {
            return ['error' => 'Could not read the console: ' . $exception->getMessage()];
        }

        if ($wanted !== '') {
            foreach ($commands as $command) {
                if ($command->getName() === $wanted) {
                    return $this->detail($command);
                }
            }

            return [
                'error' => 'No command named ' . $wanted . '.',
                'names' => array_values(array_filter(array_map(
                    static fn (Command $c): ?string => $c->getName(),
                    $commands
                ))),
            ];
        }

        $groups = [];
        $total  = 0;

        foreach ($commands as $command) {
            $name = (string) $command->getName();

            if ($name === '' || $command->isHidden()) {
                continue;
            }

            $isGenerator = $this->isGenerator($name);

            if ($generators && !$isGenerator) {
                continue;
            }

            $description = $command->getDescription();

            if ($filter !== ''
                && !str_contains(strtolower($name), $filter)
                && !str_contains(strtolower($description), $filter)
            ) {
                continue;
            }

            // Grouped by prefix, because that is how they are actually related — every
            // `create:` is the same kind of act, and a flat list of seventy names is read as
            // seventy unrelated things.
            $group = str_contains($name, ':') ? explode(':', $name, 2)[0] : '(top level)';

            $groups[$group][] = array_filter([
                'name'        => $name,
                'description' => $description,
                'usage'       => $this->usage($command),
                'generates'   => $isGenerator ? true : null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $total++;
        }

        ksort($groups);

        return [
            'count'  => $total,
            'groups' => $groups,
            'note'   => 'Ask again with `name` for one command\'s full options. Commands '
                . 'marked `generates` write files into the project.',
        ];
    }

    /**
     * One command in full.
     *
     * @return array<string, mixed>
     */
    private function detail(Command $command): array
    {
        $definition = $command->getDefinition();
        $arguments  = [];
        $options    = [];

        foreach ($definition->getArguments() as $argument) {
            $arguments[] = array_filter([
                'name'        => $argument->getName(),
                'required'    => $argument->isRequired() ? true : null,
                'array'       => $argument->isArray() ? true : null,
                'description' => $argument->getDescription(),
                'default'     => $this->scalar($argument->getDefault()),
            ], static fn ($value): bool => $value !== null && $value !== '');
        }

        foreach ($definition->getOptions() as $option) {
            $options[] = array_filter([
                'name'        => '--' . $option->getName(),
                'shortcut'    => $option->getShortcut() !== null ? '-' . $option->getShortcut() : null,
                'value'       => $this->optionValue($option),
                'description' => $option->getDescription(),
                'default'     => $this->scalar($option->getDefault()),
            ], static fn ($value): bool => $value !== null && $value !== '');
        }

        return array_filter([
            'name'        => $command->getName(),
            'description' => $command->getDescription(),
            'usage'       => $this->usage($command),
            'generates'   => $this->isGenerator((string) $command->getName()) ? true : null,
            'arguments'   => $arguments,
            'options'     => $options,
            // The class, so `find-symbol` is the obvious next question when the description
            // is not enough. The two tools are meant to be used together.
            'class'       => $command::class,
            'help'        => trim($command->getHelp()),
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** A one-line synopsis, the way `--help` would print it. */
    private function usage(Command $command): string
    {
        $usage = (string) $command->getName();

        foreach ($command->getDefinition()->getArguments() as $argument) {
            $usage .= $argument->isRequired()
                ? ' <' . $argument->getName() . '>'
                : ' [' . $argument->getName() . ']';
        }

        return $usage;
    }

    private function optionValue(InputOption $option): string
    {
        return match (true) {
            $option->isArray()          => 'repeatable value',
            $option->isValueRequired()  => 'value required',
            $option->isValueOptional()  => 'value optional',
            default                     => 'flag',
        };
    }

    /**
     * A default worth printing.
     *
     * Symfony hands back `false` for every flag and `null` for most values; printing those
     * turns a readable list into a column of noise.
     */
    private function scalar(mixed $default): string|int|float|null
    {
        if ($default === null || $default === false || $default === [] || $default === '') {
            return null;
        }

        if (is_bool($default)) {
            return 'true';
        }

        return is_scalar($default) ? $default : null;
    }

    private function isGenerator(string $name): bool
    {
        if (in_array($name, self::ALSO_GENERATORS, true)) {
            return true;
        }

        $prefix = str_contains($name, ':') ? explode(':', $name, 2)[0] : $name;

        return in_array($prefix, self::GENERATOR_PREFIXES, true);
    }

    /**
     * Every registered command.
     *
     * A fresh console rather than the running one: this tool is served by `mcp:serve`, whose
     * own console instance is mid-execution, and asking it for its command list while it is
     * running one is a needless dependency on that being safe.
     *
     * @return list<Command>
     */
    private function commands(): array
    {
        $console = new \Pramnos\Console\Application('Pramnos');

        return array_values($console->all());
    }
}
