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
            . " - api: Create an API endpoint\n"
            . " - migration: Create a migration\n"
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
            case "api":
                $output->writeln($this->createApi($name));
                break;
            case "migration":
                $output->writeln($this->createMigration($name));
                break;
            default:
                throw new \InvalidArgumentException(
                    'Invalid type of entity to create: ' . $entity
                );
        }
        return 0;
    }

    /**
     * Create a database migration
     * @param string $migrationName
     * @return string
     * @throws \Exception
     */
    public function createMigration($migrationName)
    {
        $name = preg_replace('/\W+/','',strtolower(strip_tags($migrationName ?? '')));
        $application = $this->getApplication()->internalApplication;
        $application->init();

        if (!file_exists(APP_PATH . DS . 'Migrations')) {
            if (!mkdir(APP_PATH . DS . 'Migrations')) {
                throw new \Exception('Cannot create migrations directory.');
            }
        }
        $path = APP_PATH . DS . 'Migrations' . DS;

        $migrationsFile = APP_PATH . DS . 'migrations.php';
        if (file_exists($migrationsFile)) {
            $migrations = require($migrationsFile);
        }

        if (isset($application->applicationInfo['namespace'])) {
            $namespace = $application->applicationInfo['namespace'];
        } else {
            $namespace = 'Pramnos';
        }
        if ($application->appName != '') {
            $namespace .= '\\' . $application->appName;
            $path .= $application->appName . DS;
        }
        $namespace .= '\\Migrations';


        $className =  'Migration' . $name;
        $filename = $path . DS . 'Migration' . $name . '.php';


        if (class_exists('\\' . $namespace . '\\'. $className)
            || file_exists($filename)) {
            throw new \Exception('Migration already exists.');
        }

        $date = date('d/m/Y H:i');
        $fileContent = <<<content
<?php
namespace {$namespace};

/**
 * {$name} migration
 * Auto generated at: {$date}
 */
final class {$className} extends \Pramnos\Database\Migration
{

    /**
     * Version that this migration sets
     * @var string
     */
    public \$version = '{$migrationName}';
    /**
     * Description of the migration
     * @var string
     */
    public \$description = '';
    /**
     * Should the migration executed automatically
     * @var bool
     */
    public \$autoExecute = true;

    /**
     * Run the migration
     * @return void
     */
    public function up() : void
    {
        // this up() migration is auto-generated, please modify it to your needs
    }

    /**
     * Undo the migration
     * @return void
     */
    public function down() : void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }

}
content;
        if (!file_put_contents($filename, $fileContent)) {
            throw new \Exception('Cannot write migration file.');
        }
        $migrations[$migrationName] = $className;

        $migrationsContent = <<<migcontent
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Migrations List
    |--------------------------------------------------------------------------
    |
    | These migrations will be executed in order on application execution
    |
    */
migcontent;
        $comma = '';
        foreach ($migrations as $version => $class) {
            $migrationsContent .= $comma
                . "\n    '"
                . $version
                . "' => '" .
                $class
                . "'";
            $comma = ',';
        }
        $migrationsContent .= "\n];";
        if (!file_put_contents($migrationsFile, $migrationsContent)) {
            throw new \Exception('Cannot write migrations list file.');
        }

        return "Namespace: {$namespace}\n"
            . "Class: {$className}\n"
            . "File: {$filename}\n\nMigration created.";
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


            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            if ($database->type == 'postgresql') {
                $sql = $database->prepareQuery(
                    "SELECT column_name as \"Field\", data_type as \"Type\", character_maximum_length, is_nullable as \"Null\", column_default, "
                    . "(SELECT pg_catalog.col_description(c.oid, a.ordinal_position) FROM pg_catalog.pg_class c WHERE c.oid = (SELECT relfilenode FROM pg_catalog.pg_class WHERE relname = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "')) AS \"Comment\" "
                    . "FROM information_schema.columns a "
                    . "WHERE table_name = '" 
                    . str_replace('#PREFIX#', $database->prefix, $tableName) . "'"
                );
                
            } else {
                $sql = $database->prepareQuery("SHOW FULL COLUMNS FROM `{$tableName}`");
            }
            
            $result = $database->query($sql);


            $formContent = '';

            $firstField = '';
            $primaryKey = '';
            $count = 0;
            while ($result->fetch()) {
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
                    <th>{$firstField}</th>
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
    protected function createApi($name)
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
        $namespace .= '\\Api\\Controllers';

        $path .= 'Api/Controllers';
        $lastLetter = substr($name, -1);
        if ($lastLetter == 's') {
            $className = ucfirst(substr($name, 0, -1));
            $filename = $path . DS . ucfirst(substr($name, 0, -1)) . '.php';
        } else {
            $className = ucfirst($name);
            $filename = $path . DS . ucfirst($name) . '.php';
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
    public function __construct(\Pramnos\Application\Application \$application = null)
    {
        parent::__construct(\$application);
    }
    

content;
        
            $database = \Pramnos\Database\Database::getInstance();
            $viewName = strtolower($name);
            $modelNameSpace = str_replace("Api\Controllers", "Models", $namespace);
            $modelClass = $className;
            $modelClassLower = strtolower($modelClass);


            if ($lastLetter == 's') {
                $tableName = '#PREFIX#' . strtolower($name);
            } else {
                $tableName = '#PREFIX#' . strtolower($name) . 's';
            }
            


            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            if ($database->type == 'postgresql') {
                $sql = $database->prepareQuery(
                    "SELECT column_name as \"Field\", data_type as \"Type\", character_maximum_length, is_nullable as \"Null\", column_default, "
                    . "(SELECT col_description((SELECT oid FROM pg_class WHERE relname = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "'), a.ordinal_position)) AS \"Comment\", "
                    . "column_name in ( "
                    . "    SELECT column_name "
                    . "    FROM information_schema.table_constraints tc "
                    . "    JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name) "
                    . "    WHERE constraint_type = 'PRIMARY KEY' "
                    . "    AND tc.table_name = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "'"
                    . ") as \"PrimaryKey\" "
                    . "FROM information_schema.columns a "
                    . "WHERE table_name = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "'"
                );
            } else {
                $sql = $database->prepareQuery("SHOW FULL COLUMNS FROM `{$tableName}`");
            }
            $result = $database->query($sql);


            $saveContent = '';
            $updateContent = '';
            $returnContent = '';
            $postContent = '';
            $putContent = '';
            $primaryKey = '';

            $routerContent = '';

            while ($result->fetch()) {
                $primary = false;
                if ($database->type == 'postgresql') {
                    if ($result->fields['PrimaryKey'] == 't') {
                        $primaryKey = $result->fields['Field'];
                        $primary = true;
                    }
                } elseif (isset($result->fields['Key'])
                    && $result->fields['Key'] == 'PRI') {
                        $primaryKey = $result->fields['Field'];
                        $primary = true;
                }
                $basicType = explode('(', $result->fields['Type']);
                switch ($basicType[0]) {
                    case "tinyint":
                    case "smallint":
                    case "integer":
                    case "int":
                    case "mediumint":
                    case "bigint":

                        $returnContent .= '     * @apiSuccess {Number} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {Number} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\', \'int\');' . "\n";
                                $postContent .= '        if ($model->' . $result->fields['Field'] . ' == 0) {' . "\n";
                                $postContent .= '            $model->' . $result->fields['Field'] . ' = null;' . "\n";
                                $postContent .= '        }' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {Number}' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', 0, \'post\', \'int\');' . "\n";
                            }
                            $updateContent .= '     * @apiBody {Number} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\', \'int\');' . "\n";
                            
                        }
                        break;
                    case "float":
                    case "double":
                        $returnContent .= '     * @apiSuccess {Number} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {Number} [' . $result->fields['Field'] . ']  ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\');' . "\n";
                                $postContent .= '        if ($model->' . $result->fields['Field'] . ' == 0) {' . "\n";
                                $postContent .= '            $model->' . $result->fields['Field'] . ' = null;' . "\n";
                                $postContent .= '        }' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {Number} ' . $result->fields['Field'] . '  ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', 0, \'post\');' . "\n";
                            }
                            $updateContent .= '      * @apiBody {Number} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\');' . "\n";
                        }
                        break;
                    case "bool":
                    case "boolean":
                        $returnContent .= '     * @apiSuccess {Boolean} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) { 
                            $saveContent .= '        $tmpVar = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\');' . "\n";
                            $saveContent .= '        if ($tmpVar == \'true\' || $tmpVar == \'on\' || $tmpVar == "yes" || $tmpVar === \'1\' || $tmpVar === 1) {' . "\n";
                            $saveContent .= '            $tmpVar = true; ' . "\n";
                            $saveContent .= '        } else { ' . "\n";
                            $saveContent .= '            $tmpVar = false; ' . "\n";
                            $saveContent .= '        } ' . "\n";
                            $saveContent .= '      * @apiBody {Boolean} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $postContent .= '        $model->' . $result->fields['Field'] . ' = $tmpVar;' . "\n";   
                        }
                        $updateContent .= '     * @apiBody {Boolean} [' . $result->fields['Field'] . ']  ' . $result->fields['Comment'] . "\n";
                        $putContent .= '       $model->' . $result->fields['Field'] . ' = \Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\', \'int\');' . "\n";
                        break;
                    case "json":
                        $returnContent .= '     * @apiSuccess {JSON} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {JSON} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\'));' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {JSON} ' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', \'\', \'post\'));' . "\n";
                            }
                            $updateContent .= '     * @apiBody {JSON} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = trim(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\'));' . "\n";
                        }
                        break;
                    default:
                        $returnContent .= '     * @apiSuccess {String} data.' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                        if (!$primary) {
                            if ($result->fields['Null'] == 'YES') {
                                $saveContent .= '     * @apiBody {String} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(strip_tags(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', null, \'post\')));' . "\n";
                            } else {
                                $saveContent .= '     * @apiBody {String} ' . $result->fields['Field'] . ' ' . $result->fields['Comment'] . "\n";
                                $postContent .= '        $model->' . $result->fields['Field'] . ' = trim(strip_tags(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', \'\', \'post\')));' . "\n";
                            }
                            $updateContent .= '     * @apiBody {String} [' . $result->fields['Field'] . '] ' . $result->fields['Comment'] . "\n";
                            $putContent .= '        $model->' . $result->fields['Field'] . ' = trim(strip_tags(\Pramnos\Http\Request::staticGet(\'' . $result->fields['Field'] .'\', $model->' . $result->fields['Field'] . ', \'put\')));' . "\n";
                        }
                        break;
                }

            }


            $fileContent .= <<<content
    /**
     * @api {get} 1.0/$modelClassLower List
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName list$modelClass
     * @apiDescription List of $modelClass objects
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     *
     *
     * @apiSuccess {Array} data List of $modelClass objects
$returnContent
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     */
    public function display()
    {

        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        if (\$user->userid < 2) {
            return array('status' => 401);
        }
        
        
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$list = \$model->getList();
        
        foreach (\$list as \$obj) {
            \$data[] = \$obj->getData();
        }

        return array('data' => \$data);
    }

    /**
     * @api {get} 1.0/$modelClassLower/:$primaryKey Read
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName read$modelClass
     * @apiDescription Read a specific $modelClass object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} $primaryKey Id to load
     *
     * @apiSuccess {{$modelClass}} data A $modelClass object
$returnContent
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function read$modelClass(\$$primaryKey)
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        if (\$user->userid < 2) {
            return array('status' => 401);
        }
        
        
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$model->load(\$$primaryKey);
        if (\$model->$primaryKey == 0) {
            return array(
                'status' => 404
            );
        }
        \$data = \$model->getData();
        return array('data' => \$data);
    }

    /**
     * @api {post} 1.0/$modelClassLower Create
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName create$modelClass
     * @apiDescription Create a $modelClass
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * 
$saveContent
     *
     * @apiSuccess {{$modelClass}} data A $modelClass object
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function create$modelClass()
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        
        

        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);

 
$postContent
        

        \$model->save();
        
        return array(
            'status' => 201,
            'data' => \$model->getData()
        );
    }


    /**
     * @api {put} 1.0/$modelClassLower/:$primaryKey Update
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName update$modelClass
     * @apiDescription Update a specific $modelClass object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} $primaryKey Id to update
     * 
     * 
$updateContent
     * @apiSuccess {{$modelClass}} data A $modelClass object
     * 
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function update$modelClass(\$$primaryKey)
    {
        if (!isset(\$_SESSION['user']) || !is_object(\$_SESSION['user'])) {
            return array('status' => 401);
        }
        \$user = \$_SESSION['user'];
        
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$model->load((int) \$$primaryKey);
        if (\$model->$primaryKey == 0) {
            return array(
                'status' => 404
            );
        }

 
$putContent

        
        \$model->save();
        return array(
            'status' => 202,
            'data' => \$model->getData()
        );
    }

    /**
     * @api {delete} 1.0/$modelClassLower/:$primaryKey Delete
     * @apiVersion 1.0.0
     * @apiGroup $modelClass
     * @apiName delte$modelClass
     * @apiDescription Delete a $modelClass
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} $primaryKey Id to delete
     *
     *
     * @apiUse InvalidAccessToken
     * @apiUse APIKeyMissing
     * @apiUse APIKeyInvalid
     * @apiUse InternalServerError
     *
     */
    public function delete$modelClass(\$$primaryKey)
    {
        \$model = new \\{$modelNameSpace}\\$modelClass(\$this);
        \$model->load((int) \$$primaryKey);
        if (\$model->$primaryKey == 0) {
            return array(
                'status' => 404
            );
        }
        \$model->delete(\$$primaryKey);
        return array(
            'status' => 202
        );

    }



}
content;


$routerContent = <<<content
\$router->delete(
    '/$modelClassLower/{{$primaryKey}}',
    function (\$$primaryKey) {
        \$controller = \$this->getController('$className');
        return \$controller->delete$modelClass(\$$primaryKey);
    }
);

\$router->put(
    '/$modelClassLower/{{$primaryKey}}',
    function (\$$primaryKey) {
        \$controller = \$this->getController('$className');
        return \$controller->update$modelClass(\$$primaryKey);
    }
);

\$router->get(
    '/$modelClassLower/{{$primaryKey}}',
    function (\$$primaryKey) {
        \$controller = \$this->getController('$className');
        return \$controller->read$modelClass(\$$primaryKey);
    }
);

\$router->get(
    '/$modelClassLower',
    function () {
        \$controller = \$this->getController('$className');
        return \$controller->display();
    }
);

\$router->post(
    '/$modelClassLower',
    function () {
        \$controller = \$this->getController('$className');
        return \$controller->create$modelClass();
    }
);

content;


      
        file_put_contents($filename, $fileContent);



        $routerFile = ROOT . '/src/Api/routes.php';
        $routerContentOriginal = file_get_contents($routerFile);
        if (strpos($routerContentOriginal, $routerContent) === false) {
            $routerContentOriginal = str_replace(
                'return $router->dispatch($newRequest);',
                $routerContent . "\n\n" . 'return $router->dispatch($newRequest);',
                $routerContentOriginal
            );
            file_put_contents($routerFile, $routerContentOriginal);
        }


        return "Namespace: {$namespace}\n"
            . "Class: {$className}\n"
            . "File: {$filename}\n\nController created. \n";

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
    public function __construct(\Pramnos\Application\Application \$application = null)
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


            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }
            if ($database->type == 'postgresql') {
                $sql = $database->prepareQuery(
                    "SELECT column_name as \"Field\", data_type as \"Type\", character_maximum_length, is_nullable as \"Null\", column_default, "
                    . "(SELECT pg_catalog.col_description(c.oid, a.ordinal_position) FROM pg_catalog.pg_class c WHERE c.oid = (SELECT relfilenode FROM pg_catalog.pg_class WHERE relname = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "')) AS \"Comment\" "
                    . "FROM information_schema.columns a "
                    . "WHERE table_name = '" 
                    . str_replace('#PREFIX#', $database->prefix, $tableName) . "'"
                );
                
            } else {
                $sql = $database->prepareQuery("SHOW FULL COLUMNS FROM `{$tableName}`");
            }
            $result = $database->query($sql);


            $saveContent = '';

            $primaryKey = '';
            while ($result->fetch()) {
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
     * Updates a model
     * @param string $name Model name
     * @param \Pramnos\Database\Result $result Database result
     * @param string $filename Filename of the model
     */
    protected function updateModel($name, \Pramnos\Database\Result $result, $filename)
    {
        $fileContent = '';
        
        while ($result->fetch()) {
            if (property_exists($name, $result->fields['Field'])) {
                continue;
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
                default: 
                    $type = 'string';
                    break;
            }

            $fileContent .= "    /**\n";
            if ($result->fields['Comment'] != '') {
                $fileContent .= "     * "
                    . $result->fields['Comment']
                    . "\n";
            }
            $fileContent .= "     * @var "
                . $type
                . "\n"
                . "     */\n"
                . "    public $"
                . $result->fields['Field']
                . ";\n";
        }
        if ($fileContent != '') {
            
            // Read the contents of the file
            $fileContents = file_get_contents($filename);
            
            // Find the position of the last property definition
            $lastPropertyPosition = strrpos($fileContents, 'public $');
            // Find the position of the end of the last property definition
            $lastPropertyEndPosition = strpos($fileContents, ';', $lastPropertyPosition);
            // Insert the new property definitions after the last property definition
            
            $fileContents = substr_replace($fileContents, "\n" . $fileContent, $lastPropertyEndPosition + 1, 0);
            // Write the modified contents back to the file
            file_put_contents($filename, $fileContents);

            return "File: {$filename}\n\nModel updated.";
        }
        return "Model exists and doesnt need an update.";
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


        if (!$database->tableExists($tableName)) {
            $tableName = '#PREFIX#' . strtolower($name);
            if (!$database->tableExists($tableName)) {
                throw new \Exception(
                    'Table: ' . $tableName . ' does not exist.'
                );
            }

        }

        if ($database->type == 'postgresql') {
            $sql = $database->prepareQuery(
                "SELECT column_name as \"Field\", data_type as \"Type\", character_maximum_length, is_nullable as \"Null\", column_default, "
                . "(SELECT col_description((SELECT oid FROM pg_class WHERE relname = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "'), a.ordinal_position)) AS \"Comment\", "
                . "column_name in ( "
                . "    SELECT column_name "
                . "    FROM information_schema.table_constraints tc "
                . "    JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name) "
                . "    WHERE constraint_type = 'PRIMARY KEY' "
                . "    AND tc.table_name = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "'"
                . ") as \"PrimaryKey\", "
                . "column_name in ( "
                . "    SELECT column_name "
                . "    FROM information_schema.key_column_usage "
                . "    WHERE table_name = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "' "
                . "    AND column_name = a.column_name "
                . "    AND constraint_name in ( "
                . "        SELECT constraint_name "
                . "        FROM information_schema.table_constraints "
                . "        WHERE table_name = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "' "
                . "        AND constraint_type = 'FOREIGN KEY' "
                . "    ) "
                . ") as \"ForeignKey\" "
                . "FROM information_schema.columns a "
                . "WHERE table_name = '" . str_replace('#PREFIX#', $database->prefix, $tableName) . "'"
            );

        } else {
            $sql = $database->prepareQuery("SHOW FULL COLUMNS FROM `{$tableName}`");
        }
        $result = $database->query($sql);
        

        
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
            && file_exists($filename)) {  
            return $this->updateModel('\\' . $namespace . '\\'. $className, $result, $filename);
        } elseif (class_exists('\\' . $namespace . '\\'. $className)
            && file_exists($filename)) {  
                throw new \Exception(
                    'Model already exists and cannot be updated'
                );
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


        $arrayFix = '';
        $foreignFixes = '';
        $primaryKey = '';
        while ($result->fetch()) {
            $primary = false;
            if ($database->type == 'postgresql') {
                if ($result->fields['PrimaryKey'] == 't') {
                    $primaryKey = $result->fields['Field'];
                    $primary = true;
                }
            } elseif (isset($result->fields['Key'])
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
                    if ($database->type == 'postgresql' && $result->fields['ForeignKey'] == "t") {
                        $foreignFixes .= '        if ($this->' . $result->fields['Field'] . ' == 0) {' . "\n";
                        $foreignFixes .= '            $this->' . $result->fields['Field'] . ' = null;' . "\n";
                        $foreignFixes .= '        }' . "\n";
                    }
                    $type = 'int';
                    $arrayFix .= '        if (isset($data[\'' . $result->fields['Field'] . '\']) &&  $data[\'' . $result->fields['Field'] . '\'] !== null) {' . "\n";
                    $arrayFix .= '            $data[\'' . $result->fields['Field'] . '\'] = (int) $this->' . $result->fields['Field'] . ";\n";
                    $arrayFix .= '        }' . "\n";
                    break;
                case "decimal":
                case "numeric":
                case "float":
                case "double":
                    $type = 'float';
                    $arrayFix .= '        if (isset($data[\'' . $result->fields['Field'] . '\']) &&  $data[\'' . $result->fields['Field'] . '\'] !== null) {' . "\n";
                    $arrayFix .= '            $data[\'' . $result->fields['Field'] . '\'] = (float) $this->' . $result->fields['Field'] . ";\n";
                    $arrayFix .= '        }' . "\n";
                    break;
                case "bool":
                case "boolean":
                    $type = 'bool';
                    $arrayFix .= '        $data[\'' . $result->fields['Field'] . '\'] = (bool) $this->' . $result->fields['Field'] . ";\n";
                    break;
                default: 
                    $type = 'string';
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
     * @param string \$key Primary key on database
     * @param boolean   \$debug Show debug information
     * @return \$this
     */
    public function load({$primaryKeyVal},
        \$key = NULL, \$debug = false)
    {
        return parent::_load({$primaryKeyVal}, null, \$key, \$debug);
    }

    /**
     * Save to database
     * @param boolean   \$autoGetValues If true, get all values from \$_REQUEST
     * @param boolean   \$debug Show debug information (and die)
     * @return          \$this
     */
    public function save(\$autoGetValues = false, \$debug = false)
    {
$foreignFixes
        return parent::_save(null, null, \$autoGetValues, \$debug);
    }


    /**
     * Delete from database
     * @param integer {$primaryKeyVal} ID to delete
     * @return \$this
     */
    public function delete({$primaryKeyVal})
    {
        return parent::_delete({$primaryKeyVal}, null, null);
    }

    /**
     * Return all data as array
     * @return array
     */
    public function getData()
    {
        \$data = parent::getData();
$arrayFix
        return \$data;
    }

    /**
     * List objects
     * @param string \$filter Filter for where statement in database query
     * @param string \$order Order for database query
     * @return {$className}[]
     */
    public function getList(\$filter = NULL, \$order = NULL)
    {
        return parent::_getList(\$filter, \$order);
    }

}
content;

        file_put_contents($filename, $fileContent);

        return "Namespace: {$namespace}\n"
        . "Class: {$className}\n"
        . "File: {$filename}\n\nModel created.";


    }


}