<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * create:test — scaffold a PHPUnit test class.
 *
 * Writes to tests/Unit/<Name>Test.php extending PHPUnit\Framework\TestCase,
 * using the shared 'test' stub. A trailing "Test" in the supplied name is
 * stripped so `create:test Foo` and `create:test FooTest` both produce
 * FooTest.
 *
 * Usage:
 *   php pramnos create:test Invoice
 */
class MakeTest extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:test');
        $this->setDescription('Create a PHPUnit test class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: test');
        }
        $output->writeln($this->createTest($name));
        return 0;
    }

    /**
     * Create a PHPUnit test class from the test.stub template.
     *
     * @param string $testName Base name (e.g. Invoice or InvoiceTest)
     * @return string Summary of created files
     * @throws \Exception
     */
    public function createTest(string $testName): string
    {
        $className = ucfirst(preg_replace('/\W+/', '', $testName));
        if ($className === '') {
            throw new \InvalidArgumentException('Test name must be a valid PHP class name.');
        }
        // Strip a trailing "Test" so the file/class ends in exactly one "Test".
        $base = preg_replace('/Test$/', '', $className);
        if ($base === '') {
            $base = $className;
        }

        $baseDir  = defined('ROOT') ? ROOT : getcwd();
        $testsDir = $baseDir . '/tests/Unit';
        if (!is_dir($testsDir)) {
            @mkdir($testsDir, 0777, true);
        }

        $filename = $testsDir . '/' . $base . 'Test.php';
        if (file_exists($filename)) {
            throw new \Exception("Test {$base}Test already exists at $filename.");
        }

        // The on-disk test.stub only substitutes {{ class }} (namespace Tests\Unit
        // is fixed in the template). Pass namespace for the embedded-fallback case.
        $stub = $this->renderStub('test', [
            'class'     => $base,
            'namespace' => 'Tests\\Unit',
        ]);

        if (!file_put_contents($filename, $stub)) {
            throw new \Exception("Cannot write test file: $filename");
        }

        return "Namespace: Tests\\Unit\n"
            . "Class:     {$base}Test\n"
            . "File:      {$filename}\n"
            . "\nTest created.";
    }
}
