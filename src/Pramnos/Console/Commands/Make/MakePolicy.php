<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * create:policy — scaffold an authorization policy class.
 *
 * Writes to src/Policies/<Name>.php. PramnosFramework has no dedicated
 * authorization-policy base class, so the generated file is a plain class
 * exposing ability methods (viewAny/view/create/update/delete) to be wired
 * into your own authorization checks. Generates a matching test stub.
 *
 * Usage:
 *   php pramnos create:policy ArticlePolicy
 */
class MakePolicy extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:policy');
        $this->setDescription('Create an authorization policy class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: policy');
        }
        $output->writeln($this->createPolicy($name));
        return 0;
    }

    /**
     * Create a policy class from the policy.stub template.
     *
     * @param string $policyName PascalCase class name (e.g. ArticlePolicy)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createPolicy(string $policyName): string
    {
        $application = $this->getApplication()->internalApplication;

        $namespace = isset($application->applicationInfo['namespace'])
            ? $application->applicationInfo['namespace']
            : 'App';

        $className = ucfirst(preg_replace('/\W+/', '', $policyName));
        if ($className === '') {
            throw new \InvalidArgumentException('Policy name must be a valid PHP class name.');
        }

        $dir = defined('ROOT') ? ROOT . '/src/Policies' : getcwd() . '/src/Policies';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = $dir . '/' . $className . '.php';
        if (file_exists($filename)) {
            throw new \Exception("Policy $className already exists at $filename.");
        }

        $stub = $this->renderStub('policy', [
            'namespace' => $namespace . '\\Policies',
            'class'     => $className,
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write policy file: $filename");
        }

        $testOutput = $this->generateTestStub($className . 'Policy', $namespace . '\\Policies');

        return "Namespace: {$namespace}\\Policies\n"
            . "Class:     {$className}\n"
            . "File:      {$filename}\n"
            . $testOutput
            . "\nPolicy created.\n"
            . "Note: no framework policy base exists — wire the ability methods into your own authorization checks.";
    }
}
