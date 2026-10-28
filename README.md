# Pramnos Framework v1.2

[![PHP Tests](https://github.com/mrpc/PramnosFramework/actions/workflows/php-tests.yml/badge.svg)](https://github.com/mrpc/PramnosFramework/actions/workflows/php-tests.yml) [![CodeQL](https://github.com/mrpc/PramnosFramework/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/mrpc/PramnosFramework/security/code-scanning) [![Psalm](https://github.com/mrpc/PramnosFramework/actions/workflows/psalm.yml/badge.svg)](https://github.com/mrpc/PramnosFramework/actions/workflows/psalm.yml) [![Coverage](https://codecov.io/gh/mrpc/PramnosFramework/graph/badge.svg)](https://codecov.io/gh/mrpc/PramnosFramework) [![Documentation](https://img.shields.io/badge/docs-live-2496ED?logo=readthedocs&logoColor=white)](https://mrpc.github.io/PramnosFramework/) [![Latest Release](https://img.shields.io/github/v/release/mrpc/PramnosFramework?color=success)](https://github.com/mrpc/PramnosFramework/releases/latest) [![Packagist](https://img.shields.io/packagist/v/mrpc/pramnosframework?color=success)](https://packagist.org/packages/mrpc/pramnosframework) [![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-777bb4?logo=php&logoColor=white)](https://www.php.net/) [![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE.txt)

Pramnos Framework is a comprehensive PHP MVC framework designed for building robust, scalable web applications. The **v1.2 release** brings major infrastructure improvements, a complete ORM layer, advanced API support, and enhanced developer experience.

📖 **Full documentation:** **[mrpc.github.io/PramnosFramework](https://mrpc.github.io/PramnosFramework/)**

> **Released:** July 2026  
> **Minimum PHP Version:** 8.1  
> **Database Support:** MySQL 8.0+, PostgreSQL 17+, TimescaleDB 2.26.4+
>
> The suite runs against MySQL 8.0, PostgreSQL 17 and TimescaleDB 2.26.4 — the
> TimescaleDB floor being the newest package installable on Debian 11, which is
> where a real deployment found three migrations failing that every `latest` run had
> passed. PostgreSQL 14 worked in every test up to the day the lane moved to 17 and
> is very likely still fine; it is simply no longer verified here, and an unverified
> claim is not a supported version.

## 🚀 What's New in v1.2?

### Database & Query Layer
- **Read/Write Replicas** — Automatic routing of queries to primary and replica connections
- **Connection Health & Auto-reconnect** — Transparent reconnection on dropped connections
- **DML Query Builder** — Fluent interface for SELECT, INSERT, UPDATE, DELETE with dialect support
- **DDL Schema Builder** — Programmatic schema definition with support for migrations
- **DatabaseCapabilities** — Runtime detection of database features (TimescaleDB, JSON, spatial, etc.)
- **timeBucket() & Time Series Support** — Dialect-transparent time bucketing for TimescaleDB/PostgreSQL

### Infrastructure & Architecture
- **Complete ORM** — Models with relationships, scopes, and casting
- **Migration System v2** — Framework-managed migrations with auto-run, versioning, and rollback
- **Service Providers** — Application bootstrap and dependency injection
- **Feature Registry** — Declarative feature management and wiring
- **Policy Engine** — Authorization framework with condition-based access control
- **Middleware Pipeline** — Request/response filtering with composable middleware
- **Health Check System** — Built-in application health monitoring and diagnostics

### API & Web Features
- **PSR Compliance Layer** — PSR-7/PSR-15 compatibility for interoperability
- **Modern Routing** — Attribute-based routing with parameter binding and groups
- **REST API Framework** — Scaffolding and conventions for API-first development
- **Database-Driven CORS** — Configuration stored in database with runtime validation
- **Response Object** — Formal HTTP response abstraction
- **HTTP Testing Infrastructure** — Built-in testing helpers for API/web testing

### Security Enhancements
- **CSRF Hardening** — Strengthened token validation with session binding
- **Session Cookie Hardening** — Secure cookie attributes and regeneration
- **View Escaping Helpers** — Safe output escaping with context-aware strategies
- **OAuth2 Server** — Full OAuth2 authorization server implementation
- **2FA/TOTP** — Two-factor authentication and time-based one-time passwords
- **Login Lockout** — Brute-force protection with exponential backoff

### Developer Experience
- **Scaffolding System** — Generate controllers, views, models, tests with smart defaults
- **Rich CLI** — Comprehensive command-line tools with progress reporting
- **MCP Server** — AI-powered developer assistance (Claude/ChatGPT integration)
- **Enhanced DebugBar** — Migrations, request timeline, cache tracking, and profiling
- **Form Requests** — Validation-focused request objects with automatic routing
- **Notification Channels** — Email, database, and webhook notifications
- **Admin CRUD Generators** — Automatic admin interfaces for database tables

### Database Features
- **Window Functions** — RANK(), ROW_NUMBER(), LAG/LEAD support across MySQL 8+ and PostgreSQL
- **CTEs & Subqueries** — Common Table Expressions with `with()` and subquery builders
- **Set Operations** — UNION/UNION ALL with type coercion
- **Upsert Operations** — INSERT...ON DUPLICATE KEY / INSERT...ON CONFLICT
- **Aggregate Functions** — COUNT, SUM, AVG, MIN, MAX with proper cloning

---

## 📚 Documentation

The full documentation is available as a live site at **[mrpc.github.io/PramnosFramework](https://mrpc.github.io/PramnosFramework/)**.

### Running the docs locally (Docker required)

```bash
# Start the live-reload development server at http://localhost:8000
./dockerdocs serve

# Build the static HTML site into ./site/
./dockerdocs build

# Deploy to GitHub Pages manually
./dockerdocs deploy
```

No local Python or pip installation is required — everything runs inside Docker.

### Getting Started
- **[Installation & Setup](docs/Getting_Started.md)** - Quick start guide for new projects
- **[v1.2 New Features Reference](docs/1.2-new-features.md)** - Comprehensive feature documentation (implementation reference)
- **[Upgrade Guide](docs/Pramnos_Upgrade_Guide.md)** - What changed between versions, and what to do about it
- **[Contributing Guide](docs/Pramnos_Contributing_Guide.md)** - Working on the framework's own source: setup, tests, conventions

### Core Guides by Topic

The full, current list — kept in sync with [`mkdocs.yml`](mkdocs.yml)'s navigation, which a
test (`DocsRetrievabilityTest`) enforces every guide is actually listed in — lives on the
[documentation site](https://mrpc.github.io/PramnosFramework/). The groups below mirror it:

#### Core Framework
- **[Framework Guide](docs/Pramnos_Framework_Guide.md)** - Controllers, models, views (the MVC primer)
- **[Application Styles Guide](docs/Pramnos_Application_Styles_Guide.md)** - Choosing MVC vs SPA vs hybrid
- **[Routing Guide](docs/Pramnos_Routing_Guide.md)** - Attribute-based route registration and middleware
- **[Validation Guide](docs/Pramnos_Validation_System_Guide.md)** - Form validation and custom rules
- **[Theme Guide](docs/Pramnos_Theme_Guide.md)** - Templates, widgets, menus, theme assets
- **[Internationalization Guide](docs/Pramnos_Internationalization_Guide.md)** - Multi-language support
- **[Document Output Guide](docs/Pramnos_Document_Output_Guide.md)** - Multi-format output (HTML, PDF, JSON, etc.)
- **[Environment Variables Guide](docs/Pramnos_Environment_Variables_Guide.md)** - Configuration management
- **[API Guide](docs/Pramnos_API_Guide.md)** - Building this application's own JSON REST API

#### UI Widgets & Tools
- **[HTML Components Guide](docs/Pramnos_Html_Components_Guide.md)** - Reusable UI widgets: tiles, charts, inputs
- **[Rich Text Editor Guide](docs/Pramnos_Rich_Editor_Guide.md)** - Rich-text editor widget for forms
- **[Dashboard Guide](docs/Pramnos_Dashboard_Guide.md)** - Admin dashboard cards
- **[Calendar Guide](docs/Pramnos_Calendar_Guide.md)** - Month/week/day event calendar
- **[Image Upload Guide](docs/Pramnos_Image_Upload_Guide.md)** - Client-side crop/resize before upload

#### Data Layer
- **[Database API Guide](docs/Pramnos_Database_API_Guide.md)** - Core DB access, raw SQL and its pitfalls
- **[Query Builder Guide](docs/Pramnos_QueryBuilder_Guide.md)** - Fluent SELECT/INSERT/UPDATE/DELETE
- **[Schema Builder Guide](docs/Pramnos_Schema_Builder_Guide.md)** - Programmatic DDL (columns, indexes, FKs)
- **[ORM Guide](docs/Pramnos_ORM_Guide.md)** - Models, relationships, scopes, casts, soft deletes
- **[Migration Guide](docs/Pramnos_Migration_Guide.md)** - Writing and running DB migrations
- **[Hypertable Guide](docs/Pramnos_Hypertable_Guide.md)** - TimescaleDB hypertables for time-series data
- **[Cross-Entity Search Guide](docs/Pramnos_Search_Guide.md)** - One term, several models, aggregated results

#### Infrastructure Services
- **[Cache Guide](docs/Pramnos_Cache_Guide.md)** - Query, data, and application-level caching
- **[Page Cache Guide](docs/Pramnos_Page_Cache_Guide.md)** - Full-page caching for anonymous traffic
- **[Service Worker Guide](docs/Pramnos_Service_Worker_Guide.md)** - Browser service-worker asset caching
- **[Redis Guide](docs/Pramnos_Redis_Guide.md)** - The shared Redis connection manager
- **[Queue Guide](docs/Pramnos_Queue_Guide.md)** - Background job dispatch, drivers, workers
- **[Realtime Guide](docs/Pramnos_Realtime_Guide.md)** - SSE/WebSocket push to browsers
- **[Change Feed Guide](docs/Pramnos_Change_Feed_Guide.md)** - Broadcasting model changes, audit logging

#### Security & Identity
- **[Authentication Guide](docs/Pramnos_Authentication_Guide.md)** - Sign-in, roles, JWT/bearer tokens
- **[Auth Server Setup Guide](docs/Pramnos_AuthServer_Setup_Guide.md)** - Standing up an OAuth2/OIDC auth server
- **[Authorization Guide](docs/Pramnos_Authorization_Guide.md)** - Permission checks for controllers/routes
- **[Security Guide](docs/Pramnos_Security_Guide.md)** - CSRF, XSS, injection prevention, hardening
- **[Cookie Consent Guide](docs/Pramnos_Cookie_Consent_Guide.md)** - EU cookie banner, Consent Mode v2
- **[Account Guide](docs/Pramnos_Account_Guide.md)** - End-user account/security screens: 2FA, passkeys
- **[AuthServer Integration Guide](docs/Pramnos_AuthServer_Integration_Guide.md)** - Letting another app sign in through this one

#### External Integrations
- **[HTTP Client Guide](docs/Pramnos_Http_Client_Guide.md)** - Outbound calls to third-party REST APIs
- **[LLM Guide](docs/Pramnos_LLM_Guide.md)** - Calling LLM providers from the app
- **[OAuth2 Client Guide](docs/Pramnos_OAuth_Client_Guide.md)** - Signing in with Google/Meta/TikTok, etc.

#### Platform Engines
- **[Admin Assistant Guide](docs/Pramnos_Admin_Assistant_Guide.md)** - AI-assisted help panel for admin screens
- **[Notifications Guide](docs/Pramnos_Notifications_Guide.md)** - Notification bell UI
- **[Email Guide](docs/Pramnos_Email_Guide.md)** - Email delivery, configuration, tracking
- **[Audience Guide](docs/Pramnos_Audience_Guide.md)** - Filterable audiences of people by attribute
- **[Conversations Guide](docs/Pramnos_Conversations_Guide.md)** - Team chat and support messaging
- **[Moderation Guide](docs/Pramnos_Moderation_Guide.md)** - Bans, mutes, word filters, reports
- **[Web Push Guide](docs/Pramnos_Push_Guide.md)** - Web push notifications
- **[Media Guide](docs/Pramnos_Media_Guide.md)** - File/image upload handling, thumbnails, storage

#### Operations
- **[Console Guide](docs/Pramnos_Console_Guide.md)** - Built-in CLI commands and code generation
- **[Health Guide](docs/Pramnos_Health_Guide.md)** - Health-check endpoints for uptime monitors
- **[MCP Guide](docs/Pramnos_MCP_Guide.md)** - Exposing app schema/tools to an AI assistant
- **[Workers & Daemons Guide](docs/Pramnos_Workers_And_Daemons_Guide.md)** - Long-running workers, scheduling
- **[Multi-Server Guide](docs/Pramnos_Multi_Server_Guide.md)** - Load-balanced, multi-node deployment
- **[Deploy Webhook Guide](docs/Pramnos_Deploy_Webhook_Guide.md)** - Auto-deploy on push via webhook
- **[Moving from MySQL Guide](docs/Pramnos_Moving_From_MySQL_Guide.md)** - A one-time cutover to PostgreSQL/TimescaleDB
- **[Logging Guide](docs/Pramnos_Logging_Guide.md)** - PSR-3 logging, rotation, viewer
- **[Testing Guide](docs/Pramnos_Testing_Guide.md)** - Writing tests, factories, seeders
- **[Debugging Guide](docs/Pramnos_Debugging_Guide.md)** - Debugging queries, timings, exceptions
- **[DevPanel Guide](docs/Pramnos_DevPanel_Guide.md)** - Live install diagnostics panel
- **[Debug Toolbar Usage](docs/Pramnos_Debug_Toolbar_Usage.md)** - Using the in-browser debug toolbar

## Requirements

- Docker and Docker Compose (Recommended for development)
- PHP 8.1 or higher (if running natively). Development and CI run PHP 8.5. **Planned:** the
  next release after the current one raises this floor to PHP 8.4 — see the
  [Upgrade Guide](docs/Pramnos_Upgrade_Guide.md#planned-not-yet-in-effect).
- ext-mbstring
- ext-mysqli (MySQL) and/or ext-pgsql (PostgreSQL / TimescaleDB)
- ext-sodium, for encrypting stored credentials (bundled with PHP 8 and enabled by
  default)
- Optional: Redis/Memcached for caching

The database layer talks to `mysqli` and `pgsql` **directly — not through PDO**.
`ext-pdo` is neither used nor required, and `prepareInput()` now refuses to escape a
value rather than fall back to something charset-unaware, so a missing driver
extension fails loudly instead of half-working.

## Installation

The recommended way to start a new project is using the **Pramnos Application**, which provides a one-line setup experience:

```bash
composer create-project mrpc/pramnos-application my-app
```

Alternatively, you can manually add the framework as a dependency to an existing project:

```bash
mkdir my-app && cd my-app
composer require mrpc/pramnosframework
php vendor/bin/pramnos init
```

The `init` command will guide you through the setup with smart defaults (e.g., automatic namespace generation and database naming).

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
- **Nonce-based CSP** - Automatic Content Security Policy with per-request nonces

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

The framework includes powerful console commands for development. If using Docker, run these via `docker-compose exec php-apache-environment ...`.

```bash
# Generate a full CRUD controller from an existing table
php bin/pramnos create:controller UserController

# Generate a new model
php bin/pramnos create:model User

# Generate API endpoints
php bin/pramnos create:api UserAPI

# Create database migrations
php bin/pramnos create:migration CreateUsersTable

# Run development server
php bin/pramnos serve --port=8080

# Migrate log files to structured format
php bin/pramnos migratelogs var/logs --all
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
    ],
    /**
     * Content Security Policy (CSP)
     */
    'csp' => [
        'script-src' => ['https://maps.googleapis.com'],
        'style-src' => ['https://fonts.googleapis.com'],
        'font-src' => ['https://fonts.gstatic.com'],
        'img-src' => ['https://maps.gstatic.com'],
        'connect-src' => ['https://maps.googleapis.com']
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

> 🌐 All of the guides below are also published as a searchable site at **[mrpc.github.io/PramnosFramework](https://mrpc.github.io/PramnosFramework/)**.

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

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup

The framework includes a fully containerized development and testing environment.

1. Clone the repository:
   ```bash
   git clone https://github.com/mrpc/PramnosFramework.git
   cd PramnosFramework
   ```

2. Start the Docker environment:
   ```bash
   docker-compose up -d
   ```
   This will start PHP 8.5 (for testing), MySQL, PostgreSQL/TimescaleDB, and Redis.

3. Install dependencies and initialize:
   ```bash
   docker-compose exec php-apache-environment composer install
   ```

4. Run tests:
   ```bash
   # Using the provided wrapper script
   ./dockertest
   
   # With coverage report
   ./dockertest --coverage
   ```

### Guidelines

- Core framework code (`src/Pramnos/`) targets **PHP 8.1** (`composer.json`'s floor)
  and is developed and tested on **PHP 8.5**. That floor is rising to **PHP 8.4** in the next
  release — see the [Upgrade Guide](docs/Pramnos_Upgrade_Guide.md#planned-not-yet-in-effect).
- Tests (`tests/`) run on the same **PHP 8.5** and use modern PHP features freely.
- Follow PSR-4 autoloading standards.
- Write tests for new features using PHPUnit 11 with native attributes.
- Update documentation when adding features.

If you are developing the framework and want to test the full "new project" experience locally, you can use this one-liner (adjust `APP_NAME` and the path to `PramnosFramework` as needed):

```bash
APP_NAME=test-app; mkdir $APP_NAME && cd $APP_NAME && composer init -n --name="app/$APP_NAME" && composer config version dev-main && composer config minimum-stability dev && composer config repositories.pramnos path ../PramnosFramework && composer require mrpc/pramnosframework:dev-main && php vendor/bin/pramnos init
```

## 🆘 Support

- **Documentation**: Check the [docs/](docs/) directory
- **Issues**: Submit issues on the project repository
- **Community**: Join our community discussions


