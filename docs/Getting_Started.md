# Getting Started with Pramnos Framework

Welcome to the Pramnos Framework! This guide will help you set up a new project from scratch using our command-line tools.

## Installation

The recommended way to start a new project is to use the **Pramnos Application**. This provides a one-line setup experience that automatically handles the directory structure and framework installation.

1. From your projects root directory, run:
   ```bash
   composer create-project mrpc/pramnos-application my-app
   ```

Alternatively, if you prefer to manage the process manually or add the framework to an existing project:

1. Create your project directory:
   ```bash
   mkdir my-app && cd my-app
   ```
2. Install the framework:
   ```bash
   composer require mrpc/pramnosframework
   ```
3. Run the initialization command:
   ```bash
   php vendor/bin/pramnos init
   ```

The `init` command will guide you through the setup with smart defaults:
- **Application Name**: Defaults to the folder name (`my-app`).
- **Namespace**: Automatically converted to CamelCase (`MyApp`).
- **Database**: Suggested name (`my_app_db`) and user (`my_app_user`).
- **Testing**: Pre-configured setup (PHPUnit) is automatically generated.

## Using Docker

If you enabled Docker during initialization, first start your environment:

```bash
docker-compose up -d
```

*Note: Ensure Docker and Docker Compose are installed on your host machine.*

### Helper Scripts

- **`./dockerbash`**: Enter the application container's shell.
- **`./dockertest`**: Run your PHPUnit tests inside the Docker environment.

## Project Structure

A typical Pramnos project following initialization looks like this:

- **`app/`**: Configuration and Migrations.
    - `config/settings.php`: Main environment configuration.
    - `config/testsettings.php`: Database settings for unit tests.
- **`src/`**: Your application logic (Controllers, Models, Views).
- **`www/`**: Public entry point (contains `index.php` and assets).
- **`var/`**: Logs and cache files.
- **`tests/`**: Unit and Integration tests.

## Running Tests

To run your tests, use the following command:

```bash
# Locally (requires PHP 8.2+)
vendor/bin/phpunit

# Using Docker (Recommended)
./dockertest
```

The `./dockertest` command runs PHPUnit inside the Docker container (PHP 8.4), ensuring all dependencies and environmental requirements are met.

## Creating Entities

Once your project is initialized, you can use the `create` command to scaffold new components:

```bash
# Create a new controller
php bin/pramnos create controller MyNewController

# Create a new model
php bin/pramnos create model MyModel
```
