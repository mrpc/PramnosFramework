<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * view:clear — delete the compiled templates (`var/viewcache/*.php`), so each `.tpl.php` is
 * compiled again on its next use.
 *
 * ```bash
 * ./pramnos view:clear
 * ```
 *
 * A template that changed is recompiled by itself ({@see \Pramnos\Application\Template\TemplateCache}).
 * This is for a deploy that wants a clean start, or a cache somebody doubts.
 */
class ViewClear extends Command
{
    /** Name and description. */
    protected function configure(): void
    {
        $this->setName('view:clear')
            ->setDescription('Delete the compiled templates, so each is compiled again on its next use');
    }

    /** Empty the cache, and say where. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cache = new \Pramnos\Application\Template\TemplateCache(\Pramnos\Application\View::getTemplateCacheDir());
        $count = count(glob($cache->getCacheDir() . DIRECTORY_SEPARATOR . '*.php') ?: []);
        $cache->flush();
        $output->writeln(sprintf('%d compiled template(s) deleted from %s.', $count, $cache->getCacheDir()));

        return Command::SUCCESS;
    }
}
