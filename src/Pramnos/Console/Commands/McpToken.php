<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\JWT;
use Pramnos\Auth\Scopes;
use Pramnos\Mcp\McpServiceProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mint a token for the MCP endpoint, and print the client configuration for it.
 *
 * The last step of replacing SSH, and the one that was missing. The endpoint,
 * the tools and the scopes all existed; getting a credential meant going through
 * an OAuth authorization flow by hand, which is a browser round-trip for a
 * capability whose whole point is that a developer is at a terminal.
 *
 *     php <cli> mcp:token --user=1 --scopes=diagnostics,logs,db --days=30
 *
 * Run it **on the installation you want to reach** — it needs that database,
 * because minting a token means writing a row into it. What it prints is pasted
 * into the client on the machine you are working from.
 *
 * ### The value is shown once
 *
 * `usertokens.token` is encrypted at rest, so this is the only moment the token
 * is readable. Losing it means minting another and revoking this one, which is
 * cheap — a token is not an identity, it is one revocable grant.
 *
 * ### It is a JWT, because that is what the middleware verifies
 *
 * `UnifiedAuthMiddleware` decodes the bearer against the application's signing
 * key before it looks anything up, so a random string in `usertokens` would be
 * refused at the first step with no explanation. The claims mirror the ones
 * {@see \Pramnos\Auth\SessionExchange} mints, and the signing key is read the
 * same way — including its refusal to sign when the only available key would be
 * a constant every installation shares.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class McpToken extends Command
{
    /**
     * The short names, so nobody has to remember whether it is `db_read` or `db-read`.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'diagnostics' => 'mcp:diagnostics',
        'logs'        => 'mcp:logs',
        'db'          => 'mcp:db_read',
        'db_read'     => 'mcp:db_read',
    ];

    protected function configure(): void
    {
        $this->setName('mcp:token')
            ->setDescription('Mint a token for this installation\'s MCP endpoint and print the client config')
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User id or email the token acts as. Every call it makes is recorded as theirs.'
            )
            ->addOption(
                'scopes',
                's',
                InputOption::VALUE_REQUIRED,
                'Comma-separated: diagnostics, logs, db — or full scope names. Default: diagnostics',
                'diagnostics'
            )
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Days until it expires. 0 means never, which is rarely what you want.',
                '30'
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Base URL of this installation. Defaults to the configured sURL.'
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'What the server is called in the client config.',
                'production'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userRef = (string) ($input->getOption('user') ?? '');

        if (trim($userRef) === '') {
            $output->writeln('<error>--user is required.</error>');
            $output->writeln(' A token acts as somebody. Every call it makes is recorded as theirs,');
            $output->writeln(' which is the point — an audit trail with no name on it is not one.');

            return Command::FAILURE;
        }

        $scopes = $this->resolveScopes((string) $input->getOption('scopes'), $output);

        if ($scopes === null) {
            return Command::FAILURE;
        }

        try {
            $user = $this->findUser(trim($userRef));
        } catch (\Throwable $exception) {
            /*
             * Almost always the same cause, and the driver's message does not say it:
             * this command was run somewhere without the installation's database.
             * Minting a token writes a row, so there is nowhere else it can happen —
             * and «No such file or directory» from a socket path is not a sentence
             * anybody can act on.
             */
            $output->writeln('<error>Could not reach the database.</error>');
            $output->writeln(' ' . $exception->getMessage());
            $output->writeln('');
            $output->writeln(' Run this <info>on the installation you want to reach</info> — minting a token');
            $output->writeln(' means writing a row into its `usertokens`. What it prints is what you');
            $output->writeln(' paste into the client on the machine you work from.');

            return Command::FAILURE;
        }

        if ($user === null) {
            $output->writeln('<error>No user matches "' . $userRef . '".</error>');

            return Command::FAILURE;
        }

        $key = $this->signingKey();

        if ($key === '') {
            $output->writeln('<error>This installation has no usable signing key.</error>');
            $output->writeln(' It declares no <info>authenticationKey</info> and there is no <info>sURL</info>');
            $output->writeln(' to derive one from. Refusing rather than signing with a constant every');
            $output->writeln(' installation would share — a token minted under that key verifies');
            $output->writeln(' against every other site in the same state.');

            return Command::FAILURE;
        }

        $days    = max(0, (int) $input->getOption('days'));
        $now     = time();
        $expires = $days > 0 ? $now + ($days * 86400) : null;

        $claims = [
            'iss' => defined('sURL') ? sURL : '',
            'iat' => $now,
            // The same twelve-hour backdate SessionExchange uses, for the same reason:
            // a clock a few minutes behind the signer must not reject a fresh token.
            'nbf' => $now - (3600 * 12),
        ];

        if ($expires !== null) {
            $claims['exp'] = $expires;
        }

        $jwt = JWT::encode($claims, $key);

        $user->addScopedToken(
            'access_token',
            $jwt,
            $scopes,
            'mcp:token — ' . implode(' ', $scopes),
            $expires
        );

        $this->report($output, $input, $user, $jwt, $scopes, $expires);

        return Command::SUCCESS;
    }

    /**
     * Turn what was typed into scope names, refusing anything unknown.
     *
     * Refusing rather than dropping: a typo that silently produced a token with one
     * fewer scope is a token that half works, and the half that does not shows up as
     * a tool missing from a list with nothing to say why.
     *
     * @return list<string>|null null when something was not a scope
     */
    private function resolveScopes(string $raw, OutputInterface $output): ?array
    {
        $wanted = array_filter(array_map('trim', explode(',', $raw)));

        if ($wanted === []) {
            $output->writeln('<error>--scopes is empty.</error>');

            return null;
        }

        // `mcp` is added rather than asked for. Every MCP scope inherits it, and a token
        // without it cannot call `whoami` — which is the first thing anybody reaches for
        // when a tool is missing from the list.
        $scopes = ['mcp'];

        foreach ($wanted as $one) {
            $scope = self::ALIASES[$one] ?? $one;

            if (!in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        $known = array_unique(array_merge(
            ['mcp'],
            array_values(McpServiceProvider::DIAGNOSTIC_SCOPES)
        ));

        $unknown = array_values(array_diff($scopes, $known));

        if ($unknown !== []) {
            $output->writeln('<error>Not MCP scopes: ' . implode(', ', $unknown) . '</error>');
            $output->writeln(' Available: <info>' . implode('</info>, <info>', $known) . '</info>');
            $output->writeln(' Short forms: <info>' . implode('</info>, <info>', array_keys(self::ALIASES)) . '</info>');

            return null;
        }

        /*
         * A warning and not a refusal, and the reason is the shape of the console.
         *
         * `Scopes` registers an MCP scope only while a tool asks for it, and the call
         * that offers those tools lives in an application ServiceProvider — booted by
         * `Application::init()`, which a web request runs and a console command does
         * not. So from here the registry is empty on *every* installation, including
         * the ones that offer everything.
         *
         * Refusing on that basis was the first version of this check and it was wrong
         * in the worst direction: it refused to mint a working token for a correctly
         * configured site. The endpoint decides at request time, which is the only
         * place that knows — so this says what it cannot verify rather than pretending
         * it verified it.
         */
        [$hasInvalid] = Scopes::hasInvalidScopes(implode(' ', $scopes));

        if ($hasInvalid) {
            $output->writeln('');
            $output->writeln(' <comment>Note: this command cannot tell whether the endpoint offers these.</comment>');
            $output->writeln(' <comment>The call that offers them runs in an application ServiceProvider,</comment>');
            $output->writeln(' <comment>which a web request boots and a console command does not — so the</comment>');
            $output->writeln(' <comment>scope registry looks empty from here on every installation.</comment>');
            $output->writeln('');
            $output->writeln(' <comment>If the endpoint answers with an empty tool list, this is why:</comment>');
            $output->writeln('');
            $output->writeln('   <info>McpServiceProvider::offerDiagnostics($app);</info>');
            $output->writeln('');
            $output->writeln(' <comment>Call <info>whoami</info> to see which scopes actually arrived.</comment>');
        }

        return $scopes;
    }

    /**
     * The user this token acts as, by id, email or username.
     *
     * The builder rather than a hand-built statement, because it is the only layer
     * that knows the prefix and the dialect, and it binds the reference instead of
     * interpolating it — this value comes off a command line.
     */
    private function findUser(string $reference): ?\Pramnos\User\User
    {
        $user = new \Pramnos\User\User();

        if (ctype_digit($reference)) {
            $user->load((int) $reference);

            return ((int) ($user->userid ?? 0)) > 0 ? $user : null;
        }

        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#users')
            ->select(['userid'])
            ->where(function ($q) use ($reference) {
                $q->where('email', $reference)
                  ->orWhere('username', $reference);
            })
            ->first();

        if (!$row || $row->numRows < 1) {
            return null;
        }

        $user->load((int) $row->fields['userid']);

        return ((int) ($user->userid ?? 0)) > 0 ? $user : null;
    }

    /**
     * The application's JWT signing key, or an empty string.
     *
     * Read the way {@see \Pramnos\Auth\SessionExchange} reads it, including the
     * refusal: without `sURL` the derivation reduces to a hash of a version string
     * that defaults to `edge`, so every installation in that state would sign with
     * the same publicly computable constant.
     */
    private function signingKey(): string
    {
        $app = \Pramnos\Application\Application::currentInstance();

        if (is_object($app) && isset($app->authenticationKey) && $app->authenticationKey !== '') {
            return (string) $app->authenticationKey;
        }

        if (!defined('sURL') || sURL === '') {
            return '';
        }

        return \Pramnos\Application\Api::deriveAuthenticationKey();
    }

    /**
     * Print the token once, and the configuration to paste beside it.
     *
     * @param list<string> $scopes
     */
    private function report(
        OutputInterface $output,
        InputInterface $input,
        \Pramnos\User\User $user,
        string $jwt,
        array $scopes,
        ?int $expires
    ): void {
        $base = rtrim((string) ($input->getOption('url') ?: (defined('sURL') ? sURL : '')), '/');
        $url  = ($base !== '' ? $base : 'https://your-site') . '/mcp';
        $name = (string) $input->getOption('name');

        $output->writeln('');
        $output->writeln(' <info>✓ Token minted.</info>');
        $output->writeln('');
        $output->writeln('   User    <comment>' . $user->userid . '</comment>'
            . ($user->email ? ' (' . $user->email . ')' : ''));
        $output->writeln('   Scopes  <comment>' . implode(' ', $scopes) . '</comment>');
        $output->writeln('   Expires <comment>'
            . ($expires === null ? 'never' : date('Y-m-d H:i', $expires)) . '</comment>');
        $output->writeln('');
        $output->writeln(' <comment>This is the only time the value is readable — the column is</comment>');
        $output->writeln(' <comment>encrypted at rest. Lose it and mint another.</comment>');
        $output->writeln('');
        $output->writeln('   ' . $jwt);
        $output->writeln('');
        $output->writeln(' <comment>── Add to .mcp.json on the machine you work from ────────────────</comment>');
        $output->writeln('');

        $config = [
            'mcpServers' => [
                $name => [
                    'type'    => 'http',
                    'url'     => $url,
                    'headers' => ['Authorization' => 'Bearer ' . $jwt],
                ],
            ],
        ];

        $output->writeln((string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $output->writeln('');
        $output->writeln(' <comment>Then ask it <info>whoami</info> first. If a scope you expect is missing</comment>');
        $output->writeln(' <comment>from the answer, that is the whole diagnosis.</comment>');
        $output->writeln('');
        $output->writeln(' <comment>To revoke: the user\'s sessions list, or</comment>');
        $output->writeln(' <comment>UPDATE usertokens SET status = 2 WHERE tokenid = …</comment>');
        $output->writeln('');
    }
}
