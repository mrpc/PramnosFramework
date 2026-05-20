<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeApi extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:api');
        $this->setDescription('Create an API endpoint controller');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: api');
        }
        $output->writeln($this->createApi($name));
        return 0;
    }
}
