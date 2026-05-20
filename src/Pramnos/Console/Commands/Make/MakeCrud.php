<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeCrud extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:crud');
        $this->setDescription('Create a complete CRUD (model, view, controller)');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: crud');
        }
        $output->writeln($this->createCrud($name));
        return 0;
    }
}
