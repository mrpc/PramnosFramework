<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold a service class (services-oriented application style).
 *
 * Services encapsulate application logic + data access behind intention-revealing
 * methods, keeping controllers thin — the alternative to putting behaviour on
 * ActiveRecord models. See the Application Styles guide.
 */
class MakeService extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:service');
        $this->setDescription('Create a service class (services-oriented style)');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: service');
        }
        $output->writeln($this->createService($name));
        return 0;
    }
}
