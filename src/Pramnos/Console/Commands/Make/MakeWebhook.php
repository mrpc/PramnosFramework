<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates www/webhook.php — a standalone git webhook receiver.
 *
 * Usage:
 *   php pramnos make:webhook
 *   php pramnos make:webhook --force         # overwrite existing file
 *   php pramnos make:webhook --branch=main   # pre-fill the branch name in the stub
 */
class MakeWebhook extends MakeCommandBase
{
    protected function configure(): void
    {
        $this->setName('make:webhook');
        $this->setDescription('Generate www/webhook.php — a standalone git webhook receiver');
        $this->addOption('force',  null, InputOption::VALUE_NONE,     'Overwrite existing webhook.php');
        $this->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Primary deploy branch (default: main)', 'main');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareExecution($input, $output);

        $root   = defined('ROOT') ? ROOT : (string) getcwd();
        $target = $root . '/www/webhook.php';
        $force  = (bool) $input->getOption('force');
        $branch = (string) ($input->getOption('branch') ?: 'main');

        // Determine CLI name from application namespace (lowercase namespace = cli name convention)
        $cliName = $this->detectCliName($root);

        // Safety check — don't overwrite without --force
        if (file_exists($target) && !$force) {
            $output->writeln('<comment>www/webhook.php already exists. Use --force to overwrite.</comment>');
            return 1;
        }

        // Ensure www/ exists
        if (!is_dir($root . '/www') && !mkdir($root . '/www', 0755, true)) {
            $output->writeln('<error>Cannot create www/ directory.</error>');
            return 1;
        }

        // Generate content
        $content = $this->generateWebhookScript($cliName, $branch);

        if (file_put_contents($target, $content) === false) {
            $output->writeln("<error>Failed to write {$target}</error>");
            return 1;
        }

        $output->writeln("<info>Created www/webhook.php</info>");

        // Append WEBHOOK_SECRET to .env.example if it exists and the key isn't already there
        $envExample = $root . '/.env.example';
        if (file_exists($envExample)) {
            $envContents = (string) file_get_contents($envExample);
            if (!str_contains($envContents, 'WEBHOOK_SECRET')) {
                file_put_contents($envExample, "\n# Git webhook HMAC secret (must match the secret set in GitHub/Bitbucket)\nWEBHOOK_SECRET=\n", FILE_APPEND);
                $output->writeln('<info>Appended WEBHOOK_SECRET= to .env.example</info>');
            }
        }

        $output->writeln('');
        $output->writeln('Next steps:');
        $output->writeln("  1. Set <comment>WEBHOOK_SECRET=&lt;your-secret&gt;</comment> in .env");
        $output->writeln("  2. Configure your GitHub/Bitbucket webhook to POST to:");
        $output->writeln("     <comment>https://yourapp.example.com/webhook.php</comment>");
        $output->writeln("  3. Customise the command sequences in <comment>www/webhook.php</comment>");

        return 0;
    }

    /**
     * Attempts to detect the CLI entry-point name from the project root.
     *
     * Looks for a *.php file in the root directory that matches the CLI entry
     * point convention (lowercase namespace).  Falls back to 'pramnos'.
     */
    private function detectCliName(string $root): string
    {
        // Try to read namespace from app.php
        $appConfig = $root . '/app/app.php';
        if (file_exists($appConfig)) {
            try {
                $cfg = include $appConfig;
                if (isset($cfg['namespace'])) {
                    $candidate = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cfg['namespace']));
                    if ($candidate !== '' && file_exists($root . '/' . $candidate . '.php')) {
                        return $candidate;
                    }
                    return $candidate ?: 'pramnos';
                }
            } catch (\Throwable) {
            }
        }

        // Look for a single *.php entry point in root (exclude composer.php, index.php)
        $candidates = glob($root . '/*.php') ?: [];
        $excluded   = ['index.php', 'composer.php', 'phpunit.php'];
        foreach ($candidates as $file) {
            $name = basename($file, '.php');
            if (!in_array(basename($file), $excluded, true)) {
                return $name;
            }
        }

        return 'pramnos';
    }

    /**
     * Generates the www/webhook.php content.
     */
    private function generateWebhookScript(string $cliName, string $branch): string
    {
        return <<<PHP
<?php

/**
 * Git webhook receiver.
 *
 * Point your GitHub / Bitbucket webhook at:
 *   https://yourapp.example.com/webhook.php
 *
 * Set WEBHOOK_SECRET in .env to match the secret configured in your webhook provider.
 *
 * Generated by: php {$cliName} make:webhook
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

\$dotenv = \\Dotenv\\Dotenv::createImmutable(ROOT);
\$dotenv->safeLoad();

\$handler = new \\Pramnos\\Webhook\\WebhookHandler(
    secret:     \$_ENV['WEBHOOK_SECRET'] ?? '',
    repoDir:    ROOT,
    logChannel: 'webhook',
);

\$handler->onBranch('{$branch}', [
    'git fetch --all',
    'git reset --hard origin/{$branch}',
    'composer install --no-dev --optimize-autoloader',
    'php {$cliName} migrate',
]);

// Add more branches as needed:
// \$handler->onBranch('develop', [
//     'git fetch --all',
//     'git reset --hard origin/develop',
// ]);

\$handler->handle();
PHP;
    }
}
