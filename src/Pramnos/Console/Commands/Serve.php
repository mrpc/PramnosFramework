<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Console command to run a local web server
 */
class Serve extends Command
{
    /**
     * Command configuration
     */
    protected function configure()
    {
        $this->setName('serve');
        $this->setDescription('Run a local server');
        $this->setHelp(
            "Runs a local web server. Default port: 8000"
        );
        $this->addOption(
            'port', 'p', InputOption::VALUE_REQUIRED,
            'Select a port for the server'
        );
        $this->addOption(
            'host', null, InputOption::VALUE_REQUIRED,
            'Select a host for the server'
        );
    }

    /**
     * Command execution
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $port = $input->getOption('port');
        $host = $input->getOption('host');
        if ($port === null) {
            $port = 8000;
        }
        if ($host === null) {
            $host = 'localhost';
        }
        $output->writeln(
            "Development server started on http://{$host}:{$port}/"
        );
        $path = ROOT . '/www/index.php';
        passthru(
            PHP_BINARY . " -S {$host}:{$port} \"{$path}\" 2>&1"
        );

    }



}