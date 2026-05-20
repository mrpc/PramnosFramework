<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeController extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:controller');
        $this->setDescription('Create a web controller');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: controller');
        }
        $output->writeln($this->createController($name));
        return 0;
    }
}
