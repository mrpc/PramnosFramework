<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * create:command — scaffold a new Symfony Console command class.
 *
 * It writes into the directory `init` scaffolded, names the command the way the
 * console guide's taxonomy says, and registers it — three things it did not do,
 * each of which left the project slightly wrong in a way nothing failed on:
 *
 *   - **Where.** It wrote `src/Console/Commands/`; `init` writes
 *     `src/ConsoleCommands/`, and `src/Console.php` — the file that has to
 *     `add()` the command — invites the next one there by name. Both namespaces
 *     autoload, so nothing broke: a project simply ended up with commands in two
 *     places and a convention that depended on which tool wrote the file.
 *   - **What it is called.** `CollectChannels` became `app:collectchannels`, a
 *     lower-cased concatenation, against the `namespace:verb` convention the
 *     console guide documents at the top of its own command table.
 *   - **Being reachable.** Nothing registered it, so the command did not appear
 *     in `<cli> list` until somebody added the line by hand — and the one thing
 *     a generated command must be is runnable.
 *
 * Usage:
 *   php pramnos create:command CollectChannels                  → channels:collect
 *   php pramnos create:command CollectChannels --command-name=feeds:pull
 */
class MakeCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:command');
        $this->setDescription('Create a console command class');
        $this->addCommonOptions();
        $this->addOption(
            'command-name',
            null,
            InputOption::VALUE_REQUIRED,
            'The CLI name, e.g. channels:collect. Derived from the class name when omitted.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: command');
        }
        $output->writeln($this->createConsoleCommand($name, (string) $input->getOption('command-name')));
        return 0;
    }

    /**
     * Create a console command class from the command.stub template.
     *
     * @param  string $commandName PascalCase class name (e.g. CollectChannels)
     * @param  string $cliName     An explicit CLI name; derived when empty
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createConsoleCommand(string $commandName, string $cliName = ''): string
    {
        $application = $this->getApplication()->internalApplication;

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $commandName));
        if ($className === '') {
            throw new \InvalidArgumentException('Command name must be a valid PHP class name.');
        }

        [$relative, $subNamespace] = $this->commandsLocation();

        $root = defined('ROOT') ? ROOT : getcwd();
        $dir  = $root . '/' . $relative;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Command $className already exists at $filename.");
        }

        $fullNamespace = $namespace . '\\' . $subNamespace;
        $commandLine   = $cliName !== '' ? $cliName : self::deriveCliName($className);

        $stub = $this->renderStub('command', [
            'namespace'    => $fullNamespace,
            'class'        => $className,
            'command_name' => $commandLine,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write command file: $filename");
        }

        $testOutput = $this->writeTestStub($root, $className, $fullNamespace, $commandLine);

        return "Namespace: {$fullNamespace}\n"
            . "Class:     {$className}\n"
            . "Command:   {$commandLine}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . $this->registerInConsole($root, $fullNamespace, $className)
            . "\nCommand created.";
    }

    /**
     * Write the command's test, which asserts something.
     *
     * The shared `generateTestStub()` renders `test.stub`, whose whole body is
     * `assertTrue(true)` — a passing test with no subject, and in a project with a
     * coverage floor a hole the report cannot see. A console command has two things
     * worth pinning from the first minute and they need the command's *name*, which
     * that helper has no way to pass. Rendered here rather than by widening a public
     * signature every generator shares.
     *
     * @return string A line for the command's output.
     */
    private function writeTestStub(
        string $root,
        string $className,
        string $fullNamespace,
        string $commandLine
    ): string {
        $testsDir = $root . '/tests/Unit';
        if (!is_dir($testsDir)) {
            @mkdir($testsDir, 0777, true);
        }

        $testFile = $testsDir . '/' . $className . 'CommandTest.php';
        if (file_exists($testFile)) {
            return '';
        }

        $stub = $this->renderStub('command-test', [
            'class'        => $className . 'Command',
            'shortclass'   => $className,
            'namespace'    => $fullNamespace,
            'command_name' => $commandLine,
        ]);

        return file_put_contents($testFile, $stub) !== false
            ? "Test:      {$testFile}\n"
            : '';
    }

    /**
     * Where this project keeps its console commands.
     *
     * Discovered rather than declared: `init` writes `src/ConsoleCommands/`, and a
     * project scaffolded before that — or one that moved them — keeps whatever it
     * has. Writing into a second tree is not an error anything reports; it is a
     * convention quietly splitting in two.
     *
     * @return array{0: string, 1: string} The path relative to ROOT, and the
     *                                     namespace suffix that matches it.
     */
    private function commandsLocation(): array
    {
        $root = defined('ROOT') ? ROOT : getcwd();

        foreach ([
            ['src/ConsoleCommands', 'ConsoleCommands'],
            ['src/Console/Commands', 'Console\\Commands'],
        ] as [$relative, $suffix]) {
            if (is_dir($root . '/' . $relative)) {
                return [$relative, $suffix];
            }
        }

        // Neither exists yet: the one `init` writes, so the first generated
        // command lands beside where the next scaffold would put it.
        return ['src/ConsoleCommands', 'ConsoleCommands'];
    }

    /**
     * The CLI name for a class, as `noun:verb`.
     *
     * The console guide's taxonomy groups commands by what they act on —
     * `queue:process`, `user:create`, `schedule:run` — and the first word of a
     * PascalCase class name is nearly always the verb: `CollectChannels` is
     * `channels:collect`, `SyncStock` is `stock:sync`.
     *
     * One word has no noun to group under, so it keeps `app:`. A guess is still a
     * guess, which is what `--command-name` is for; what it replaces is
     * `app:collectchannels`, which was not a guess but a concatenation.
     */
    public static function deriveCliName(string $className): string
    {
        $base = preg_replace('/Command$/', '', $className);
        $base = $base === '' ? $className : $base;

        $words = preg_split('/(?<!^)(?=[A-Z])/', $base) ?: [$base];
        $words = array_values(array_filter(array_map('strtolower', $words), static fn($w) => $w !== ''));

        if (count($words) < 2) {
            return 'app:' . ($words[0] ?? strtolower($base));
        }

        $verb = array_shift($words);

        return implode('', $words) . ':' . $verb;
    }

    /**
     * Add the `$this->add(new …)` line to the project's Console.php.
     *
     * The same thing `create:crud` does for `screens/registry.js`, and for the same
     * reason: a generated thing nothing registers is a generated thing that does
     * not run, and the person who asked for it has no way to know that from the
     * output.
     *
     * Reported rather than enforced — a project may have restructured its console
     * — and idempotent, so re-running after an edit does not add the line twice.
     *
     * @return string A line for the command's output.
     */
    private function registerInConsole(string $root, string $fullNamespace, string $className): string
    {
        $path = $root . '/src/Console.php';
        if (!is_file($path)) {
            return "Register:  src/Console.php not found — add \$this->add(new \\{$fullNamespace}\\{$className}()); by hand\n";
        }

        $source = (string) file_get_contents($path);
        $line   = '        $this->add(new \\' . $fullNamespace . '\\' . $className . '());';

        if (str_contains($source, '\\' . $fullNamespace . '\\' . $className . '(')) {
            return "Register:  already in src/Console.php\n";
        }

        // Anchored on the invitation `init` writes, so the line lands where a reader
        // was already told to put one. Without it, after `parent::registerCommands()`.
        $anchor = '        // Register your custom commands here:';
        if (str_contains($source, $anchor)) {
            $updated = str_replace($anchor, $line . "\n" . $anchor, $source);
        } elseif (str_contains($source, 'parent::registerCommands();')) {
            $updated = str_replace(
                'parent::registerCommands();',
                "parent::registerCommands();\n" . $line,
                $source
            );
        } else {
            return "Register:  could not find where to add it in src/Console.php — add {$line} by hand\n";
        }

        if (file_put_contents($path, $updated) === false) {
            return "Register:  could not write src/Console.php — add {$line} by hand\n";
        }

        return "Register:  added to src/Console.php\n";
    }
}
