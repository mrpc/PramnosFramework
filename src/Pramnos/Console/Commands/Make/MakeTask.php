<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * create:task — scaffold a new queue task handler.
 *
 * Writes to src/Tasks/<Name>.php extending Pramnos\Queue\AbstractTask and
 * generates a matching test stub.
 *
 * Usage:
 *   php pramnos create:task SendInvoice
 */
class MakeTask extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:task');
        $this->setDescription('Create a queue task handler class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: task');
        }
        $output->writeln($this->createTask($name));
        return 0;
    }

    /**
     * Create a queue task class from the task.stub template.
     *
     * @param string $taskName PascalCase class name (e.g. SendInvoice)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createTask(string $taskName): string
    {
        $application = $this->getApplication()->internalApplication;

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $taskName));
        if ($className === '') {
            throw new \InvalidArgumentException('Task name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Tasks' : getcwd() . '/src/Tasks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Task $className already exists at $filename.");
        }

        $stub = $this->renderStub('task', [
            'namespace' => $namespace . '\\Tasks',
            'class'     => $className,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write task file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Task', $namespace . '\\Tasks');

        return "Namespace: {$namespace}\\Tasks\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nTask created.";
    }
}
