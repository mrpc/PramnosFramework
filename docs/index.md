---
use_cases:
  - Installing the framework for the first time
  - Starting a new project and choosing between create-project and composer require
  - Finding out what a scaffolded project directory contains
---

# Quickstart

The fastest way to a running Pramnos project:

```bash
composer create-project mrpc/pramnos-application my-app
```

This handles the directory structure and the framework installation in one step. Everything
else — Docker, configuration, what a scaffolded project looks like, and generating your first
model and controller — is the [Full Setup Guide](Getting_Started.md).

## Installing into an existing project

To add the framework to a project you already have, rather than starting a new one:

1. Install it:
	```bash
	composer require mrpc/pramnosframework
	```
2. Run the initialization command:
	```bash
	php vendor/bin/pramnos init
	```

`init` asks for an application name, a namespace, a database name and user — each with a
sensible default — and whether to set up Docker. The [Full Setup Guide](Getting_Started.md)
picks up from here: what `init` writes, how `.env` and the committed `settings.php` divide the
configuration, and the project structure it scaffolds.
