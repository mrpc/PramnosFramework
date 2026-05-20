<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * Legacy Create command (Backward Compatibility Alias)
 * Forwards calls to the new Symfony-compatible create:* commands.
 */
class Create extends Command
{
    /** Entities supported by the legacy command. */
    private const VALID_ENTITIES = [
        'model', 'controller', 'view', 'crud', 'api',
        'migration', 'seeder', 'middleware', 'event', 'listener',
    ];

    /** Entities that require a name argument (migration supports interactive wizard without one). */
    private const REQUIRES_NAME = [
        'model', 'controller', 'view', 'crud', 'api',
        'seeder', 'middleware', 'event', 'listener',
    ];

    protected function configure()
    {
        $this->setName('create');
        $this->setDescription('Legacy alias for create:* commands');
        $this->setHelp("Legacy alias. Please use create:<entity> instead.");

        $this->addArgument('entity', InputArgument::REQUIRED, 'What to create (model, controller, etc.)');
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name of the created object');

        $this->addOption('schema', 's', InputOption::VALUE_OPTIONAL, 'Database schema', null);
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Database table', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $entity = strtolower($input->getArgument('entity'));
        $name = $input->getArgument('name');

        // Pre-validate to maintain BC exception contract of the original Create command.
        if (!in_array($entity, self::VALID_ENTITIES, true)) {
            throw new \InvalidArgumentException('Invalid type of entity to create: ' . $entity);
        }
        if (in_array($entity, self::REQUIRES_NAME, true) && !$name) {
            throw new \InvalidArgumentException('Name is required for: ' . $entity);
        }

        $output->writeln("<comment>Warning: 'pramnos create {$entity}' is deprecated. Please use 'pramnos create:{$entity}' instead.</comment>");

        $commandName = 'create:' . $entity;

        try {
            $command = $this->getApplication()->find($commandName);
        } catch (\Symfony\Component\Console\Exception\CommandNotFoundException $e) {
            throw new \InvalidArgumentException('Invalid type of entity to create: ' . $entity, 0, $e);
        }

        $arguments = [
            'command' => $commandName,
            'name'    => $name,
        ];

        if ($input->getOption('schema')) {
            $arguments['--schema'] = $input->getOption('schema');
        }

        if ($input->getOption('table')) {
            $arguments['--table'] = $input->getOption('table');
        }

        $arrayInput = new ArrayInput($arguments);
        // Important: keep the interactivity of the original input for wizards
        $arrayInput->setInteractive($input->isInteractive());

        return $command->run($arrayInput, $output);
    }

    // ── BC static proxies ────────────────────────────────────────────────────
    // These were public static methods on the original monolithic Create.php.
    // They now live in MakeCommandBase; proxies here keep existing callers working.

    public static function getProperClassName($name, $forceSingular = true)
    {
        return MakeCommandBase::getProperClassName($name, $forceSingular);
    }

    public static function getModelTableName($name)
    {
        return MakeCommandBase::getModelTableName($name);
    }
}
