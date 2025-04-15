Pramnos Framework
===================

Pramnos Framework is a custom framework built on top of Symfony, enhanced with frequently used code and components that are common across my projects. It combines the power and flexibility of Symfony with my custom solutions to streamline development and avoid repetitive coding tasks.

## Requirements

- PHP 7.2 or higher
- ext-mbstring extension

## Installation

```bash
composer require mrpc/pramnosframework
```

## Features

- Built on top of Symfony components
- Custom routing system
- Authentication and authorization
- Database abstraction layer
- Email handling with Symfony Mailer
- Console commands
- File system operations
- HTML generation utilities
- HTTP request/response handling
- Caching mechanisms
- Geolocation services
- Media handling
- Translation services
- Theming system

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

## Basic Usage

```php
<?php
// Include autoloader
require __DIR__.'/vendor/autoload.php';
// Set the path to your root app directory
define ('ROOT', __DIR__);

// Define start point (optional but recommended)
define('SP', microtime(true));

// Create an application instance
$app = new Pramnos\Application\App();

$app->init();
$app->exec();

echo $app->render();
```

## Console Commands

```bash
# Run console commands
php bin/pramnos command:name
```

## License

MIT

## Author

Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.



