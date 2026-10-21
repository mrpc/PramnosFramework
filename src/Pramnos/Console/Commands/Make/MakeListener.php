<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MakeListener extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:listener');
        $this->setDescription('Create an event listener class');
        $this->addCommonOptions();
        $this->addOption('event', null, InputOption::VALUE_REQUIRED, 'The event class handle() takes (JobPostCreated, or a full class name)');
        $this->addOption('queued', null, InputOption::VALUE_NONE, 'Run it in the queue: extends QueuedListener');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: listener');
        }
        $event                = $input->getOption('event');
        $this->listenerEvent  = is_string($event) && $event !== '' ? $event : null;
        $this->listenerQueued = (bool) $input->getOption('queued');
        $output->writeln($this->createListener($name));
        return 0;
    }
}
