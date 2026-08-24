<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a front-end component, with its test.
 *
 * The small one of the four commands, and the one that decides whether the other
 * three are used. `create:service` writes a service **and a test stub**, and that
 * pairing is why services in a scaffolded project have tests. There was no
 * equivalent on the front end, so a component was a file somebody created by
 * hand in whichever directory they guessed, and its test was a file they did not
 * create at all.
 *
 * What it writes:
 *
 *   frontend/components/<Name>.svelte      — props, a docblock, a root element
 *   frontend/__tests__/<Name>.test.js      — renders it and asserts one thing
 *
 * The test is deliberately trivial and deliberately present: a suite with one
 * real assertion per component is a suite somebody adds to, and an empty
 * `__tests__` directory is one nobody does.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class MakeComponent extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:component');
        $this->setDescription('Create a front-end component and its test');
        $this->addCommonOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);

        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: component');
        }

        if ($this->spaStack() !== 'svelte') {
            $output->writeln('<comment>create:component is for the svelte stack.</comment>');
            $output->writeln('  This project declares: <info>'
                . ($this->spaStack() ?: 'none') . '</info>');

            return 0;
        }

        $output->writeln($this->createSpaComponent($name));

        return 0;
    }
}
