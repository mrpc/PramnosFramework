<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Create something related to the application
 */
class Create extends Command
{
    /**
     * Command configuration
     */
    protected function configure()
    {
        $this->setName('create');
        $this->setDescription('Create something');
        $this->setHelp(
            "Create an entity. Possible entities:\n"
            . " - model: Create a model\n"
            . " - controller: Create a controller\n"
            . " - view: Create a view\n"
        );
        $this->addArgument(
            'entity', InputArgument::REQUIRED, 'What to create'
        );
        $this->addArgument(
            'name', InputArgument::REQUIRED, 'Name of the created object'
        );
    }

    /**
     * Command execution
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $entity = $input->getArgument('entity');
        $name =  $input->getArgument('name');
        switch (strtolower($entity)) {
            case "model":
                $output->writeln($this->createModel($name));
                break;
            case "controller":
                break;
            case "view":
                break;
            default:
                throw new \InvalidArgumentException(
                    'Invalid type of entity to create: ' . $entity
                );
        }
    }

    /**
     * Creates a model
     * @param string $name Model name
     */
    protected function createModel($name)
    {
        $this->getApplication()->internalApplication->init();
        $database = \Pramnos\Database\Database::getInstance();
        $tableName = '#PREFIX#' . $name . 's';

        if (!$database->table_exists($tableName)) {
            throw new \Exception('Table: ' . $tableName . ' does not exist.');
        }
        $sql = $database->Prepare("SHOW FULL COLUMNS FROM `{$tableName}`");
        $result = $database->Execute($sql);
        $fileContent = '';
        while (!$result->eof) {

            $type = 'string';
            $basicType = explode('(', $result->fields['Type']);
            switch ($basicType[0]) {
                case "tinyint":
                case "smallint":
                case "integer":
                case "int":
                case "mediumint":
                case "bigint":
                    $type = 'int';
                    break;
                case "decimal":
                case "numeric":
                case "float":
                case "double":
                    $type = 'float';
                    break;
                case "bool":
                case "boolean":
                    $type = 'bool';
                    break;
            }


            $fileContent .= "    /**\n"
                . "     * "
                . $result->fields['Comment']
                . "\n"
                . "     * @var "
                . $type
                . "\n"
                . "     */\n"
                . "    public $"
                . $result->fields['Field']
                . ";\n\n";
            $result->MoveNext();
        }
        echo $fileContent;

    }


}