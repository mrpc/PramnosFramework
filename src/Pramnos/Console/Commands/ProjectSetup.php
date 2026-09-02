<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Console\Commands\Concerns\RunsProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * project:setup — bring a freshly cloned checkout up to a working local environment.
 *
 * ## The question this answers
 *
 * *"I have just cloned this project. What do I have to create by hand?"* Until the
 * credentials moved into `.env` the answer was "nothing", because they were committed —
 * which is the problem that change fixed and the gap this fills. `.env` is now
 * deliberately absent from a clone, and without it Docker starts a database with no
 * password and the application cannot connect. There was nothing that said so.
 *
 * ## What it does, in order
 *
 *   1. `.env` from `.env.example`, prompting only for what it cannot work out
 *   2. `docker-compose up -d --build`
 *   3. `composer install` inside the container
 *   4. wait for the database to accept connections
 *   5. framework migrations
 *   6. an administrator, if the project has the auth feature and you want one
 *   7. `npm install` and a first build, when the project has a front end
 *
 * Every step is skipped when it is already done, so running it twice is safe and
 * running it after a `git pull` is a reasonable way to catch up. That is the difference
 * between this and `init`: `init` creates a project and refuses to touch one that
 * exists; this one only ever operates on a project that already does.
 *
 * ## What it deliberately does not do
 *
 * It does not write, patch or generate a single project file. Not one of the steps above
 * is a scaffolding step — `project:reconfigure` and `project:resync` own that, and a
 * command that both sets up an environment *and* edits tracked files is one nobody can
 * safely run on a checkout with local changes.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class ProjectSetup extends Command
{
    use RunsProcesses;

    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** When true, no external process runs — the plan is printed instead. */
    public bool $skipProcesses = false;

    protected function configure(): void
    {
        $this->setName('project:setup')
            ->setDescription('Set up a local environment for a cloned project (.env, Docker, deps, migrations)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen and change nothing')
            ->addOption('no-docker', null, InputOption::VALUE_NONE, 'Skip starting Docker (an environment that is already up, or a non-Docker host)')
            ->addOption('no-migrations', null, InputOption::VALUE_NONE, 'Skip the framework migrations')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Do not offer to create an administrator')
            ->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'Database password, instead of being asked for it')
            ->addOption('force-env', null, InputOption::VALUE_NONE, 'Rewrite .env even if one is already there');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->targetBaseDir === '') {
            $this->targetBaseDir = defined('ROOT') ? ROOT : (string) getcwd();
        }
        $this->dryRun = (bool) $input->getOption('dry-run');

        $output->writeln('<info>Setting up a local environment</info>');
        $output->writeln('  ' . $this->targetBaseDir);
        $output->writeln('');

        if (!$this->looksLikeAProject()) {
            $output->writeln('<error>This does not look like a Pramnos project.</error>');
            $output->writeln('  Expected <comment>composer.json</comment> and <comment>app/config/settings.php</comment> here.');
            $output->writeln('  To create a new project instead, run <info>init</info>.');

            return Command::FAILURE;
        }

        if (!$this->prepareEnv($input, $output)) {
            return Command::FAILURE;
        }

        $useDocker = !$input->getOption('no-docker') && $this->hasDocker();

        if ($useDocker) {
            $this->startDocker($output);
            $this->installDependencies($output);
            $this->waitForDatabase($output);
        } else {
            $output->writeln('  <comment>Skipping Docker.</comment> Install dependencies and start your own server.');
        }

        $cliName = $this->cliName();

        if ($useDocker && !$input->getOption('no-migrations')) {
            $this->migrate($output, $cliName);

            if (!$input->getOption('no-admin')) {
                $this->offerAdminUser($input, $output, $cliName);
            }
        }

        if ($useDocker) {
            $this->buildFrontEnd($output);
        }

        $this->reportNextSteps($output, $cliName, $useDocker);

        return Command::SUCCESS;
    }

    // ── Preconditions ───────────────────────────────────────────────────────

    /**
     * Whether this directory is a project at all.
     *
     * Checked because every step below is destructive-ish in the way a wrong directory
     * makes worse: `docker-compose up` in the wrong place either fails confusingly or
     * starts somebody else's containers. Two files rather than one, because a bare
     * `composer.json` is any PHP project.
     */
    private function looksLikeAProject(): bool
    {
        return file_exists($this->targetBaseDir . '/composer.json')
            && file_exists($this->targetBaseDir . '/app/config/settings.php');
    }

    private function hasDocker(): bool
    {
        if (!file_exists($this->targetBaseDir . '/docker-compose.yml')) {
            return false;
        }

        return $this->skipProcesses ? true : $this->step('docker version', 'Checking Docker', $this->nullOutput()) === 0;
    }

    // ── 1. .env ─────────────────────────────────────────────────────────────

    /**
     * Create `.env` from `.env.example`, asking only for what cannot be worked out.
     *
     * The host user ids are read from this machine rather than asked for: their correct
     * value is `id -u`/`id -g` and nobody knows theirs by heart, while getting them
     * wrong means every file the container writes into the bind mount is owned by
     * somebody else. The password is the one thing genuinely only the operator knows.
     *
     * An existing `.env` is left alone unless `--force-env`. It is the one file in the
     * project that is not in version control, so overwriting it is the one edit here
     * that cannot be recovered with git.
     */
    private function prepareEnv(InputInterface $input, OutputInterface $output): bool
    {
        $envPath     = $this->targetBaseDir . '/.env';
        $examplePath = $this->targetBaseDir . '/.env.example';

        if (file_exists($envPath) && !$input->getOption('force-env')) {
            $output->writeln('  <info>.env</info> is already there — left alone. (--force-env to rewrite it.)');

            return true;
        }

        if (!file_exists($examplePath)) {
            $output->writeln('<error>No .env.example to copy.</error>');
            $output->writeln('  This project predates env-based configuration. Its credentials are still in');
            $output->writeln('  <comment>app/config/settings.php</comment>, so there is nothing for this step to create.');

            return false;
        }

        $lines = preg_split('/\R/', (string) file_get_contents($examplePath)) ?: [];
        $ids   = Init::hostUserIds();

        // Asked once, up front, rather than per blank key: a prompt in the middle of a
        // list of keys reads as an error.
        $password = (string) ($input->getOption('db-pass') ?? '');
        if ($password === '' && $this->needsPassword($lines)) {
            $password = (string) $this->ask(
                $input,
                $output,
                new Question('Database password (as in the project\'s own environment): ')
            );
        }

        $filled = [];
        foreach ($lines as $line) {
            if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                $filled[] = $line;
                continue;
            }

            [$all, $key, $value] = $m;

            if (isset($ids[$key])) {
                $filled[] = $key . '=' . $ids[$key];
                continue;
            }

            if ($value === '' && str_contains($key, 'PASSWORD')) {
                $filled[] = $key . '=' . $password;
                continue;
            }

            $filled[] = $all;
        }

        if ($this->dryRun) {
            $output->writeln('  would write: <comment>.env</comment> (' . count($lines) . ' keys from .env.example)');

            return true;
        }

        file_put_contents($envPath, implode("\n", $filled));
        $output->writeln('  Wrote <info>.env</info> from .env.example.');

        // Said out loud because it is the one thing that can still be wrong after this
        // command reports success, and the symptom — "authentication failed" — points at
        // the database rather than at a file the operator was never told to check.
        if ($password === '' && $this->needsPassword($lines)) {
            $output->writeln('  <comment>No password was given: fill in .env before the database will accept you.</comment>');
        }

        return true;
    }

    /** @param list<string> $lines */
    private function needsPassword(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/^[A-Z0-9_]*PASSWORD=$/', $line)) {
                return true;
            }
        }

        return false;
    }

    // ── 2-7. The environment ────────────────────────────────────────────────

    private function startDocker(OutputInterface $output): void
    {
        $this->step('docker-compose up -d --build', 'Starting Docker environment', $output);
    }

    private function installDependencies(OutputInterface $output): void
    {
        // Retried for the same reason init retries it: composer extracts into the
        // bind-mounted vendor/, and Docker Desktop on macOS intermittently reports a
        // directory it has just created as missing.
        $status = 1;
        for ($attempt = 1; $attempt <= 3 && $status !== 0; $attempt++) {
            $status = $this->step(
                'docker-compose exec -T -u www-data -e COMPOSER_HOME=/tmp/composer app composer install --no-interaction',
                $attempt === 1 ? 'Installing dependencies' : "Installing dependencies (retry $attempt/3)",
                $output
            );
        }
    }

    /**
     * Wait for the database to accept a connection.
     *
     * Not a courtesy: `docker-compose up -d` returns as soon as the containers are
     * *created*, and a fresh Postgres or MySQL volume takes several seconds to
     * initialise. Migrating into that window fails with a connection error that reads
     * like a configuration mistake.
     */
    private function waitForDatabase(OutputInterface $output): void
    {
        if ($this->dryRun || $this->skipProcesses) {
            $output->writeln('  would wait for: <comment>the database to accept connections</comment>');

            return;
        }

        // needs a running database; the tests set skipProcesses
        // @codeCoverageIgnoreStart
        $output->write('Waiting for database ');
        $symbols = ['/', '-', '\\', '|'];

        for ($try = 0; $try < 30; $try++) {
            $output->write("\r\033[KWaiting for database " . $symbols[$try % 4]);

            // Both clients are tried rather than reading the configured driver: this
            // command does not parse settings.php, and asking the wrong one costs a
            // failed exec rather than a wrong answer.
            exec('docker-compose exec -T db pg_isready -q 2>/dev/null', $ignored, $pg);
            exec('docker-compose exec -T db mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null', $ignored, $my);

            if ($pg === 0 || $my === 0) {
                $output->writeln("\r\033[KWaiting for database <info>READY</info>");

                return;
            }
            sleep(2);
        }

        $output->writeln("\r\033[KWaiting for database <comment>TIMEOUT (proceeding anyway)</comment>");
        // @codeCoverageIgnoreEnd
    }

    private function migrate(OutputInterface $output, string $cliName): void
    {
        $this->step(
            "docker-compose exec -T -u www-data app php $cliName.php migrate --scope=framework",
            'Running framework migrations',
            $output,
            true // the per-migration list is always worth seeing
        );
    }

    /**
     * Offer to create an administrator, and only offer.
     *
     * Delegated to the project's own CLI rather than reimplemented: `user:create` knows
     * this application's password policy, its user table and its permission model, and a
     * second implementation here would diverge from all three the first time one changed.
     */
    private function offerAdminUser(InputInterface $input, OutputInterface $output, string $cliName): void
    {
        $wanted = (bool) $this->ask($input, $output, new ConfirmationQuestion(
            'Create an administrator account now? [Y/n] ', true
        ));

        if (!$wanted) {
            $output->writeln("  Later: <info>./$cliName user:create --admin</info>");

            return;
        }

        // Interactive, so it is run through the terminal rather than the spinner — a
        // spinner over a command that is waiting for input shows a spinner forever.
        $output->writeln('');
        if ($this->dryRun || $this->skipProcesses) {
            $output->writeln("  would run: <comment>./$cliName user:create --admin</comment>");

            return;
        }

        // interactive passthrough; the tests set skipProcesses
        // @codeCoverageIgnoreStart
        passthru("docker-compose exec -u www-data app php $cliName.php user:create --admin");
        // @codeCoverageIgnoreEnd
    }

    /**
     * Install and build the front end, when there is one.
     *
     * Best-effort: a project can be perfectly usable with an unbuilt front end — it
     * serves the fallback assets — and a failed `npm install` is not a reason to report
     * that the environment did not come up.
     */
    private function buildFrontEnd(OutputInterface $output): void
    {
        if (!file_exists($this->targetBaseDir . '/package.json')) {
            return;
        }

        $this->step(
            'docker-compose exec -T -u www-data -e HOME=/tmp app sh -lc "npm install --no-audit --no-fund"',
            'Installing front-end dependencies',
            $output
        );

        $scripts = json_decode((string) file_get_contents($this->targetBaseDir . '/package.json'), true);
        if (isset($scripts['scripts']['build'])) {
            $this->step(
                'docker-compose exec -T -u www-data -e HOME=/tmp app sh -lc "npm run build"',
                'Building the front end',
                $output
            );
        }
    }

    private function reportNextSteps(OutputInterface $output, string $cliName, bool $useDocker): void
    {
        $output->writeln('');
        $output->writeln('<info>Environment ready.</info>');

        if ($useDocker) {
            $port = $this->publishedPort();
            $output->writeln($port === ''
                ? '  The application is served by the app container.'
                : "  <comment>http://localhost:$port</comment>");
            $output->writeln("  Shell: <info>./dockerbash</info>   Tests: <info>./dockertest</info>   CLI: <info>./$cliName</info>");
        }

        $output->writeln('  Configuration is in <comment>.env</comment>, which is not committed.');
    }

    // ── Small helpers ───────────────────────────────────────────────────────

    /**
     * The project's CLI entry point, read off disk.
     *
     * Every project names it after itself, so it cannot be assumed — and the wrong name
     * turns the migration step into "file not found", which reads as a broken framework
     * rather than a wrong guess.
     */
    private function cliName(): string
    {
        foreach (glob($this->targetBaseDir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'index' || $name === 'webhook') {
                continue;
            }
            if (str_contains((string) file_get_contents($file), 'internalApplication')) {
                return $name;
            }
        }

        return 'pramnos';
    }

    /** The host port `docker-compose.yml` publishes for the app, or '' if unreadable. */
    private function publishedPort(): string
    {
        $compose = @file_get_contents($this->targetBaseDir . '/docker-compose.yml');
        if ($compose === false || !preg_match('/-\s*"(\d+):80"/', $compose, $m)) {
            return '';
        }

        return $m[1];
    }

    /**
     * One external step.
     *
     * Named `step`, not `run`: `Command::run()` is public and final in spirit, and a
     * private `run()` here is a fatal at class-load time rather than a confusing
     * override — which is how this was caught.
     *
     * A seam as much as a shortcut: `skipProcesses` is what lets the tests drive the
     * whole sequence without Docker, and without it every assertion here would need a
     * container.
     */
    private function step(string $command, string $message, OutputInterface $output, bool $showOutput = false): int
    {
        if ($this->skipProcesses) {
            $output->writeln('  would run: <comment>' . $command . '</comment>');

            return 0;
        }

        return $this->runProcessWithSpinner($command, $message, $output, $showOutput);
    }

    /** Ask a question through the helper set, which the tests replace. */
    private function ask(InputInterface $input, OutputInterface $output, Question $question): mixed
    {
        return $this->getHelper('question')->ask($input, $output, $question);
    }

    private function nullOutput(): OutputInterface
    {
        return new \Symfony\Component\Console\Output\NullOutput();
    }

    /**
     * {@inheritDoc}
     *
     * Nothing here builds Docker images itself, so there is no daemon output to
     * interpret — `docker-compose up` failing is reported with its own output by the
     * spinner. Init has the hint catalogue because it is the command that pulls images.
     */
    protected function explainDockerFailure(string $log, OutputInterface $output): void
    {
        if (str_contains($log, 'Cannot connect to the Docker daemon')) {
            $output->writeln('  <comment>Docker is not running. Start Docker Desktop and try again.</comment>');
        }
    }
}
