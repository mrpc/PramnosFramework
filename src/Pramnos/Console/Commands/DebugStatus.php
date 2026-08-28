<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print the current debug configuration: APP_DEBUG flag, Xdebug status,
 * and port 9003 hint.
 *
 * Useful for diagnosing "why isn't the toolbar showing" or "why isn't Xdebug
 * connecting" without digging through php.ini manually.
 */
class DebugStatus extends Command
{
    protected function configure(): void
    {
        $this->setName('debug:status')
            ->setDescription('Show debug configuration (APP_DEBUG, Xdebug, port 9003)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envDebug  = getenv('APP_DEBUG');
        $appDebug  = \Pramnos\Application\Settings::getSetting('debug');

        // The same three signals `DebugBarServiceProvider::isDebugEnabled()` reads, and no
        // others. This command used to count the `debug` *setting* as one of them, which was
        // both wrong about the toolbar and wrong in the more expensive direction: an operator
        // reading "Toolbar active: ON" concluded it was on for them when the setting no
        // longer opens it, or — before the setting stopped opening it — did not realise it
        // was on for every visitor of the site.
        // The toolbar's own gate, asked rather than re-implemented: a third copy of this
        // decision is a third chance for it to disagree with the other two.
        $debugOn = \Pramnos\Debug\DebugBarServiceProvider::toolbarAllowed();

        $xdebugLoaded  = extension_loaded('xdebug');
        $xdebugVersion = $xdebugLoaded ? phpversion('xdebug') : null;
        $xdebugMode    = ini_get('xdebug.mode') ?: 'off';
        $xdebugPort    = ini_get('xdebug.client_port') ?: '9003';

        $output->writeln('');
        $output->writeln('<comment>Debug Configuration</comment>');
        $output->writeln(str_repeat('─', 40));

        $debugLabel = $debugOn ? '<info>ON</info>' : '<comment>OFF</comment>';
        $output->writeln("  APP_DEBUG (env):  " . ($envDebug ?: '(not set)'));
        $output->writeln("  DEVELOPMENT:      "
            . (defined('DEVELOPMENT') && DEVELOPMENT === true ? 'true' : '(not set)'));
        $output->writeln("  Signed token:     "
            . (\Pramnos\Debug\DebugAccess::isGranted() ? 'redeemed' : '(none here)'));
        $output->writeln("  Toolbar active:   {$debugLabel}");
        $output->writeln('');
        $output->writeln("  debug (settings): "
            . ($appDebug !== false ? var_export($appDebug, true) : '(not set)')
            . ' — error display and the DevPanel; it does <comment>not</comment> open the toolbar');
        $output->writeln('  On a live server, open it for one browser: <info>debug:token</info>');

        $output->writeln('');
        $output->writeln('<comment>Xdebug</comment>');
        $output->writeln(str_repeat('─', 40));

        if ($xdebugLoaded) {
            $output->writeln("  Loaded:   <info>yes</info> (v{$xdebugVersion})");
            $output->writeln("  Mode:     {$xdebugMode}");
            $output->writeln("  Port:     {$xdebugPort}");
        } else {
            $output->writeln("  Loaded:   <comment>no</comment>");
            $output->writeln("  Install:  pecl install xdebug");
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
