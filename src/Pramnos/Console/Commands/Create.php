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
            . " - crud: Create a CRUD system (model/view/controller)\n"
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
            case "crud":
                $output->writeln($this->createCrud($name));
                break;
            default:
                throw new \InvalidArgumentException(
                    'Invalid type of entity to create: ' . $entity
                );
        }
    }

    /**
     * Creates a CRUD system based on a model name
     * @param string $name
     * @return string
     */
    public function createCrud($name)
    {
        $content = "Creating Model: ";
        try {
            $this->createModel($name);
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        $content .= "Creating Controller: ";
        try {
            $this->createController($name, true);
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        $content .= "Creating View: ";
        try {
            $this->createView($name, true);
            $content .= "OK\n";
        } catch (\Exception $ex) {
            $content .= "FAIL - " . $ex->getMessage() . "\n";
        }
        return $content . "\n";
    }


    /**
     * Creates a view
     * @param string $name Name of the view
     * @param bool $full Create a full crud view (Create/List/Edit/Delete)
     */
    protected function createView($name, $full = false)
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

        $indexContent = 'Hello World';
        $editContent = '';
        $lastLetter = substr($name, -1);
        if ($lastLetter == 's') {
            $className =  ucfirst($name);
            $filename = $path . DS . ucfirst($name) . '.php';
        } else {
            $className =  ucfirst($name) . 's';
            $filename = $path . DS . ucfirst($name) . 's.php';
        }

        if ($full) {
            $database = \Pramnos\Database\Database::getInstance();
            $objectName = ucfirst($name);


            if ($lastLetter == 's') {
                $tableName = '#PREFIX#' . strtolower($name);
            } else {
                $tableName = '#PREFIX#' . strtolower($name) . 's';
            }


            if (!$database->table_exists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            $sql = $database->Prepare("SHOW FULL COLUMNS FROM `{$tableName}`");
            $result = $database->Execute($sql);


            $formContent = '';

            $firstField = '';
            $primaryKey = '';
            $count = 0;
            while (!$result->eof) {
                $count++;
                $primary = false;
                if (isset($result->fields['Key'])
                    && $result->fields['Key'] == 'PRI') {
                    $primaryKey = $result->fields['Field'];
                    $primary = true;
                }
                if ($count == 2) {
                    $firstField = $result->fields['Field'];
                }
                if ($result->fields['Comment'] != '') {
                        $fieldName = $result->fields['Comment'];
                } else {
                        $fieldName = ucfirst($result->fields['Field']);
                }
                $field = $result->fields['Field'];

                $basicType = explode('(', $result->fields['Type']);
                if (!$primary) {
                    switch ($basicType[0]) {
                        case "tinyint":
                        case "smallint":
                        case "integer":
                        case "int":
                        case "mediumint":
                        case "bigint":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="number" value="<?php echo \$this->model->{$field}; ?>" step="1" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                            break;

                        case "float":
                        case "double":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="number" step="0.0001" value="<?php echo \$this->model->{$field}; ?>" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                            break;

                        case "bool":
                        case "boolean":
$formContent .= <<<content
            <div class="form-group">
            <label for="{$field}">{$fieldName}:</label>
                <select id="{$field}" name="{$field}" class="form-control">
                    <option <?php if (\$this->model->{$field} == 0): echo 'selected'; endif;?> value="0"><?php l('No');?></option>
                    <option <?php if (\$this->model->{$field} == 1): echo 'selected'; endif;?> value="1"><?php l('Yes');?></option>
                </select>
            </div>

content;
                            break;

                        case "text":
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <textarea id="{$field}" name="{$field}" class="form-control"><?php echo \$this->model->{$field}; ?></textarea>
            </div>

content;
                            break;

                        default:
$formContent .= <<<content
            <div class="form-group">
                <label for="{$field}">{$fieldName}:</label>
                <input type="text" value="<?php echo \$this->model->{$field}; ?>" id="{$field}" name="{$field}" class="form-control">
            </div>

content;
                            break;
                    }
                }
                $formContent .= "\n";
                $result->MoveNext();
            }

            $editContent = <<<content
<div class="card">
    <div class="card-body">
        <form action="[sURL]{$className}/save/<?php echo \$this->model->{$primaryKey}; ?>" method="post" role="form">

{$formContent}

            <div class="form-group">
                <button type="submit" class="btn btn-primary"><?php l('Save'); ?></button>
            </div>
        </form>

    </div>
</div>
content;

            $indexContent = <<<content
<div class="card">
    <div class="card-header">
        <h1 class="page-head-line">
            {$objectName} list
        </h1>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <a href="[sURL]{$className}/edit/0"><button type="button" class="btn btn-primary"><i class="fa fa-plus"></i> <?php l('New'); ?></button></a>
            </div>
            <br /><br />
        </div>

        <!-- Table -->
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{$firstField}</td>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (\$this->items as \$item): ?>
                    <tr>
                        <td><a href="[sURL]{$className}/show/<?php echo \$item->{$primaryKey}; ?>">#<?php echo \$item->{$primaryKey}; ?></a></td>
                        <td><a href="[sURL]{$className}/show/<?php echo \$item->{$primaryKey}; ?>"><?php echo \$item->{$firstField}; ?></a></td>
                        <td>
                            <a href="[sURL]{$className}/edit/<?php echo \$item->{$primaryKey}; ?>"><?php l('Edit');?></a>
                            <a onclick="return confirm('<?php l('Are you sure?');?>');" href="[sURL]{$className}/delete/<?php echo \$item->{$primaryKey}; ?>"><?php l('Delete');?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
content;
        }


        $files[] = array (
            'reason' => 'Index File',
            'file' => $viewPath . DS . strtolower($name) . '.html.php',
            'content' => $indexContent
        );
        $files[] = array (
            'reason' => 'Edit Resource',
            'file' => $viewPath . DS . 'edit.html.php',
            'content' => $editContent
        );
        $files[] = array (
            'reason' => 'Show Resource',
            'file' => $viewPath . DS . 'show.html.php',
            'content' => '<?php var_dump($this->model);?' . '>'
        );
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
content;
        $fileContent .= "\n?"
            . ">\nCONTENT";
        $return = "Files: \n";
        foreach ($files as $file) {
            $return .= ' - ' . $file['file'] . "\n";
            file_put_contents(
                $file['file'],
                str_replace(
                    array('REASON', 'CONTENT', '[sURL]'),
                    array($file['reason'], $file['content'], '<?php echo sURL;?>'),
                    $fileContent
                )
            );
        }

        return $return . "\nView created.";

    }


    /**
     * Creates a controller
     * @param string $name Name of the controller to be created
     * @param bool $full Create a full crud controller
     */
    protected function createController($name, $full = false)
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

        $path .= 'Controllers';
        $lastLetter = substr($name, -1);
        if ($lastLetter == 's') {
            $className =  ucfirst($name);
            $filename = $path . DS . ucfirst($name) . '.php';
        } else {
            $className =  ucfirst($name) . 's';
            $filename = $path . DS . ucfirst($name) . 's.php';
        }


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

content;
        if (!$full) {
            $fileContent .= <<<content
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
        } else {
            $database = \Pramnos\Database\Database::getInstance();
            $viewName = strtolower($name);
            $modelNameSpace = str_replace("Controllers", "Models", $namespace);
            $modelClass = substr($className, 0, -1);


            if ($lastLetter == 's') {
                $tableName = '#PREFIX#' . strtolower($name);
            } else {
                $tableName = '#PREFIX#' . strtolower($name) . 's';
            }


            if (!$database->table_exists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            $sql = $database->Prepare("SHOW FULL COLUMNS FROM `{$tableName}`");
            $result = $database->Execute($sql);


            $saveContent = '';

            $primaryKey = '';
            while (!$result->eof) {
                $primary = false;
                if (isset($result->fields['Key'])
                    && $result->fields['Key'] == 'PRI') {
                    $primaryKey = $result->fields['Field'];
                    $primary = true;
                }
                $basicType = explode('(', $result->fields['Type']);
                if (!$primary) {
                    switch ($basicType[0]) {
                        case "tinyint":
                        case "smallint":
                        case "integer":
                        case "int":
                        case "mediumint":
                        case "bigint":
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\', \'int\');'
                                . "\n";
                            break;
                        case "float":
                        case "double":
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = (float) $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\');'
                                . "\n";
                            break;
                        case "bool":
                        case "boolean":
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = (bool) $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\');'
                                . "\n";
                            break;
                        default:
                            $saveContent .= '        $model->'
                                . $result->fields['Field']
                                . ' = trim('
                                . "\n            strip_tags(\n"
                                . '                $request->get(\''
                                . $result->fields['Field']
                                . '\', \'\', \'post\')'
                                . "\n            )"
                                . "\n        );\n";
                            break;
                    }
                }

                $result->MoveNext();
            }


            $fileContent .= <<<content
    /**
     * Display a listing of the resource
     * @return string
     */
    public function display()
    {
        \$view = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);

        \$view->items = \$model->getList();
        return \$view->display();
    }

    /**
     * Display the specified resource
     * @return string
     */
    public function show()
    {
        \$view = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
        \$view->addModel(\$model);
        return \$view->display('show');
    }

    /**
     * Show the form for creating a new resource or editing an existing one
     * @return string
     */
    public function edit()
    {
        \$view = \$this->getView('{$viewName}');
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
        \$view->addModel(\$model);
        return \$view->display('edit');
    }

    /**
     * Store a newly created or edited resource in storage.
     */
    public function save()
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->load(\$request->getOption());
{$saveContent}
        \$model->save();
        \$this->redirect(sURL . '{$className}');
    }

    /**
     * Remove the specified resource from storage
     */
    public function delete()
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$request = new \Pramnos\Http\Request();
        \$model->delete(\$request->getOption());
        \$this->redirect(sURL . '{$className}');
    }

}
content;
        }

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
        $lastLetter = substr($name, -1);
        if ($lastLetter == 's') {
            $tableName = '#PREFIX#' . strtolower($name);
        } else {
            $tableName = '#PREFIX#' . strtolower($name) . 's';
        }


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
        $path .= 'Models';
        if ($lastLetter == 's') {
            $className =  ucfirst(substr($name, 0, -1));
            $filename = $path . DS . ucfirst(substr($name, 0, -1)) . '.php';
        } else {
            $className =  ucfirst($name);
            $filename = $path . DS . ucfirst($name) . '.php';
        }

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