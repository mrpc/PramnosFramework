<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Debug\DebugAccess;

/**
 * Mints a token that opens the debug toolbar for one browser.
 *
 * The toolbar is off on a live server, and it should be. But the bugs that
 * deserve a toolbar are mostly the ones that only happen there, on live data and
 * live traffic. This hands out a grant that is limited in two ways that matter:
 * it applies to whoever redeems it and nobody else, and it stops working by
 * itself.
 *
 * ```
 * php pramnos debug:token                       # one hour
 * php pramnos debug:token --ttl=15m
 * php pramnos debug:token --ttl=4h --url=https://example.com/orders
 * ```
 *
 * Open the printed URL. The toolbar appears — for that browser — and keeps
 * appearing, on every page and on every XHR the pages make, until the token
 * expires. `?_debug=off` on any URL ends it early.
 *
 * The token is signed with the application key, so it needs no storage and
 * rotating the key invalidates every outstanding one.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DebugToken extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'debug:token';

    protected function configure(): void
    {
        $this
            ->setName('debug:token')
            ->setDescription(
                'Issue a signed token that opens the debug toolbar for one browser'
            )
            ->addOption(
                'ttl',
                null,
                InputOption::VALUE_REQUIRED,
                'How long it lasts: 30m, 2h, 90 (seconds). Max 12h.',
                '1h'
            )
            ->addOption(
                'url',
                'u',
                InputOption::VALUE_REQUIRED,
                'Build the link against this URL instead of the site URL'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ttl = $this->parseTtl((string) $input->getOption('ttl'));

        if ($ttl === null) {
            $output->writeln(
                '<error>Could not read --ttl. Use 90, 30m, 2h or 1d.</error>'
            );

            return Command::FAILURE;
        }

        try {
            $token = DebugAccess::issue($ttl);
        } catch (\RuntimeException $ex) {
            $output->writeln('<error>' . $ex->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $expiry = (int) explode('.', $token, 2)[0];
        $base   = $this->baseUrl($input);

        $output->writeln('');
        $output->writeln(
            '  <info>' . $base
            . (str_contains($base, '?') ? '&' : '?')
            . DebugAccess::PARAM . '=' . $token . '</info>'
        );
        $output->writeln('');
        $output->writeln(
            '  Valid until <options=bold>' . date('Y-m-d H:i:s', $expiry)
            . '</> (' . $this->describe($ttl) . ')'
        );
        $output->writeln(
            '  Open it once; the toolbar then follows that browser, including '
            . 'its XHR calls.'
        );
        $output->writeln(
            '  End it early with <options=bold>?' . DebugAccess::PARAM . '='
            . DebugAccess::REVOKE . '</>'
        );
        $output->writeln('');
        $output->writeln(
            '  <comment>The toolbar exposes queries, logs and session keys. '
            . 'Treat this link as a credential.</comment>'
        );
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Read a `--ttl` value into seconds.
     *
     * @param  string   $value `90`, `30m`, `2h`, `1d`
     * @return int|null Null when it cannot be read
     */
    protected function parseTtl(string $value): ?int
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (!preg_match('/^(\d+)([smhd])$/', $value, $matches)) {
            return null;
        }

        $units = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

        return (int) $matches[1] * $units[$matches[2]];
    }

    /**
     * A human-readable duration, for the line under the link.
     */
    protected function describe(int $seconds): string
    {
        $capped = max(60, min($seconds, DebugAccess::MAX_TTL));
        $note   = $capped !== $seconds ? ', capped' : '';

        if ($capped % 3600 === 0) {
            return ($capped / 3600) . 'h' . $note;
        }

        if ($capped % 60 === 0) {
            return ($capped / 60) . 'm' . $note;
        }

        return $capped . 's' . $note;
    }

    /**
     * What to hang the token off.
     *
     * `--url` wins; otherwise the site URL as the application knows it, and
     * failing that a placeholder — the token is the part that matters, and a
     * command that cannot guess the host should still hand it over.
     */
    protected function baseUrl(InputInterface $input): string
    {
        $url = $input->getOption('url');

        if (is_string($url) && $url !== '') {
            return rtrim($url, '?&');
        }

        $setting = \Pramnos\Application\Settings::getSetting('siteurl');

        if (is_string($setting) && $setting !== '') {
            return rtrim($setting, '/') . '/';
        }

        if (defined('sURL') && is_string(sURL) && sURL !== '') {
            return sURL;
        }

        return 'https://your-site/';
    }
}
