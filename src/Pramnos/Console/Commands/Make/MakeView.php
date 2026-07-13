<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MakeView extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:view');
        $this->setDescription('Create a template view');
        $this->addCommonOptions();
        $this->addOption(
            'full',
            'f',
            InputOption::VALUE_NONE,
            'Generate complete CRUD view templates (index/show/edit)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: view');
        }
        $output->writeln($this->createView($name, (bool) $input->getOption('full')));
        return 0;
    }
}
