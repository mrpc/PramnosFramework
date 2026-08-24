<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create one front-end screen.
 *
 * ## The gap this fills
 *
 * The MVC side has `create:view` — a view without a model, a controller or a
 * migration, for the page that is not a CRUD over a table. The SPA side had no
 * equivalent: `createSpaScreen()` existed but was reachable **only** through
 * `create:crud`, so the way to add a dashboard, a report or a screen over an
 * endpoint somebody else wrote was to copy a generated file and delete two
 * thirds of it.
 *
 * That asymmetry is the SPA half of the same complaint as the field types: the
 * capability was written for one caller and never given a door.
 *
 * ## Two modes
 *
 *   create:screen Dashboard                      — a blank screen, registered
 *   create:screen Invoices --table=invoices      — a CRUD screen over a table
 *   create:screen Invoices --resource=invoice    — a CRUD screen over an endpoint
 *
 * With `--table` it introspects and produces exactly what `create:crud` would
 * produce for its front-end half, and nothing else: no model, no API controller,
 * no routes. That is the case where the API already exists — a hand-written
 * controller, or another application's — and only the screen is missing.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class MakeScreen extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:screen');
        $this->setDescription('Create a front-end screen (blank, or a CRUD over a table or endpoint)');
        $this->addCommonOptions();
        $this->addOption(
            'resource',
            null,
            InputOption::VALUE_OPTIONAL,
            'API resource the screen lists, when it differs from the screen name'
        );
        $this->addOption(
            'blank',
            null,
            InputOption::VALUE_NONE,
            'A screen with no list — a dashboard, a report'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);

        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: screen');
        }

        if ($this->spaStack() === '') {
            // Not a failure — an application in `mvc` style has no screens, and
            // saying so is more useful than an error about a missing directory.
            $output->writeln('<comment>This project declares no SPA stack in app.php.</comment>');
            $output->writeln('  Add one with <info>scaffold:spa</info>, then run this again.');

            return 0;
        }

        // `--resource` renames only what the screen talks to, not what it is
        // called: a screen named `Invoices` over `/api/1.0/invoice` is the normal
        // case, because the resource is singular and the screen is not.
        if ($resource = $input->getOption('resource')) {
            $this->dbtable = $this->dbtable ?: $resource;
        }

        $output->writeln(
            $input->getOption('blank')
                ? $this->createBlankSpaScreen($name)
                : $this->createSpaScreen($name)
        );

        return 0;
    }
}
