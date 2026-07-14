<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * create:provider — scaffold a new service provider.
 *
 * Writes to src/Providers/<Name>.php extending
 * Pramnos\Application\ServiceProvider and generates a matching test stub.
 *
 * Usage:
 *   php pramnos create:provider PaymentServiceProvider
 */
class MakeProvider extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:provider');
        $this->setDescription('Create a service provider class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: provider');
        }
        $output->writeln($this->createProvider($name));
        return 0;
    }

    /**
     * Create a service provider class from the provider.stub template.
     *
     * @param string $providerName PascalCase class name (e.g. PaymentServiceProvider)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createProvider(string $providerName): string
    {
        $application = $this->getApplication()->internalApplication;

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $providerName));
        if ($className === '') {
            throw new \InvalidArgumentException('Provider name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Providers' : getcwd() . '/src/Providers';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Provider $className already exists at $filename.");
        }

        $stub = $this->renderStub('provider', [
            'namespace' => $namespace . '\\Providers',
            'class'     => $className,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write provider file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Provider', $namespace . '\\Providers');

        return "Namespace: {$namespace}\\Providers\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nProvider created.";
    }
}
