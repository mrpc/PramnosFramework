# Pramnos Console Commands Guide

## Overview

The Pramnos Framework includes a powerful console command system built on Symfony Console components. The console system provides code generation, maintenance tools, and administrative utilities to streamline development workflows.

## Available Commands

### Code Generation Commands

The framework provides comprehensive code generation through the `create` command:

```bash
# Create a new model
php bin/pramnos create model User

# Create a controller
php bin/pramnos create controller UserController

# Create a view
php bin/pramnos create view User

# Create complete CRUD system (model + controller + view)
php bin/pramnos create crud User

# Create API endpoint
php bin/pramnos create api UserAPI

# Create database migration
php bin/pramnos create migration CreateUsersTable
```

### Server Commands

```bash
# Start development server
php bin/pramnos serve

# Start server on specific port
php bin/pramnos serve --port=8080
```

### Maintenance Commands

```bash
# Migrate log files to structured format
php bin/pramnos migratelogs /path/to/logs --all

# Migrate specific log file
php bin/pramnos migratelogs /path/to/file.log

# Migrate without creating backup
php bin/pramnos migratelogs /path/to/file.log --no-backup
```

## Model Generation

### Basic Model Creation

```bash
php bin/pramnos create model User
```

This generates a model class with:
- Database table mapping
- Primary key configuration
- Basic CRUD methods
- Type-safe property declarations
- API list method with pagination

### Generated Model Structure

```php
<?php
namespace MyApp\Models;

/**
 * User Model
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\Model
{
    /**
     * User ID
     * @var int
     */
    public $userid;
    
    /**
     * Username
     * @var string
     */
    public $username;
    
    /**
     * Email address
     * @var string
     */
    public $email;
    
    /**
     * Primary key in database
     * @var string
     */
    protected $_primaryKey = "userid";

    /**
     * Database table
     * @var string
     */
    protected $_dbtable = "users";

    /**
     * Load from database
     * @param int $userid ID to load
     * @param string $key Primary key on database
     * @param boolean $debug Show debug information
     * @return $this
     */
    public function load($userid, $key = NULL, $debug = false)
    {
        return parent::_load($userid, null, $key, $debug);
    }

    /**
     * Save to database
     * @param boolean $autoGetValues If true, get all values from $_REQUEST
     * @param boolean $debug Show debug information (and die)
     * @return $this
     */
    public function save($autoGetValues = false, $debug = false)
    {
        return parent::_save(null, null, $autoGetValues, $debug);
    }

    /**
     * Delete from database
     * @param integer $userid ID to delete
     * @return $this
     */
    public function delete($userid)
    {
        return parent::_delete($userid, null, null);
    }

    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     */
    public function getApiList($fields = array(), $search = '', 
        $order = '', $page = 0, $itemsPerPage = 10, 
        $debug = false, $returnAsModels = false, $useGetData = true)
    {
        return parent::_getApiList(
            $fields, $search, $order, '', '', '',
            null, null, $page, $itemsPerPage, $debug, $returnAsModels, $useGetData
        );
    }
}
```

### Model Generation Options

```bash
# Generate model for specific table
php bin/pramnos create model User --table=custom_users

# Generate model with schema specification (PostgreSQL)
php bin/pramnos create model User --schema=public
```

### Model Registry

Generated models are automatically registered in `app/model-registry.json`:

```json
[
    {
        "className": "User",
        "namespace": "MyApp\\Models",
        "fullClassName": "MyApp\\Models\\User",
        "table": "users",
        "schema": "",
        "createdAt": "2024-12-25T10:30:00+00:00",
        "updatedAt": "2024-12-25T10:30:00+00:00"
    }
]
```

## Controller Generation

### Basic Controller Creation

```bash
php bin/pramnos create controller UserController
```

### CRUD Controller Generation

```bash
php bin/pramnos create controller User --full
```

This generates a complete CRUD controller with:
- Display action (list view)
- Show action (detail view)
- Edit action (create/update form)
- Save action (form processing)
- Delete action (record removal)
- JSON data method for datatables

### Generated Controller Structure

```php
<?php
namespace MyApp\Controllers;

/**
 * User Controller
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\Controller
{
    /**
     * User controller constructor
     * @param Application $application
     */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(
            array('edit', 'save', 'delete', 'show', 'getUser')
        );
        parent::__construct($application);
    }
    
    /**
     * Display a listing of the resource
     * @return string
     */
    public function display()
    {
        $view = $this->getView('user');
        $model = new \MyApp\Models\User($this);

        $view->items = $model->getList();
        $this->application->addbreadcrumb('User', sURL . 'User');
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'User';
        return $view->display();
    }

    /**
     * Display the specified resource
     * @return string
     */
    public function show()
    {
        $view = $this->getView('user');
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->load($request->getOption());
        $view->addModel($model);
        $this->application->addbreadcrumb('User', sURL . 'User');
        $this->application->addbreadcrumb('View ' . $model->userid, sURL . 'User/show/' . $model->userid);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $model->userid . ' | User';
        return $view->display('show');
    }

    /**
     * Show the form for creating a new resource or editing an existing one
     * @return string
     */
    public function edit()
    {
        $view = $this->getView('user');
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->load($request->getOption());
        $view->addModel($model);

        $this->application->addbreadcrumb('User', sURL . 'User');
        if ($model->userid > 0) {
            $this->application->addbreadcrumb('View ' . $model->userid, sURL . 'User/show/' . $model->userid);
            $this->application->addbreadcrumb('Edit', sURL . 'User/edit/' . $model->userid);
        } else {
            $this->application->addbreadcrumb('Create', sURL . 'User/edit/0');
        }
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = ($model->userid > 0 ? 'Edit' : 'Create') . ' | User';
        
        return $view->display('edit');
    }

    /**
     * Store a newly created or edited resource in storage.
     */
    public function save()
    {
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->load($request->getOption());
        
        // Auto-generated field assignments based on database schema
        $model->username = trim(strip_tags($request->get('username', '', 'post')));
        $model->email = trim(strip_tags($request->get('email', '', 'post')));
        $model->firstname = trim(strip_tags($request->get('firstname', '', 'post')));
        
        $model->save();
        $this->redirect(sURL . 'User');
    }

    /**
     * Remove the specified resource from storage
     */
    public function delete()
    {
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->delete($request->getOption());
        $this->redirect(sURL . 'User');
    }

    /**
     * Returns the resource in JSON format
     * @return string
     */
    public function getUser()
    {
        $model = new \MyApp\Models\User($this);
        \Pramnos\Framework\Factory::getDocument('json');
        return $model->getJsonList();
    }
}
```

## View Generation

### Basic View Creation

```bash
php bin/pramnos create view User
```

### Full CRUD Views

```bash
php bin/pramnos create view User --full
```

This generates complete view templates:
- `index.html.php` - List view with datatables
- `edit.html.php` - Create/edit form
- `show.html.php` - Detail view

### Generated View Examples

#### List View (index.html.php)
```php
<div class="card">
    <div class="card-header">
        <h1 class="page-head-line">User list</h1>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo sURL; ?>User/edit/0">
                    <button type="button" class="btn btn-primary">
                        <i class="fa fa-plus"></i> <?php l('New'); ?>
                    </button>
                </a>
            </div>
            <br /><br />
        </div>
        
        <?php
        $datatable = new \Pramnos\Html\Datatable('user', URL . 'User/getUser');
        
        // Auto-generated columns based on database schema
        $datatable->addColumn('Username', true, true, true, '', '', true, 'left', true);
        $datatable->addColumn('Email', true, true, true, '', '', true, 'left', true);
        $datatable->addColumn('First Name', true, true, true, '', '', true, 'left', true);
        $datatable->addColumn('Actions');
        
        $datatable->jui = false;
        $datatable->bootstrap = true;
        echo $datatable->render();
        ?>
    </div>
</div>
```

#### Edit Form (edit.html.php)
```php
<div class="card">
    <div class="card-body">
        <form action="[sURL]User/save/<?php echo $this->model->userid; ?>" method="post" role="form">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" value="<?php echo $this->model->username; ?>" 
                       id="username" name="username" class="form-control">
            </div>

            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" value="<?php echo $this->model->email; ?>" 
                       id="email" name="email" class="form-control">
            </div>

            <div class="form-group">
                <label for="firstname">First Name:</label>
                <input type="text" value="<?php echo $this->model->firstname; ?>" 
                       id="firstname" name="firstname" class="form-control">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary"><?php l('Save'); ?></button>
            </div>
        </form>
    </div>
</div>
```

## API Generation

### Creating API Endpoints

```bash
php bin/pramnos create api UserAPI
```

This generates a complete REST API controller with:
- GET endpoints for listing and individual records
- POST endpoints for creating records
- PUT endpoints for updating records
- DELETE endpoints for removing records
- Automatic API documentation (PHPDoc format)

### Generated API Controller

```php
<?php
namespace MyApp\Api\Controllers;

/**
 * UserAPI Controller
 * Auto generated at: 25/12/2024 10:30
 */
class UserAPI extends \Pramnos\Application\Controller
{
    /**
     * @api {get} 1.0/user List
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName listUser
     * @apiDescription List of User objects with pagination, search, sorting and field selection
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     *
     * @apiParam  {Number} [page=0] Page number for pagination. Set to 0 to get all results
     * @apiParam  {Number} [limit=20] Limit number of results per page
     * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname
     * @apiParam  {String} [search] Global search term or JSON object for field-specific search
     * @apiParam  {String} [fields] Specify which fields to return (comma-separated or JSON array)
     */
    public function display()
    {
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            return array('status' => 401);
        }
        $user = $_SESSION['user'];
        if ($user->userid < 2) {
            return array('status' => 401);
        }
        
        $model = new \MyApp\Models\User($this);
        
        // Get parameters from request
        $fields = \Pramnos\Http\Request::staticGet('fields', array(), 'get');
        $search = \Pramnos\Http\Request::staticGet('search', '', 'get');
        $sort = \Pramnos\Http\Request::staticGet('sort', '', 'get');
        $page = (int) \Pramnos\Http\Request::staticGet('page', 0, 'get', 'int');
        $limit = (int) \Pramnos\Http\Request::staticGet('limit', 20, 'get', 'int');
        
        // Use the new getApiList method for enhanced pagination, search, and field selection
        return $model->getApiList(
            $fields, 
            $search, 
            $sort, 
            $page, 
            $limit,
            false, // debug
            false, // returnAsModels
            false   // useGetData
        );
    }

    /**
     * @api {get} 1.0/user/:userid Read
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName readUser
     * @apiDescription Read a specific User object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} userid Id to load
     */
    public function readUser($userid)
    {
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            return array('status' => 401);
        }
        $user = $_SESSION['user'];
        if ($user->userid < 2) {
            return array('status' => 401);
        }
        
        $model = new \MyApp\Models\User($this);
        $model->load((int) $userid);
        if ($model->userid == 0) {
            return array('status' => 404);
        }
        $data = $model->getData();
        return array('data' => $data);
    }

    /**
     * @api {post} 1.0/user Create
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName createUser
     * @apiDescription Create a User
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * 
     * @apiBody {String} username Username
     * @apiBody {String} email Email address
     * @apiBody {String} [firstname] First name
     */
    public function createUser()
    {
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            return array('status' => 401);
        }
        $user = $_SESSION['user'];
        
        $model = new \MyApp\Models\User($this);

        $model->username = trim(strip_tags(\Pramnos\Http\Request::staticGet('username', '', 'post')));
        $model->email = trim(strip_tags(\Pramnos\Http\Request::staticGet('email', '', 'post')));
        $model->firstname = trim(strip_tags(\Pramnos\Http\Request::staticGet('firstname', '', 'post')));

        $model->save();
        
        return array(
            'status' => 201,
            'data' => $model->getData()
        );
    }

    /**
     * @api {put} 1.0/user/:userid Update
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName updateUser
     * @apiDescription Update a specific User object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} userid Id to update
     */
    public function updateUser($userid)
    {
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            return array('status' => 401);
        }
        $user = $_SESSION['user'];
        
        $model = new \MyApp\Models\User($this);
        $model->load((int) $userid);
        if ($model->userid == 0) {
            return array('status' => 404);
        }

        $model->username = trim(strip_tags(\Pramnos\Http\Request::staticGet('username', $model->username, 'put')));
        $model->email = trim(strip_tags(\Pramnos\Http\Request::staticGet('email', $model->email, 'put')));
        $model->firstname = trim(strip_tags(\Pramnos\Http\Request::staticGet('firstname', $model->firstname, 'put')));
        
        $model->save();
        return array(
            'status' => 202,
            'data' => $model->getData()
        );
    }

    /**
     * @api {delete} 1.0/user/:userid Delete
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName deleteUser
     * @apiDescription Delete a User
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} userid Id to delete
     */
    public function deleteUser($userid)
    {
        $model = new \MyApp\Models\User($this);
        $model->load((int) $userid);
        if ($model->userid == 0) {
            return array('status' => 404);
        }
        $model->delete($userid);
        return array('status' => 202);
    }
}
```

### API Route Registration

API routes are automatically added to `src/Api/routes.php`:

```php
$router->delete(
    '/user/{userid}',
    function ($userid) {
        $controller = $this->getController('UserAPI');
        return $controller->deleteUser($userid);
    }
);

$router->put(
    '/user/{userid}',
    function ($userid) {
        $controller = $this->getController('UserAPI');
        return $controller->updateUser($userid);
    }
);

$router->get(
    '/user/{userid}',
    function ($userid) {
        $controller = $this->getController('UserAPI');
        return $controller->readUser($userid);
    }
);

$router->get(
    '/user',
    function () {
        $controller = $this->getController('UserAPI');
        return $controller->display();
    }
);

$router->post(
    '/user',
    function () {
        $controller = $this->getController('UserAPI');
        return $controller->createUser();
    }
);
```

## CRUD Generation

### Complete CRUD System

```bash
php bin/pramnos create crud User
```

This single command creates:
1. Model with database mapping
2. Controller with full CRUD operations
3. Views for list, create, edit, and detail pages

The output shows the status of each component:

```
Creating Model: OK
Creating Controller: OK
Creating View: OK
```

## Migration System

### Creating Migrations

```bash
php bin/pramnos create migration CreateUsersTable
```

This generates a migration class:

```php
<?php
namespace MyApp\Migrations;

/**
 * CreateUsersTable migration
 * Auto generated at: 25/12/2024 10:30
 */
final class MigrationCreateUsersTable extends \Pramnos\Database\Migration
{
    /**
     * Version that this migration sets
     * @var string
     */
    public $version = 'CreateUsersTable';
    
    /**
     * Description of the migration
     * @var string
     */
    public $description = '';
    
    /**
     * Should the migration executed automatically
     * @var bool
     */
    public $autoExecute = true;

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
```

### Migration Registry

Migrations are automatically registered in `app/migrations.php`:

```php
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
    'CreateUsersTable' => 'MigrationCreateUsersTable'
];
```

## Development Server

### Starting the Server

```bash
# Start on default port (8000)
php bin/pramnos serve

# Start on custom port
php bin/pramnos serve --port=8080

# Start with custom host
php bin/pramnos serve --host=0.0.0.0 --port=8080
```

The development server provides:
- Hot reloading for PHP files
- Automatic routing
- Error display
- Access to framework features

## Log Migration

### Migrating Log Files

The framework includes a powerful log migration system to convert legacy log formats to structured JSON:

```bash
# Migrate all .log files in a directory
php bin/pramnos migratelogs /path/to/logs --all

# Migrate specific file
php bin/pramnos migratelogs /path/to/application.log

# Migrate without backup
php bin/pramnos migratelogs /path/to/application.log --no-backup
```

### Migration Features

- **Preserves timestamps** - Extracts timestamps from various log formats
- **Handles multiline logs** - Properly processes stack traces and error messages
- **Creates backups** - Original files are backed up with `.bak` extension
- **Progress tracking** - Shows progress bar for large files
- **Error handling** - Continues processing if individual lines fail

### Example Migration Output

```
Processing: application.log

 1000/1000 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

Migration completed successfully:
Files processed: 1 (Failed: 0)
Lines processed: 1000 (Converted: 987)
Duration: 0.45 seconds
```

## Advanced Features

### Foreign Key Detection

The code generator automatically detects foreign key relationships and generates appropriate model methods:

```php
// If a 'user_id' field is detected, this method is auto-generated
public function getUser()
{
    if ($this->user_id > 0) {
        $user = new \MyApp\Models\User($this);
        $user->load($this->user_id);
        return $user;
    }
    return null;
}
```

### Data Type Handling

The generator creates type-safe field assignments based on database schema:

```php
// Integer fields
$model->count = \Pramnos\Http\Request::staticGet('count', 0, 'post', 'int');

// Boolean fields  
$tmpVar = \Pramnos\Http\Request::staticGet('active', '', 'post');
if ($tmpVar == 'true' || $tmpVar == 'on' || $tmpVar == "yes" || $tmpVar === '1' || $tmpVar === 1) {
    $tmpVar = true; 
} else { 
    $tmpVar = false; 
} 
$model->active = $tmpVar;

// Float fields
$model->price = (float) \Pramnos\Http\Request::staticGet('price', '', 'post');

// String fields (with sanitization)
$model->name = trim(strip_tags(\Pramnos\Http\Request::staticGet('name', '', 'post')));
```

### API Documentation Generation

Generated API controllers include comprehensive PHPDoc annotations compatible with API documentation tools:

```php
/**
 * @api {get} 1.0/user List
 * @apiVersion 1.0.0
 * @apiGroup User
 * @apiName listUser
 * @apiDescription List of User objects with pagination, search, sorting and field selection
 *
 * @apiHeader {String} apiKey Application unique api key
 * @apiHeader {String} accessToken Authenticated user access token
 *
 * @apiParam  {Number} [page=0] Page number for pagination
 * @apiParam  {Number} [limit=20] Limit number of results per page
 * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname
 * @apiParam  {String} [search] Global search term or JSON field-specific search
 * @apiParam  {String} [fields] Comma-separated or JSON array of fields to return
 *
 * @apiSuccess {Array} data List of User objects
 * @apiSuccess {Object} [pagination] Pagination information (only when page > 0)
 * @apiSuccess {Number} pagination.currentpage Current page number
 * @apiSuccess {Number} pagination.itemsperpage Items per page
 * @apiSuccess {Number} pagination.totalitems Total number of items
 * @apiSuccess {Number} pagination.totalpages Total number of pages
 * @apiSuccess {Boolean} pagination.hasnext Whether there is a next page
 * @apiSuccess {Boolean} pagination.hasprevious Whether there is a previous page
 * @apiSuccess {Array} fields List of fields included in the response
 */
```

## Configuration

### Console Application Setup

The console application is configured in `src/Pramnos/Console/Application.php`:

```php
protected function registerCommands()
{
    $this->add(new \Pramnos\Console\Commands\Create());
    $this->add(new \Pramnos\Console\Commands\Serve());
    $this->add(new \Pramnos\Console\Commands\MigrateLogs());
    
    // Add custom commands here
    // $this->add(new \MyApp\Console\CustomCommand());
}
```

### Custom Command Creation

You can create custom console commands by extending Symfony's Command class:

```php
<?php
namespace MyApp\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomCommand extends Command
{
    protected function configure()
    {
        $this->setName('custom:task');
        $this->setDescription('Execute custom task');
        $this->setHelp('This command performs a custom task');
        
        $this->addArgument('parameter', InputArgument::REQUIRED, 'Required parameter');
        $this->addOption('option', 'o', InputOption::VALUE_OPTIONAL, 'Optional parameter');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $parameter = $input->getArgument('parameter');
        $option = $input->getOption('option');
        
        $output->writeln("Executing custom task with parameter: $parameter");
        
        // Your custom logic here
        
        return Command::SUCCESS;
    }
}
```

## Best Practices

### Model Generation

1. **Use descriptive table names** - The generator uses table names to create class names
2. **Define proper primary keys** - Ensure your tables have clear primary key definitions
3. **Add column comments** - Database column comments become PHPDoc annotations
4. **Use consistent naming** - Follow your project's naming conventions

### Controller Generation

1. **Plan your actions** - Consider which actions need authentication
2. **Use meaningful names** - Controller names should reflect their purpose
3. **Review generated code** - Always review and customize generated controllers
4. **Add validation** - Implement proper input validation in save methods

### API Development

1. **Design RESTful endpoints** - Follow REST conventions for API design
2. **Implement proper authentication** - Use JWT or session-based auth
3. **Add input validation** - Validate all API inputs
4. **Handle errors gracefully** - Return appropriate HTTP status codes
5. **Document your APIs** - Maintain the generated API documentation

### Database Migrations

1. **Make migrations idempotent** - Migrations should be safe to run multiple times
2. **Use descriptive names** - Migration names should describe what they do
3. **Test migrations** - Always test migrations on development data first
4. **Keep migrations small** - Break large changes into smaller migrations

### Development Workflow

1. **Start with models** - Generate models first to establish data structure
2. **Create controllers** - Build controllers with required business logic
3. **Design views** - Create user-friendly interfaces
4. **Build APIs** - Add API endpoints for mobile/frontend integration
5. **Write tests** - Create tests for critical functionality

## Troubleshooting

### Common Issues

**Database Connection Errors**
```bash
# Ensure database configuration is correct in your app settings
# Check database credentials and connection
```

**Permission Errors**
```bash
# Ensure the web server has write permissions to:
# - app/ directory (for registry files)
# - includes/ directory (for generated files)
# - logs/ directory (for logging)

chmod -R 755 app/ includes/ logs/
```

**Generated Code Issues**
- Review generated code for customization needs
- Check namespace configuration in application settings
- Verify database table structure matches expectations

**Console Command Not Found**
- Ensure `bin/pramnos` has execute permissions
- Check PHP CLI is available and working
- Verify Composer dependencies are installed

The Pramnos console system provides a comprehensive set of tools for rapid application development, making it easy to scaffold complete applications with minimal manual coding while maintaining code quality and consistency.

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - Understanding MVC architecture for generated code
- **[Database API Guide](Pramnos_Database_API_Guide.md)** - Database patterns used in generated models and controllers
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** - Implementing authentication in generated controllers
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Adding caching to generated code
- **[Logging System Guide](Pramnos_Logging_Guide.md)** - Log migration tools and monitoring generated applications
- **[Email System Guide](Pramnos_Email_Guide.md)** - Adding email functionality to generated controllers
- **[Media System Guide](Pramnos_Media_Guide.md)** - Integrating file uploads in generated forms
- **[Theme System Guide](Pramnos_Theme_Guide.md)** - Customizing generated view templates
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** - Adding multi-language support to generated components

---

For more information on customizing generated code and understanding the framework patterns, see the [Framework Guide](Pramnos_Framework_Guide.md) for detailed explanations of controllers, models, and views.
