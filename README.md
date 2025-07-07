# Pramnos Framework

Pramnos Framework is a comprehensive PHP MVC framework designed for building robust web applications. It combines proven design patterns with modern development practices, providing a solid foundation for secure, scalable, and maintainable applications.

## 📚 Documentation

For comprehensive documentation, please refer to:

- **[Framework Guide](docs/Pramnos_Framework_Guide.md)** - Complete guide to the framework architecture, controllers, views, and best practices
- **[Database API Guide](docs/Pramnos_Database_API_Guide.md)** - Detailed documentation on database operations and patterns
- **[Cache System Guide](docs/Pramnos_Cache_Guide.md)** - Caching implementation and usage
- **[Authentication Guide](docs/Pramnos_Authentication_Guide.md)** - User authentication and session management
- **[Console Commands Guide](docs/Pramnos_Console_Guide.md)** - Command-line tools and utilities
- **[Logging System Guide](docs/Pramnos_Logging_Guide.md)** - Comprehensive logging, analytics, and monitoring
- **[Document & Output Guide](docs/Pramnos_Document_Output_Guide.md)** - Multi-format output, document generation, and asset management
- **[Theme System Guide](docs/Pramnos_Theme_Guide.md)** - Template system, widgets, menus, and theming best practices

## Requirements

- PHP 7.4 or higher (8.0+ recommended)
- ext-mbstring extension
- ext-pdo extension (for database support)
- Optional: Redis/Memcached for caching

## Installation

```bash
composer require mrpc/pramnosframework
```

## ✨ Key Features

### 🏗️ Architecture
- **MVC Design Pattern** - Clean separation of concerns
- **Component-Based** - Modular and reusable components
- **Namespace Support** - PSR-4 compliant autoloading
- **Factory Pattern** - Centralized object creation and management

### 🔐 Authentication & Security
- **JWT Token Support** - Secure token-based authentication
- **Session Management** - Robust session handling with multiple storage backends
- **Permission System** - Granular access control and user permissions
- **OAuth2 Support** - Built-in OAuth2 server capabilities
- **CSRF Protection** - Request validation and security

### 💾 Database & Caching
- **Database Abstraction** - Support for MySQL, PostgreSQL
- **Query Builder** - Secure parameterized queries with printf-style formatting
- **Multiple Cache Backends** - Redis, Memcached, File-based caching
- **Database Migrations** - Version-controlled database schema changes
- **Connection Pooling** - Efficient database connection management

### 🌐 Web Features
- **RESTful Routing** - Flexible URL routing with parameter binding
- **API Framework** - Built-in REST API support with versioning
- **Multiple Output Formats** - JSON, XML, HTML, PDF, RSS
- **Theme System** - Pluggable theming with template inheritance
- **Multilingual Support** - Complete internationalization framework

### 🛠️ Developer Tools
- **Console Commands** - Code generators and maintenance tools
- **Logging System** - Structured logging with multiple handlers
- **Debug Tools** - Built-in debugging and profiling utilities
- **Testing Support** - PHPUnit integration and test helpers

### 📦 Additional Components
- **Media Handling** - Image processing and file management
- **Email System** - SMTP support with template rendering
- **Geolocation** - Geographic utilities and distance calculations
- **HTML Utilities** - Form builders, datatables, and UI components

## Directory Structure

```
src/Pramnos/
├── Addon/         # Extension modules
├── Application/   # Application core
├── Auth/          # Authentication components
├── Cache/         # Caching utilities
├── Console/       # CLI commands
├── Database/      # Database interaction
├── Document/      # Document handling
├── Email/         # Email services
├── Filesystem/    # File operations
├── Framework/     # Core framework classes
├── General/       # General utilities
├── Geolocation/   # Geolocation services
├── Html/          # HTML utilities
├── Http/          # HTTP request/response
├── Interfaces/    # Framework interfaces
├── Logs/          # Logging functionality
├── Media/         # Media handling
├── Routing/       # URL routing
├── Theme/         # Theming system
├── Translator/    # Translation services
├── User/          # User management
└── helpers.php    # Global helper functions
```

## 🚀 Quick Start

### Basic Application Setup

```php
<?php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

// Set the path to your root app directory
define('ROOT', dirname(__DIR__));

// Define start point for performance tracking
define('SP', microtime(true));

// Create an application instance
$app = new Pramnos\Application\Application();

// Initialize the application
$app->init();

// Execute the application
$app->exec();

// Render the output
echo $app->render();
```

### Creating Your First Controller

```php
<?php
namespace MyApp\Controllers;

class WelcomeController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Define public actions (no authentication required)
        $this->addaction(['display', 'about']);
        
        // Define authenticated actions (login required)
        $this->addAuthAction(['dashboard', 'profile']);
        
        parent::__construct($application);
    }
    
    public function display()
    {
        $view = $this->getView('Welcome');
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Welcome to Pramnos Framework';
        
        $this->application->addBreadcrumb('Home', sURL);
        
        return $view->display('welcome');
    }
}
```

### Database Operations

```php
// Using the Pramnos database pattern
$sql = $this->application->database->prepareQuery(
    "SELECT * FROM users WHERE email = %s AND status = %d", 
    $email, 
    $status
);
$result = $this->application->database->query($sql);

// Single record
if ($result->numRows > 0) {
    $user = $result->fields; // Direct access to associative array
}

// Multiple records
$users = [];
while ($result->fetch()) {
    $users[] = $result->fields;
}
```

## Console Commands

The framework includes powerful console commands for development:

```bash
# Generate a new controller
php bin/pramnos create:controller UserController --full

# Generate a new model
php bin/pramnos create:model User

# Generate API endpoints
php bin/pramnos create:api UserAPI

# Create database migrations
php bin/pramnos create:migration CreateUsersTable

# Run development server
php bin/pramnos serve --port=8080

# Migrate log files to structured format
php bin/pramnos migrate:logs --days=30
```

## 🏗️ Architecture Overview

### Framework Components

```
src/Pramnos/
├── Application/     # Core MVC components (Controllers, Models, Views)
├── Auth/           # Authentication, JWT, Permissions
├── Cache/          # Multi-backend caching system
├── Console/        # CLI commands and tools
├── Database/       # Database abstraction and migrations
├── Document/       # Output rendering (HTML, JSON, PDF, etc.)
├── Email/          # Email handling and templates
├── Framework/      # Base classes and Factory
├── Http/           # Request/Response handling
├── Logs/           # Logging and log management
├── Media/          # File and image processing
├── Routing/        # URL routing and dispatching
├── Theme/          # Theming and template system
├── Translator/     # Internationalization
└── User/           # User management and tokens
```

### Configuration Structure

```
app/
├── app.php          # Main application configuration
├── config/          # Additional configuration files
│   ├── database.php
│   ├── cache.php
│   └── settings.php
├── language/        # Translation files
├── migrations/      # Database migrations
└── themes/          # Application themes

src/
├── Controllers/     # Application controllers
├── Models/          # Data models
├── Views/           # Template files (.html.php)
└── Api/            # API controllers and routes
```

## 🔧 Configuration

### Database Configuration

```php
// app/config/database.php
return [
    'host' => 'localhost',
    'username' => 'dbuser',
    'password' => 'dbpass',
    'database' => 'myapp',
    'prefix' => 'app_',
    'type' => 'mysql', // or 'postgresql'
    'port' => 3306
];
```

### Cache Configuration

```php
// app/config/cache.php
return [
    'method' => 'redis', // redis, memcached, memcache, file
    'hostname' => 'localhost',
    'port' => 6379,
    'database' => 0,
    'prefix' => 'myapp_'
];
```

### Application Configuration

```php
// app/app.php
return [
    'namespace' => 'MyApp',
    'theme' => 'default',
    'api_version' => 'v1',
    'addons' => [
        ['addon' => 'UserDatabase', 'type' => 'auth'],
        ['addon' => 'Session', 'type' => 'system']
    ],
    'scripts' => [
        [
            'script' => 'jquery',
            'src' => 'assets/js/jquery.min.js',
            'deps' => [],
            'version' => '3.6.0',
            'footer' => false
        ]
    ]
];
```

## 🌟 Advanced Features

### Custom Authentication Addon

```php
<?php
namespace MyApp\Addon\Auth;

class CustomAuth extends \Pramnos\Addon\Addon
{
    public function onAuth($username, $password, $remember, $encrypted, $validate)
    {
        // Custom authentication logic
        return [
            'status' => true,
            'username' => $username,
            'uid' => $userId,
            'email' => $email,
            'auth' => $authHash
        ];
    }
}
```

### API Controller Example

```php
<?php
namespace MyApp\Api\Controllers;

class UsersController extends \Pramnos\Application\Controller
{
    public function display()
    {
        // GET /api/users
        $users = $this->getModel('User')->getList();
        return $this->response(['users' => $users]);
    }
    
    public function postCreateUser()
    {
        // POST /api/users
        $data = $this->getRequestData();
        $user = $this->getModel('User');
        $user->create($data);
        return $this->response(['user' => $user->getData()], 201);
    }
}
```

### Custom Cache Usage

```php
// Using the cache system
$cache = \Pramnos\Cache\Cache::getInstance('user_data', 'user', 'redis');

// Save data
$cache->save($userData, $userId);

// Load data
$userData = $cache->load($userId);

// Clear category
$cache->clear('user');
```

## 📄 Documentation

The framework includes comprehensive documentation for all major subsystems:

### Core System Documentation
- **[Framework Guide](docs/Pramnos_Framework_Guide.md)** - Core framework architecture and concepts
- **[Database API Guide](docs/Pramnos_Database_API_Guide.md)** - Database operations and query building
- **[Authentication Guide](docs/Pramnos_Authentication_Guide.md)** - User authentication and session management
- **[Cache System Guide](docs/Pramnos_Cache_Guide.md)** - Caching strategies and implementation
- **[Console Guide](docs/Pramnos_Console_Guide.md)** - Command-line interface and console commands

### Feature Documentation
- **[Logging Guide](docs/Pramnos_Logging_Guide.md)** - Logging system and debugging tools
- **[Document & Output Guide](docs/Pramnos_Document_Output_Guide.md)** - Document generation and output management
- **[Theme System Guide](docs/Pramnos_Theme_Guide.md)** - Theming, templates, and UI customization
- **[Email System Guide](docs/Pramnos_Email_Guide.md)** - Email sending, templates, and tracking
- **[Media System Guide](docs/Pramnos_Media_Guide.md)** - File uploads, image processing, and media management
- **[Internationalization Guide](docs/Pramnos_Internationalization_Guide.md)** - Multi-language support and localization

## 📄 License

MIT License - see the [LICENSE.txt](LICENSE.txt) file for details.

## 👨‍💻 Author

**Yannis - Pastis Glaros**  
Email: <mrpc@pramnoshosting.gr>  
Company: Pramnos Hosting Ltd.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup

1. Clone the repository
2. Run `composer install`
3. Copy configuration files from examples
4. Set up your database and cache
5. Run tests with `vendor/bin/phpunit`

### Guidelines

- Follow PSR-4 autoloading standards
- Write tests for new features
- Update documentation when adding features
- Use the existing code style and patterns

## 🆘 Support

- **Documentation**: Check the [docs/](docs/) directory
- **Issues**: Submit issues on the project repository
- **Community**: Join our community discussions


