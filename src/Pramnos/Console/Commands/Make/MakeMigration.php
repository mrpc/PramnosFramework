<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeMigration extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:migration');
        $this->setDescription('Create a database migration');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        
        // No name → interactive wizard; name provided → silent stub
        if (!$name) {
            $output->writeln($this->runMigrationWizard($input, $output));
        } else {
            $output->writeln($this->createMigration($name));
        }
        return 0;
    }
}
