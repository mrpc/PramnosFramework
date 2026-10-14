---
use_cases:
  - Installing the framework for the first time
  - Starting a new project and choosing between create-project and composer require
  - Finding out what a scaffolded project directory contains
---

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
- **Docker**: Optional but highly recommended containerized setup.

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
	 - `config/settings.php`: Main environment configuration — reads `.env`, holds no credentials.
	 - `config/testsettings.php`: The same, pointed at `APP_DB_TEST_NAME`.
- **`.env`**: this machine's values — credentials, database name, `APP_DEBUG`. Not committed.
- **`.env.example`**: the committed list of every key, secrets blank. Copy it to `.env`.
- **`src/`**: Your application logic (Controllers, Models, Views).
- **`www/`**: Public entry point (contains `index.php` and assets).
- **`var/`**: Logs, cache and migration state — machine-local, ignored by git.
- **`tests/`**: Unit and Integration tests.

The [Full Setup Guide](Getting_Started.md) explains the configuration, the `daemons`
container and what `init` puts in `.gitignore`.

To run your tests, use the following command:

```bash
# Using Docker (Recommended)
./dockertest

# With coverage
./dockertest --coverage
```

The `./dockertest` command runs PHPUnit inside the Docker container (PHP 8.5), ensuring all dependencies and environmental requirements are met. It automatically handles container startup and composer installation if needed.

## Creating Entities

Once your project is initialized, use the `create:*` commands to scaffold new components.
The project's CLI is `<cli>.php` at the project root (`myapp.php` for the namespace `MyApp`);
with Docker, `./<cli>` runs it inside the container:

```bash
# Create a new controller
./<cli> create:controller MyNewController

# Create a new model
./<cli> create:model MyModel
```
