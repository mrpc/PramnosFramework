<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * create:command — scaffold a new Symfony Console command class.
 *
 * Writes to src/Console/Commands/<Name>.php under the application's
 * <namespace>\Console\Commands namespace and generates a matching test stub.
 *
 * Usage:
 *   php pramnos create:command SyncStock
 */
class MakeCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:command');
        $this->setDescription('Create a console command class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: command');
        }
        $output->writeln($this->createConsoleCommand($name));
        return 0;
    }

    /**
     * Create a console command class from the command.stub template.
     *
     * @param string $commandName PascalCase class name (e.g. SyncStock)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createConsoleCommand(string $commandName): string
    {
        $application = $this->getApplication()->internalApplication;

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $commandName));
        if ($className === '') {
            throw new \InvalidArgumentException('Command name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Console/Commands' : getcwd() . '/src/Console/Commands';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Command $className already exists at $filename.");
        }

        // Derive a sensible CLI name from the class name: strip a trailing
        // "Command" suffix, then lowercase (e.g. SyncStock -> app:syncstock).
        $base        = preg_replace('/Command$/', '', $className);
        $commandLine = 'app:' . strtolower($base === '' ? $className : $base);

        $stub = $this->renderStub('command', [
            'namespace'    => $namespace . '\\Console\\Commands',
            'class'        => $className,
            'command_name' => $commandLine,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write command file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Command', $namespace . '\\Console\\Commands');

        return "Namespace: {$namespace}\\Console\\Commands\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nCommand created.";
    }
}
