<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate the RSA key pair the authorization server signs its tokens with: `app/keys/private.key`
 * and `public.key`.
 *
 * The server makes one on its first request when there is none, so this is for doing it on
 * purpose: at deploy time, before the web server's user would have to write into `app/keys`, or to
 * replace a pair. **Replacing it invalidates every token signed with the old key** — access tokens
 * and ID tokens stop verifying, and a client holding the old public key from the JWKS refuses the
 * new ones until it fetches again. So an existing pair is replaced only with `--force`.
 */
class OauthKeys extends Command
{
    /** Where the pair lives, under the project root. */
    public const DIRECTORY = 'app/keys';

    protected function configure(): void
    {
        $this->setName('oauth:keys')
            ->setDescription('Generate the RSA key pair OAuth2 / OpenID Connect tokens are signed with')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing pair. Every token signed with it stops verifying.');
    }

    /** The project root: a method, so a test points it at a temporary directory. */
    protected function root(): string
    {
        return defined('ROOT') ? (string) ROOT : (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->root() . '/' . self::DIRECTORY;
        $private   = $directory . '/private.key';
        $public    = $directory . '/public.key';

        if ((is_file($private) || is_file($public)) && !$input->getOption('force')) {
            $output->writeln('<error>This installation already has a signing key pair in ' . self::DIRECTORY . '/.</error>');
            $output->writeln('');
            $output->writeln('Replacing it invalidates every token signed with it: access tokens and');
            $output->writeln('ID tokens stop verifying, and clients that cached the old public key');
            $output->writeln('refuse the new tokens until they fetch the JWKS again.');
            $output->writeln('');
            $output->writeln('Pass <info>--force</info> if that is what you mean to do.');

            return Command::FAILURE;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            $output->writeln('<error>Could not create ' . self::DIRECTORY . '.</error>');

            return Command::FAILURE;
        }

        $key = openssl_pkey_new(['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || !openssl_pkey_export($key, $pem)) {
            $output->writeln('<error>RSA key generation failed: ' . (string) openssl_error_string() . '</error>');

            return Command::FAILURE;
        }

        if (@file_put_contents($private, $pem) === false || @file_put_contents($public, (string) $details['key']) === false) {
            $output->writeln('<error>Could not write the key pair to ' . self::DIRECTORY . '/.</error>');

            return Command::FAILURE;
        }
        // The private key is the identity of every token this server issues.
        @chmod($private, 0600);
        @chmod($public, 0644);

        $output->writeln('<info>Signing key pair written to ' . self::DIRECTORY . '/</info> (RSA 2048).');
        $output->writeln('The public key is published at <info>/.well-known/jwks.json</info>; nothing needs copying.');
        $output->writeln('Make sure the web server\'s user can read both files.');
        $output->writeln('');
        $output->writeln('<comment>Back this pair up</comment>, outside the repository: losing it signs everybody out of');
        $output->writeln('every client, and it must never be committed.');

        return Command::SUCCESS;
    }
}
