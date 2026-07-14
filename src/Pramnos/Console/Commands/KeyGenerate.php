<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates (or rotates) the application key and writes it to the project .env.
 *
 * The key is a cryptographically strong random value — base64 of 32 random
 * bytes (256 bits of entropy), mirroring the random-byte approach used by the
 * `init` command (`bin2hex(random_bytes(...))`) but with more entropy and the
 * `base64:` prefix convention so consumers can distinguish encoded keys.
 *
 * ## Usage
 *
 * ```
 * php pramnos key:generate            Write a fresh APP_KEY into .env (refuses if one exists)
 * php pramnos key:generate --force    Overwrite an existing APP_KEY
 * php pramnos key:generate --show     Print a new key without touching any file
 * ```
 *
 * Safety: rotating an existing key can invalidate any data encrypted with the
 * previous key as well as existing signed sessions/cookies. The command refuses
 * to clobber an existing non-empty APP_KEY unless `--force` is given, and always
 * prints a warning when it does rotate.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class KeyGenerate extends Command
{
    /**
     * Base directory whose `.env` is written. Defaults to ROOT (or the current
     * working directory) at runtime, but is public so tests can point it at a
     * temporary directory — mirroring ScaffoldViews / LibrariesSync.
     */
    public string $targetBaseDir = '';

    protected static $defaultName = 'key:generate';

    protected function configure(): void
    {
        $this
            ->setName('key:generate')
            ->setDescription('Generate the application key and set APP_KEY in .env')
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Print a freshly generated key without writing it anywhere'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite an existing APP_KEY (rotation)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = $this->targetBaseDir !== ''
            ? $this->targetBaseDir
            : (defined('ROOT') ? ROOT : (string) getcwd());

        $key = $this->generateKey();

        // --show: never touches the filesystem.
        if ($input->getOption('show')) {
            $output->writeln($key);
            return Command::SUCCESS;
        }

        $envPath     = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . '.env';
        $examplePath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . '.env.example';

        // Load current .env contents, seeding from .env.example when absent.
        if (is_file($envPath)) {
            $contents = (string) file_get_contents($envPath);
        } elseif (is_file($examplePath)) {
            $contents = (string) file_get_contents($examplePath);
            $output->writeln('<comment>.env not found — created from .env.example</comment>');
        } else {
            $contents = '';
        }

        $existing = $this->currentKey($contents);

        if ($existing !== null && $existing !== '' && !$input->getOption('force')) {
            $output->writeln('<error>APP_KEY is already set. Refusing to overwrite it.</error>');
            $output->writeln('<comment>Re-run with --force to rotate the key.</comment>');
            return Command::FAILURE;
        }

        // Rotation of an existing key is destructive to encrypted data/sessions.
        if ($existing !== null && $existing !== '') {
            $output->writeln(
                '<comment>Warning: rotating APP_KEY invalidates data encrypted with the '
                . 'previous key and any existing signed sessions/cookies.</comment>'
            );
        }

        $newContents = $this->setKeyLine($contents, $key);

        if (@file_put_contents($envPath, $newContents) === false) {
            $output->writeln('<error>Failed to write ' . $envPath . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Application key set successfully.</info>');
        $output->writeln('APP_KEY=' . $key);

        return Command::SUCCESS;
    }

    /**
     * Generate a strong random application key: base64 of 32 random bytes,
     * prefixed with `base64:` so it is recognisable as an encoded key.
     */
    private function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Extract the current APP_KEY value from raw .env contents, or null if the
     * key line is absent. An empty value returns '' (present but unset).
     */
    private function currentKey(string $contents): ?string
    {
        if (preg_match('/^APP_KEY=(.*)$/m', $contents, $m)) {
            return trim($m[1], " \t\"'");
        }
        return null;
    }

    /**
     * Return $contents with the APP_KEY line set to $key: the existing line is
     * replaced in place, or a new line is appended when none exists.
     */
    private function setKeyLine(string $contents, string $key): string
    {
        $line = 'APP_KEY=' . $key;

        if (preg_match('/^APP_KEY=.*$/m', $contents)) {
            return (string) preg_replace('/^APP_KEY=.*$/m', $line, $contents);
        }

        if ($contents === '') {
            return $line . "\n";
        }

        // Append, ensuring exactly one trailing newline before the new line.
        return rtrim($contents, "\r\n") . "\n" . $line . "\n";
    }
}
