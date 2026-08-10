<?php
namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a complete CRUD for an entity.
 *
 * What "complete" means depends on how the application is built, which is why
 * this command reads `app_style` from app.php instead of always producing the
 * same files:
 *
 *  - **mvc**    — model + controller + server-rendered views (unchanged).
 *  - **spa**    — model + API controller + routes + a front-end screen.
 *  - **hybrid** — both, over a single model: one domain object, two controllers.
 *
 * `--target` overrides the choice for one run.
 */
class MakeCrud extends MakeCommandBase
{
    protected function configure()
    {
        $this->setName('create:crud');
        $this->setDescription('Create a complete CRUD (model, controller, views and/or API + SPA screen)');
        $this->addCommonOptions();
        $this->addOption(
            'target',
            null,
            InputOption::VALUE_OPTIONAL,
            'What to generate: mvc, spa or both (default: from app.php app_style)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareExecution($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            throw new \InvalidArgumentException('Name is required for: crud');
        }

        $target = $input->getOption('target') ?: $this->defaultCrudTarget();
        if (!in_array($target, ['mvc', 'spa', 'both'], true)) {
            throw new \InvalidArgumentException(
                "Unknown --target '$target'. Use mvc, spa or both."
            );
        }

        // Set on the command, not passed in: createCrud() is a public method
        // apps may override, so its signature stays as it was.
        $this->crudTarget = $target;
        $output->writeln($this->createCrud($name));

        return 0;
    }
}
