<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeEvent extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:event');
        $this->setDescription('Create an event class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: event');
        }
        $output->writeln($this->createEvent($name));
        return 0;
    }
}
