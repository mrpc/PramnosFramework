<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Push\Vapid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate the VAPID key pair a browser needs before it can subscribe to notifications.
 *
 * Run once. **Rotating the key invalidates every existing subscription** — a browser that
 * subscribed with the old public key cannot be pushed to with the new private one, and nobody
 * finds out until somebody notices that notifications stopped. So this refuses to overwrite an
 * existing pair without `--force`, and says why.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class PushVapidGenerate extends Command
{
    protected function configure(): void
    {
        $this->setName('push:vapid-generate')
            ->setDescription('Generate the VAPID key pair for web push notifications')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Replace an existing pair. Every current subscription stops working.'
            );
    }

    /**
     * The service worker's gaps, as a seam.
     *
     * Read from a file on disk that a test cannot arrange, and it decides the most useful thing
     * this command says: that the key pair it just wrote is four parts out of five.
     *
     * @return array<string, string>
     */
    protected function workerGaps(): array
    {
        return \Pramnos\Push\ServiceWorker::missing();
    }

    protected function workerPath(): ?string
    {
        return \Pramnos\Push\ServiceWorker::path();
    }

    /**
     * Where `app/keys` is.
     *
     * A method rather than the expression inline, so a test can point it at a temporary
     * directory — the alternative is a test that writes a real key pair into the checkout it is
     * running in, which is exactly the file that must never be committed.
     */
    protected function root(): string
    {
        return defined('ROOT') ? (string) ROOT : (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root      = $this->root();
        $directory = $root . '/' . Vapid::DIRECTORY;
        $private   = $directory . '/' . Vapid::PRIVATE_FILE;
        $public    = $directory . '/' . Vapid::PUBLIC_FILE;

        if (Vapid::configured($root) && !$input->getOption('force')) {
            $output->writeln('<error>This installation already has a VAPID key pair.</error>');
            $output->writeln('');
            $output->writeln('Replacing it stops every existing subscription working: a browser');
            $output->writeln('that subscribed with the old public key cannot be pushed to with a');
            $output->writeln('new private one, and it will not be told — the notifications simply');
            $output->writeln('stop. Everybody would have to subscribe again.');
            $output->writeln('');
            $output->writeln('Pass <info>--force</info> if that is what you mean to do.');

            return Command::FAILURE;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            $output->writeln('<error>Could not create ' . Vapid::DIRECTORY . '.</error>');

            return Command::FAILURE;
        }

        try {
            $pair = Vapid::generate();
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (@file_put_contents($private, $pair['privateKey']) === false
            || @file_put_contents($public, $pair['publicKey']) === false
        ) {
            $output->writeln('<error>Could not write the key pair to ' . Vapid::DIRECTORY . '.</error>');

            return Command::FAILURE;
        }

        // The private key is the identity of every notification this application will ever send.
        @chmod($private, 0600);
        @chmod($public, 0644);

        $output->writeln('<info>VAPID key pair written to ' . Vapid::DIRECTORY . '/</info>');
        $output->writeln('');
        $output->writeln('  Public key:  ' . $pair['publicKey']);
        $output->writeln('');
        $output->writeln('The public key is handed to the browser by <info>GET /push/key</info>;');
        $output->writeln('nothing needs to be copied anywhere.');

        $subject = Vapid::subject();

        if ($subject === '') {
            $output->writeln('');
            $output->writeln('<comment>No contact subject is configured.</comment> RFC 8292 requires one —');
            $output->writeln("it is the address a push service uses when something is wrong with");
            $output->writeln('what you send, before it starts refusing. Set an admin email, or');
            $output->writeln("<info>'push' => ['subject' => 'mailto:you@example.com']</info> in app.php.");
        } else {
            $output->writeln('  Contact:     ' . $subject);
        }

        /*
         * The half that is not a key.
         *
         * Push is delivered to a service worker. With a pair on disk, subscriptions in the
         * table and a `201` from the push service, a worker with no `push` listener discards
         * every notification — silently, on every device, with no error anywhere.
         *
         * Reported here because this command is where somebody sets push up, and because a
         * project scaffolded before the handlers existed has a worker without them and no way
         * to find out.
         */
        $missing = $this->workerGaps();

        if ($missing !== []) {
            $output->writeln('');
            $output->writeln('<comment>The service worker cannot receive this yet.</comment>');

            if ($this->workerPath() === null) {
                $output->writeln('There is no service worker at <info>www/sw.js</info>. Push is');
                $output->writeln('delivered to one, so a site without it cannot receive a');
                $output->writeln('notification at all.');
            } else {
                $output->writeln('Found ' . $this->workerPath() . ', without:');
                $output->writeln('');

                foreach ($missing as $handler => $why) {
                    $output->writeln('  <info>' . $handler . '</info> — ' . wordwrap($why, 70, "\n    "));
                }

                $output->writeln('');
                $output->writeln('The scaffolded worker carries all three. A project generated');
                $output->writeln('before web push existed has one without them.');
            }
        }

        $output->writeln('');
        $output->writeln('<comment>Back this pair up.</comment> Losing it means losing every subscriber:');
        $output->writeln('there is no way to re-establish a subscription from the server side.');

        return Command::SUCCESS;
    }
}
