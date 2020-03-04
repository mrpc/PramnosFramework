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
                $output->writeln($this->createController($name));
                break;
            case "view":
                $output->writeln($this->createView($name));
                break;
            default:
                throw new \InvalidArgumentException(
                    'Invalid type of entity to create: ' . $entity
                );
        }
    }

    /**
     * Creates a controller
     * @param string $name
     */
    protected function createView($name)
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $path = ROOT . DS . INCLUDES . DS;
        if ($application->appName != '') {
            $path .= $application->appName . DS;
        }
        $path .= 'Views';
        $viewPath = $path . DS . strtolower($name);

        if (file_exists($viewPath)) {
            throw new \Exception('View already exists.');
        }
        mkdir($viewPath);

        $files = array();
        $files['Index File'] = $viewPath . DS . strtolower($name) . '.html.php';
        $files['Edit Resource'] = $viewPath . DS . 'edit.html.php';
        $files['Show Resource'] = $viewPath . DS . 'show.html.php';
        $actualName = ucfirst($name);
        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php

/**
 * {$actualName} View
 * REASON
 * Auto generated at: {$date}
 */

defined('SP') or die('No startpoint defined...');
?>
Hello World
content;
        $return = "Files: \n";
        foreach ($files as $reason => $filename) {
            $return .= ' - ' . $filename . "\n";
            file_put_contents(
                $filename, str_replace('REASON', $reason, $fileContent)
            );
        }

        return $return . "\nView created.";

    }


    /**
     * Creates a controller
     * @param string $name
     */
    protected function createController($name)
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();

        $path = ROOT . DS . INCLUDES . DS;

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'Pramnos';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
            $path .= $application->appName . DS;
        }
        $namespace .= '\\Controllers';
        $className =  ucfirst($name);
        $path .= 'Controllers';
        $filename = $path . DS . ucfirst($name) . '.php';
        if (class_exists('\\' . $namespace . '\\'. $className)
            || file_exists($filename)) {
            throw new \Exception('Controller already exists.');
        }
        if (!file_exists($path)) {
            mkdir($path);
        }
        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php
namespace {$namespace};
use \Pramnos\Application;

/**
 * {$className} Controller
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Controller
{

    /**
     * {$className} controller constructor
     * @param Application \$application
     */
    public function __construct(Application \$application = null)
    {
        \$this->addAuthAction(
            array('edit', 'save', 'delete', 'show')
        );
        parent::__construct(\$application);
    }


    /**
     * Display a listing of the resource
     * @return string
     */
    public function display()
    {

    }

    /**
     * Display the specified resource
     * @return string
     */
    public function show()
    {

    }

    /**
     * Show the form for creating a new resource or editing an existing one
     * @return string
     */
    public function edit()
    {

    }

    /**
     * Store a newly created or edited resource in storage.
     */
    public function save()
    {

    }

    /**
     * Remove the specified resource from storage
     */
    public function delete()
    {

    }



}
content;

        file_put_contents($filename, $fileContent);

        return "Namespace: {$namespace}\n"
            . "Class: {$className}\n"
            . "File: {$filename}\n\nController created.";

    }

    /**
     * Creates a model
     * @param string $name Model name
     */
    protected function createModel($name)
    {
        $application = $this->getApplication()->internalApplication;
        $application->init();
        $database = \Pramnos\Database\Database::getInstance();
        $tableName = '#PREFIX#' . strtolower($name) . 's';

        if (!$database->table_exists($tableName)) {
            throw new \Exception('Table: ' . $tableName . ' does not exist.');
        }
        $sql = $database->Prepare("SHOW FULL COLUMNS FROM `{$tableName}`");
        $result = $database->Execute($sql);


        $path = ROOT . DS . INCLUDES . DS;

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'Pramnos';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
            $path .= $application->appName . DS;
        }
        $namespace .= '\\Models';
        $className =  ucfirst($name);
        $path .= 'Models';
        $filename = $path . DS . ucfirst($name) . '.php';
        if (class_exists('\\' . $namespace . '\\'. $className)
            || file_exists($filename)) {
            throw new \Exception('Model already exists.');
        }
        if (!file_exists($path)) {
            mkdir($path);
        }


        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php
namespace {$namespace};

/**
 * {$className} Model
 * Auto generated at: {$date}
 */
class {$className} extends \Pramnos\Application\Model
{

content;



        $primaryKey = '';
        while (!$result->eof) {
            $primary = false;
            if (isset($result->fields['Key'])
                && $result->fields['Key'] == 'PRI') {
                $primaryKey = $result->fields['Field'];
                $primary = true;
            }
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

            $fileContent .= "    /**\n";
            if ($result->fields['Comment'] != '') {
                $fileContent .= "     * "
                    . $result->fields['Comment']
                    . "\n";
            }
            if ($primary) {
                if ($result->fields['Comment'] != '') {
                    $fileContent .= "     * Primary Key \n";
                } else {
                    $fileContent .= "     * (Primary Key) \n";
                }
            }
            $fileContent .= "     * @var "
                . $type
                . "\n"
                . "     */\n"
                . "    public $"
                . $result->fields['Field']
                . ";\n";
            $result->MoveNext();
        }
        if ($primaryKey != '') {
            $fileContent .= "    /**\n"
                . "     * Primary key in database\n"
                . "     * @var string\n"
                . "     */\n"
                . '    protected $_primaryKey = "'
                . $primaryKey
                . "\";\n\n";
        }
        $fileContent .= "    /**\n"
            . "     * Database table\n"
            . "     * @var string\n"
            . "     */\n"
            . '    protected $_dbtable = "'
            . $tableName
            . "\";\n\n";

        $primaryKeyVal = '$primaryKey';
        if ($primaryKey != '') {
            $primaryKeyVal = '$' . $primaryKey;
        }

        $fileContent .= <<<content
    /**
     * Load from database
     * @param string {$primaryKeyVal} ID to load
     * @param string \$table Database table
     * @param string \$key Primary key on database
     * @param boolean   \$debug Show debug information
     * @return \$this
     */
    public function load({$primaryKeyVal}, \$table = NULL,
        \$key = NULL, \$debug = false)
    {
        return parent::_load({$primaryKeyVal}, \$table, \$key, \$debug);
    }

    /**
     * Save to database
     * @param string    \$table
     * @param string    \$key
     * @param boolean   \$autoGetValues If true, get all values from \$_REQUEST
     * @param boolean   \$debug Show debug information (and die)
     * @return          \$this
     */
    public function save(\$table = NULL, \$key = NULL,
        \$autoGetValues = false, \$debug = false)
    {
        return parent::_save(\$table, \$key, \$autoGetValues, \$debug);
    }


    /**
     * Delete from database
     * @param integer {$primaryKeyVal} ID to delete
     * @param string \$table
     * @param string \$key
     * @return \$this
     */
    public function delete({$primaryKeyVal}, \$table = NULL, \$key = NULL)
    {
        return parent::_delete({$primaryKeyVal}, \$table, \$key);
    }

    /**
     * List objects
     * @param string \$filter Filter for where statement in database query
     * @param string \$order Order for database query
     * @param type $\table
     * @param type \$key
     * @return {$className}[]
     */
    public function getList(\$filter = NULL, \$order = NULL,
        \$table = NULL, \$key = NULL, \$debug = false)
    {
        return parent::_getList(\$filter, \$order, \$table, \$key, \$debug);
    }

}
content;

        file_put_contents($filename, $fileContent);

        return "Namespace: {$namespace}\n"
        . "Class: {$className}\n"
        . "File: {$filename}\n\nModel created.";


    }


}