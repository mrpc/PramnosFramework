<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared ground for the `spa:` commands.
 *
 * The front-end toolchain lives in the app container and is driven by npm, so
 * the two things a SPA project does daily — serve it with HMR, build it for
 * production — were `./dockernpm run dev` and `./dockernpm run build`: correct,
 * but nowhere in `pramnos list`, so they had to be remembered from the docs
 * rather than found from the CLI everything else in the project uses.
 *
 * What the subclasses get from here is the part that is the same for both and
 * easy to get wrong: refusing to run in a project that has no build step at all,
 * installing dependencies when they are missing rather than failing with npm's
 * own error, and running through `./dockernpm` so the toolchain in the image is
 * used instead of whatever Node the host happens to have.
 */
abstract class SpaCommandBase extends Command
{
    /** Project root. Overridable for testing. */
    public string $projectRoot = '';

    /**
     * app/app.php, loaded at most once.
     *
     * `require` of an already-included file returns `true` rather than the array
     * it evaluates to, so the result is cached instead of re-required.
     *
     * @var array<string, mixed>|null
     */
    private ?array $config = null;

    /** The project root, defaulting to the running application's. */
    protected function root(): string
    {
        if ($this->projectRoot !== '') {
            return $this->projectRoot;
        }

        // tests always pre-set projectRoot
        // @codeCoverageIgnoreStart
        return defined('ROOT') ? ROOT : (string) getcwd();
        // @codeCoverageIgnoreEnd
    }

    /**
     * The project's configuration, or [] when there is none to read.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        if ($this->config === null) {
            $path = $this->root() . '/app/app.php';
            $data = is_file($path) ? require $path : null;
            $this->config = is_array($data) ? $data : [];
        }

        return $this->config;
    }

    /**
     * Refuse, with a reason, unless this project has a SPA that needs building.
     *
     * Three ways a project can fail this, each with its own answer — a single
     * "not supported here" would leave the reader guessing which one they hit:
     *
     *   - no `app/app.php`: the command was not run from a project root;
     *   - `app_style = mvc`: there is no front end to serve or build;
     *   - a build-less stack: the sources under `www/assets/js/` are served
     *     exactly as written, so there is nothing to build and nothing for a dev
     *     server to supply.
     */
    protected function requireBuildStack(OutputInterface $output): bool
    {
        if (!is_file($this->root() . '/app/app.php')) {
            $output->writeln('<error>No app/app.php here — run this from a project root.</error>');
            return false;
        }

        $config   = $this->config();
        $appStyle = (string) ($config['app_style'] ?? 'mvc');
        if ($appStyle === 'mvc') {
            $output->writeln('<error>This project has no SPA front end.</error>');
            $output->writeln('  Its pages are server-rendered; there is no front-end build.');
            return false;
        }

        if (!Init::spaNeedsNode((string) ($config['spa_stack'] ?? ''))) {
            $output->writeln('<error>This project\'s SPA has no build step.</error>');
            $output->writeln('  Sources in <comment>www/assets/js/</comment> are served exactly as written, with their');
            $output->writeln('  URLs stamped by file mtime for cache-busting — so there is nothing to');
            $output->writeln('  build, and nothing for a dev server to supply. Just reload the page.');
            return false;
        }

        return true;
    }

    /**
     * Run an npm command for this project, installing dependencies first if they
     * are not there yet.
     *
     * Where npm comes from is decided by `npmRunner()` — the toolchain lives in
     * the container, and the console may already be in it.
     *
     * @param string $args npm arguments, e.g. "run build"
     * @return int Exit code of the npm command.
     */
    protected function npm(string $args, OutputInterface $output): int
    {
        $root   = $this->root();
        $runner = $this->npmRunner($output);

        if ($runner === '') {
            return Command::FAILURE;
        }

        // npm's own error for a missing node_modules is about a missing binary,
        // several lines down, and says nothing about what to do. Install instead:
        // it is what the reader would do next, and it is idempotent.
        if (!is_dir($root . '/node_modules')) {
            $output->writeln('<comment>node_modules is missing — installing dependencies first.</comment>');
            $code = $this->passthru($this->shellCommand($root, $runner, 'install'));
            if ($code !== 0) {
                return $code;
            }
        }

        return $this->passthru($this->shellCommand($root, $runner, $args));
    }

    /**
     * How to invoke npm from wherever this command is running, or '' if it cannot
     * be invoked at all (having said why).
     *
     * **Inside the container, run npm directly.** The scaffolded CLI wrapper is
     * `docker-compose exec -u www-data app php <cli>.php`, so the console is
     * normally already in the container — and delegating to `./dockernpm` from
     * there means asking Docker to exec into Docker. It fails with "The app
     * container is not running", which is both wrong and impossible to act on
     * from inside the container it is talking about.
     *
     * `HOME=/tmp` for the same reason dockernpm sets it: www-data's home is not
     * writable, and npm wants a cache directory.
     */
    private function npmRunner(OutputInterface $output): string
    {
        if ($this->inContainer()) {
            if (!$this->hasNpm()) {
                $output->writeln('<error>This container has no npm binary.</error>');
                $output->writeln('  Rebuild the image with node: <comment>docker-compose build app</comment>');
                return '';
            }

            return 'HOME=/tmp npm';
        }

        if (is_file($this->root() . '/dockernpm')) {
            return './dockernpm';
        }

        if ($this->hasNpm()) {
            return 'npm';
        }

        $output->writeln('<error>Neither ./dockernpm nor npm is available here.</error>');
        $output->writeln('  Start the containers (<comment>docker-compose up -d</comment>) or install Node.');

        return '';
    }

    /**
     * The shell command to run, from the project root.
     *
     * `cd` rather than chdir(): the child needs the project root — `./dockernpm`
     * shells out to docker-compose, which finds docker-compose.yml relative to
     * the working directory, and npm needs to find package.json. Mutating this
     * process's cwd to achieve that would outlive the call.
     */
    private function shellCommand(string $root, string $runner, string $args): string
    {
        return 'cd ' . escapeshellarg($root) . ' && ' . $runner . ' ' . $args;
    }

    /**
     * Whether this process is running inside a container.
     *
     * Separated so tests can pin it: the answer differs between a developer's
     * host, the scaffolded CLI wrapper, and the suite itself — which runs in a
     * container, and would otherwise take a different branch than the code it is
     * checking.
     */
    protected function inContainer(): bool
    {
        // overridden in tests
        // @codeCoverageIgnoreStart
        return is_file('/.dockerenv');
        // @codeCoverageIgnoreEnd
    }

    /** Whether an npm binary is reachable. Separated so tests can pin it. */
    protected function hasNpm(): bool
    {
        // overridden in tests; shelling out here would
        // @codeCoverageIgnoreStart
        // make the result depend on the machine running the suite.
        exec('command -v npm 2>/dev/null', $out, $code);
        return $code === 0;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Run a command, passing its output straight through.
     *
     * Protected so tests can capture the command instead of starting a process —
     * the same seam `Serve` uses.
     */
    protected function passthru(string $command): int
    {
        // replaced in tests; running npm for real is
        // @codeCoverageIgnoreStart
        // not something a unit test should do.
        $code = 0;
        passthru($command, $code);
        return $code;
        // @codeCoverageIgnoreEnd
    }

    /**
     * The application's own URL, as far as it can be known, or ''.
     *
     * Read from the published port in docker-compose.yml. It is a hint printed
     * next to the dev server, and the reason it is worth the effort: the Vite
     * port serves no HTML, so the one thing a reader must not do is open it —
     * and the URL they *should* open is the piece of information that makes that
     * concrete rather than a warning to remember.
     */
    protected function appUrl(): string
    {
        $compose = $this->root() . '/docker-compose.yml';
        if (!is_file($compose)) {
            return '';
        }

        if (!preg_match('/-\s*"(\d+):80"/', (string) file_get_contents($compose), $m)) {
            return '';
        }

        $base = 'http://localhost:' . $m[1];

        // A hybrid project mounts the SPA under /app; a pure SPA answers at the
        // root. Sending someone to the wrong one of those looks like a 404 in the
        // application rather than a wrong URL.
        return ((string) ($this->config()['app_style'] ?? '')) === 'hybrid'
            ? $base . '/app'
            : $base;
    }
}
