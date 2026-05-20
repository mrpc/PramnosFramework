<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeView extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:view');
        $this->setDescription('Create a template view');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: view');
        }
        $output->writeln($this->createView($name));
        return 0;
    }
}
