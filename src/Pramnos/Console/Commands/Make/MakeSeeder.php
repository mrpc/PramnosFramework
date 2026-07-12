<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeSeeder extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:seeder');
        $this->setDescription('Create a database seeder class');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: seeder');
        }
        $output->writeln($this->createSeeder($name, [], ''));
        return 0;
    }
}
