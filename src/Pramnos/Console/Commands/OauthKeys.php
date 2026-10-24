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
 * Nothing generates one on its own — a request that reaches the authorization server before
 * this has run gets `OAuth2 signing key missing at ...: run php bin/pramnos oauth:keys`
 * ({@see \Pramnos\Auth\OAuth2\OAuth2ServerFactory::readableKey()}), on purpose: a key
 * generated as a side effect of whichever request happened to be first is not something to
 * leave to request timing. Run this at deploy time, before the web server's user would have
 * to write into `app/keys`, or to replace a pair. **Replacing it invalidates every token
 * signed with the old key** — access tokens and ID tokens stop verifying, and a client
 * holding the old public key from the JWKS refuses the
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing pair. Every token signed with it stops verifying.')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Give the keys to this user (the web server\'s, e.g. www-data); needs root');
    }

    /** Whether this runs as root: a method, so a test can be either. */
    protected function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /** The project root: a method, so a test points it at a temporary directory. */
    protected function root(): string
    {
        return defined('ROOT') ? (string) ROOT : (string) getcwd();
    }

    /**
     * A new RSA 2048 pair as PEM, `[private, public]`, or null when OpenSSL cannot make one — a
     * method, so a test can be the OpenSSL that fails.
     *
     * @return array{string, string}|null
     */
    protected function generate(): ?array
    {
        $key     = openssl_pkey_new(['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || !openssl_pkey_export($key, $pem)) {
            return null;
        }

        return [(string) $pem, (string) $details['key']];
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

        $pair = $this->generate();
        if ($pair === null) {
            $output->writeln('<error>RSA key generation failed: ' . (string) openssl_error_string() . '</error>');

            return Command::FAILURE;
        }

        if (@file_put_contents($private, $pair[0]) === false || @file_put_contents($public, $pair[1]) === false) {
            $output->writeln('<error>Could not write the key pair to ' . self::DIRECTORY . '/.</error>');

            return Command::FAILURE;
        }
        // The private key is the identity of every token this server issues.
        @chmod($private, 0600);
        @chmod($public, 0644);

        // The key that encrypts authorization codes and refresh tokens: made once, never replaced
        // — a new one would end every refresh token — so the server never makes it mid-request.
        $encryption = $directory . '/encryption.key';
        if (!is_file($encryption) && @file_put_contents($encryption, base64_encode(random_bytes(32))) !== false) {
            @chmod($encryption, 0600);
        }

        $owner = (string) ($input->getOption('owner') ?? '');
        if ($owner !== '') {
            foreach ([$directory, $private, $public, $encryption] as $path) {
                if (is_file($path) || is_dir($path)) {
                    if (!@chown($path, $owner)) {
                        $output->writeln('<error>Could not give ' . self::DIRECTORY . ' to ' . $owner . ' (is that a user here, and is this root?).</error>');

                        return Command::FAILURE;
                    }
                }
            }
        }

        $output->writeln('<info>Signing key pair written to ' . self::DIRECTORY . '/</info> (RSA 2048).');
        $output->writeln('The public key is published at <info>/.well-known/jwks.json</info>; nothing needs copying.');
        if ($owner !== '') {
            $output->writeln('Owned by <info>' . $owner . '</info>.');
        } elseif ($this->runningAsRoot()) {
            // The private key is 0600: written by root, the web server cannot open it, and every
            // /oauth/token answers 500.
            $output->writeln('<comment>Written as root: the web server cannot read private.key.</comment> Give the keys');
            $output->writeln('to its user — <info>chown -R www-data ' . self::DIRECTORY . '</info> — or run again with');
            $output->writeln('<info>--force --owner=www-data</info>.');
        } else {
            $output->writeln('Make sure the web server\'s user is the one that ran this, or can read the files.');
        }
        $output->writeln('');
        $output->writeln('<comment>Back this pair up</comment>, outside the repository: losing it signs everybody out of');
        $output->writeln('every client, and it must never be committed.');

        return Command::SUCCESS;
    }
}
