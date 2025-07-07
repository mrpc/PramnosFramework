# Pramnos Framework Guide

## Overview

Pramnos is a PHP MVC framework designed for building robust web applications with a focus on security, modularity, and clean code architecture. This guide covers the framework's structure, conventions, and best practices.

## Framework Architecture

### Core Components

The Pramnos framework follows the Model-View-Controller (MVC) pattern with these key components:

- **Controllers**: Handle HTTP requests and business logic
- **Models**: Manage data and business rules
- **Views**: Present data to users (HTML templates)
- **Application**: Central application management
- **Database**: Data access layer with security features

### Directory Structure

```
src/
├── Controllers/          # Application controllers
├── Models/              # Data models and business logic
├── Views/               # HTML templates and view files
├── Api/                 # API controllers and endpoints
│   └── Controllers/     # API-specific controllers
├── OAuth2/              # OAuth2 specific components
│   ├── routes.php       # OAuth2 route definitions
│   └── sso_routes.php   # SSO route definitions
└── Application.php      # Main application class

app/
├── api.php              # API application entry point
├── app.php              # Main application entry point
├── config/              # Configuration files
├── language/            # Internationalization files
├── Migrations/          # Database migration files
└── themes/              # UI themes and assets

www/
├── index.php            # Web application entry point
└── api/                 # API endpoint entry points
```

## Controllers

### Basic Controller Structure

```php
<?php
namespace YourNamespace\Controllers;

class ExampleController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Define public actions (no authentication required)
        $this->addaction(['public_action', 'another_public']);
        
        // Define authenticated actions (login required)
        $this->addAuthAction(['private_action', 'dashboard']);
        
        // Set module name for views and navigation
        $this->_modulename = 'Example';
        
        parent::__construct($application);
    }
    
    public function display()
    {
        // Default action when controller is accessed without specific method
        return $this->dashboard();
    }
    
    public function dashboard()
    {
        $view = $this->getView('Example');
        
        // Set page title and breadcrumbs
        $this->header = 'Dashboard';
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'My Dashboard';
        $this->addbreadcrumb('Dashboard', sURL . 'Example/dashboard');
        
        return $view->display('dashboard');
    }
}
```

### Controller Conventions

1. **Class Names**: Use PascalCase (e.g., `UserDashboard`, `ApiController`)
2. **File Names**: Match class names (e.g., `UserDashboard.php`)
3. **Namespaces**: Use project-specific namespaces (e.g., `Project\Controllers`)
4. **Methods**: Use camelCase for action methods

### Authentication and Authorization

```php
// Public actions (no login required)
$this->addaction(['login', 'register', 'forgotPassword']);

// Authenticated actions (login required)
$this->addAuthAction(['dashboard', 'profile', 'settings']);

// Check if user is authenticated
$currentUser = \Pramnos\User\User::getCurrentUser();
if ($currentUser) {
    // User is logged in
    $userId = $currentUser->userid;
    $username = $currentUser->username;
}
```

### URL Handling and Redirects

```php
// Always use sURL constant for URLs (works in subdirectories)
$this->redirect(sURL . 'Controller/action');

// Add breadcrumbs
$this->addbreadcrumb('Home', sURL);
$this->addbreadcrumb('Dashboard', sURL . 'Dashboard/dashboard');

// Set page headers
$this->header = 'Page Title';
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->title = 'Browser Title';
```



## Views and Templates

### View Structure

```php
// In controller
$view = $this->getView('ViewName');
$view->data = $someData;
$view->user = $currentUser;
return $view->display('template_name');
```

**Important**: View template files must use the `.html.php` extension, not just `.html`. This allows for PHP code execution within templates when needed.

### Template Files

Templates are stored in `/src/Views/ViewName/template_name.html.php`:

```html
<div>
    <h1>{{header}}</h1>
    
    <!-- Use sURL for all links -->
    <a href="<?php echo sURL;?>Controller/action">Link Text</a>
    
    <!-- Display data -->
    <p>Welcome, <?php echo $this->user->username;?>!</p>
    
    
</div>
```


## Routing



### URL Patterns

- **Controllers**: `/ControllerName/action`

## Application Configuration

### Application Class

```php
<?php
namespace YourNamespace;

class Application extends \Pramnos\Application\Application
{
    public function __construct()
    {
        parent::__construct();
        
        // Set application-specific configuration
        $this->setConfig('app_name', 'Your App Name');
        $this->setConfig('version', '1.0.0');
    }
    
    public function exec($query = '')
    {
        // Custom application logic before execution
        
        // Call parent execution
        parent::exec($query);
    }
}
```

### Configuration Files

Configuration is typically stored in `/app/config/settings.php`:

```php
<?php
return [
    'database' => [
        'host' => 'localhost',
        'username' => 'dbuser',
        'password' => 'dbpass',
        'database' => 'dbname'
    ],
    'app' => [
        'name' => 'Your Application',
        'version' => '1.0.0',
        'timezone' => 'UTC'
    ],
    'security' => [
        'session_timeout' => 3600,
        'password_hash_algo' => PASSWORD_DEFAULT
    ]
];
```


## Error Handling

### Adding Errors

```php
// Add error messages
$this->addError('Something went wrong');
$this->addError('Validation failed: ' . $validationMessage);

// Check for errors
if ($this->hasErrors()) {
    // Handle errors
    return $this->showErrorPage();
}
```

### Exception Handling

```php
try {
    // Risky operation
    $result = $this->performOperation();
} catch (\Exception $e) {
    $this->addError('Operation failed: ' . $e->getMessage());
    error_log($e->getMessage());
    return $this->showErrorPage();
}
```


## Framework Factory Classes

### Common Factory Usage

```php
// Get authentication handler
$auth = \Pramnos\Framework\Factory::getAuth();

// Get document handler
$doc = \Pramnos\Framework\Factory::getDocument();

// Get current user
$user = \Pramnos\User\User::getCurrentUser();

// Get database instance (alternative method)
$database = \Pramnos\Database\Database::getInstance();
```

This guide provides a comprehensive overview of the Pramnos framework structure and conventions. Use it as a reference for building consistent, secure, and maintainable applications within the Pramnos ecosystem.

---

## Related Documentation

- **[Database API Guide](Pramnos_Database_API_Guide.md)** - Detailed database operations and best practices
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** - User authentication and authorization patterns
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Performance optimization through caching
- **[Console Commands Guide](Pramnos_Console_Guide.md)** - Code generation and development tools
- **[Logging System Guide](Pramnos_Logging_Guide.md)** - Application monitoring and debugging
- **[Document & Output Guide](Pramnos_Document_Output_Guide.md)** - Document generation and output formats
- **[Theme System Guide](Pramnos_Theme_Guide.md)** - UI theming and template customization
- **[Email System Guide](Pramnos_Email_Guide.md)** - Email handling and notifications
- **[Media System Guide](Pramnos_Media_Guide.md)** - File uploads and media processing
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** - Multi-language application support

---

For specific implementation details and advanced features, explore the specialized guides above to deepen your understanding of each framework component.
