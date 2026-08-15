<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Console\Make\StubRenderer;
use Pramnos\Routing\OpenApiGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate an OpenAPI 3.0 document from attribute-routed controllers.
 *
 * This is the attribute-native API-docs flow: instead of maintaining a separate
 * routes.php plus `@api` JSDoc comment blocks, apps that route with `#[Route]`
 * get their OpenAPI spec straight from {@see OpenApiGenerator}, which reflects the
 * same attributes the router dispatches from — so docs cannot drift from routes.
 *
 * ## Usage
 *
 * ```
 * php pramnos api:docs                                  # scan for controllers, write under the document root
 * php pramnos api:docs --namespace='App\Controllers'    # explicit controllers namespace
 * php pramnos api:docs --controllers=src/Api/Controllers # namespace follows the directory
 * php pramnos api:docs --overrides=src/openapi-overrides.json  # deep-merge hand-written schemas
 * ```
 *
 * ## What it scans, and why it says so
 *
 * The command used to default to `src/Controllers` and `www/api/openapi.json` and
 * report only where it wrote. An application that keeps its API in
 * `src/Api/Controllers` — the layout this command's own usage block suggests — got
 * `Wrote 1 path(s), 1 operation(s)` for **72** endpoints, and nothing in that line was
 * false. A document describing one endpoint of seventy-two is not obviously broken:
 * it is indistinguishable from an application that has one endpoint, so it gets
 * published and believed.
 *
 * So the success line now names the **directory and namespace it scanned**, the
 * defaults look for the API before the MVC controllers, and a scan that finds less
 * than a sibling directory would have found says so.
 *
 * Request/response schemas that cannot be inferred from routes are supplied via
 * the deep-merged `--overrides` document.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class ApiDocs extends Command
{
    /**
     * Base directory the relative paths resolve against. Defaults to ROOT (or the
     * cwd) at runtime; public so tests can point it at a temporary directory —
     * mirroring KeyGenerate / ScaffoldViews.
     */
    public string $targetBaseDir = '';

    protected static $defaultName = 'api:docs';

    protected function configure(): void
    {
        $this
            ->setName('api:docs')
            ->setDescription('Generate an OpenAPI document from #[Route] controllers')
            ->addOption('controllers', null, InputOption::VALUE_REQUIRED, 'Controllers directory (relative to project root). Default: the first of src/Api/Controllers or src/Controllers that exists', null)
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Controllers namespace (derived from app/app.php and the controllers directory when omitted)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file for the OpenAPI JSON. Default: <document root>/api/openapi.json', null)
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'API title')
            ->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'API version', '1.0.0')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'API description')
            ->addOption('server', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Server URL (repeatable)')
            ->addOption('overrides', null, InputOption::VALUE_REQUIRED, 'Path to an openapi-overrides.json to deep-merge')
            ->addOption('no-html', null, InputOption::VALUE_NONE, 'Do not also generate the RapiDoc HTML viewer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $this->baseDir();

        $controllersOption = $input->getOption('controllers');
        $controllersChosen = $controllersOption !== null
            ? (string) $controllersOption
            : $this->defaultControllersDir($base);

        $controllersPath = $this->resolve($base, $controllersChosen);
        $namespace       = (string) ($input->getOption('namespace')
            ?? $this->detectNamespace($base, $controllersChosen));

        if ($namespace === '') {
            $output->writeln('<error>Could not determine the controllers namespace. Pass --namespace.</error>');
            return Command::FAILURE;
        }
        if (!is_dir($controllersPath)) {
            $output->writeln("<error>Controllers directory not found: {$controllersPath}</error>");
            return Command::FAILURE;
        }

        $info = ['version' => (string) $input->getOption('api-version')];
        if ($input->getOption('title') !== null) {
            $info['title'] = (string) $input->getOption('title');
        }
        if ($input->getOption('description') !== null) {
            $info['description'] = (string) $input->getOption('description');
        }

        $servers = array_map(
            static fn (string $url): array => ['url' => $url],
            (array) $input->getOption('server')
        );

        $overrides = [];
        $overridesOption = $input->getOption('overrides');
        if ($overridesOption !== null) {
            $overridesPath = $this->resolve($base, (string) $overridesOption);
            if (!is_file($overridesPath)) {
                $output->writeln("<error>Overrides file not found: {$overridesPath}</error>");
                return Command::FAILURE;
            }
            $decoded = json_decode((string) file_get_contents($overridesPath), true);
            if (!is_array($decoded)) {
                $output->writeln("<error>Overrides file is not valid JSON: {$overridesPath}</error>");
                return Command::FAILURE;
            }
            $overrides = $decoded;
        }

        $document = (new OpenApiGenerator($info, $servers, $overrides))
            ->fromDirectory($controllersPath, $namespace);

        $operationCount = 0;
        foreach ($document['paths'] ?? [] as $methods) {
            $operationCount += count($methods);
        }

        $outputOption = $input->getOption('output');
        $outputPath   = $this->resolve(
            $base,
            $outputOption !== null ? (string) $outputOption : $this->defaultOutputFile($base)
        );
        $dir        = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $outputPath,
            (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );

        $output->writeln(sprintf(
            '<info>Scanned %s (namespace %s)</info>',
            $controllersChosen,
            $namespace
        ));
        $output->writeln(sprintf(
            '<info>Wrote %d path(s), %d operation(s) to %s</info>',
            count($document['paths'] ?? []),
            $operationCount,
            $outputPath
        ));

        // A thin result is the failure mode worth shouting about: it is published and
        // believed, because it looks exactly like an application that really does
        // serve that many endpoints. Only checked when the directory was not named
        // explicitly — somebody who passed --controllers has said where to look.
        if ($controllersOption === null) {
            $this->warnIfASiblingWouldHaveFoundMore(
                $output,
                $base,
                $controllersChosen,
                $operationCount
            );
        }

        // Also emit the RapiDoc HTML viewer next to the spec (docs/index.html),
        // matching what scaffolded Pramnos apps serve at /api/docs/.
        if (!$input->getOption('no-html')) {
            $docsDir  = $dir . '/docs';
            if (!is_dir($docsDir)) {
                mkdir($docsDir, 0775, true);
            }
            $html = (new StubRenderer())->render('api-docs.html', [
                'title' => (string) ($info['title'] ?? 'API') . ' — API documentation',
                'spec'  => '../' . basename($outputPath),
            ]);
            file_put_contents($docsDir . '/index.html', $html);
            $output->writeln('<info>Wrote docs viewer to ' . $docsDir . '/index.html</info>');
        }

        return Command::SUCCESS;
    }

    private function baseDir(): string
    {
        if ($this->targetBaseDir !== '') {
            return rtrim($this->targetBaseDir, '/');
        }
        if (defined('ROOT')) {
            return rtrim((string) ROOT, '/');
        }
        return rtrim((string) getcwd(), '/');
    }

    private function resolve(string $base, string $path): string
    {
        if ($path === '') {
            return $base;
        }
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path; // absolute
        }
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Directories this command looks in when `--controllers` was not given.
     *
     * `src/Api/Controllers` comes first because an application that has both keeps
     * its API there — which is also what this command's own usage block suggests.
     * An application with only `src/Controllers` is unaffected: that is what it
     * finds, and what it always found.
     *
     * @var array<int, string>
     */
    private const CONTROLLER_CANDIDATES = ['src/Api/Controllers', 'src/Controllers'];

    /**
     * Directory names that are a document root when one of them exists.
     *
     * @var array<int, string>
     */
    private const WEB_ROOT_CANDIDATES = ['www', 'public', 'html', 'web'];

    /**
     * The controllers directory to scan when none was named.
     *
     * @param  string $base Project root
     * @return string Relative path; the first candidate that exists, else the last
     */
    private function defaultControllersDir(string $base): string
    {
        foreach (self::CONTROLLER_CANDIDATES as $candidate) {
            if (is_dir($base . '/' . $candidate)) {
                return $candidate;
            }
        }

        // Nothing found: keep the historical default so the error message names the
        // path somebody expects to see.
        return self::CONTROLLER_CANDIDATES[count(self::CONTROLLER_CANDIDATES) - 1];
    }

    /**
     * Where to write when `--output` was not given.
     *
     * `www/` was hardcoded, which stopped being right the moment `pramnos init`
     * grew `--web-root`: a project scaffolded with `--web-root=public` had this
     * command create a `www/` beside it — served by nothing, and owned by whoever
     * ran the command.
     *
     * @param  string $base Project root
     * @return string Relative path to the OpenAPI file
     */
    private function defaultOutputFile(string $base): string
    {
        foreach (self::WEB_ROOT_CANDIDATES as $candidate) {
            if (is_file($base . '/' . $candidate . '/index.php')) {
                return $candidate . '/api/openapi.json';
            }
        }

        return self::WEB_ROOT_CANDIDATES[0] . '/api/openapi.json';
    }

    /**
     * Say so when the directory next door holds more of the API than the one scanned.
     *
     * This is the whole point of the filing that prompted it: `Wrote 1 path(s), 1
     * operation(s)` was true, and true is not the same as informative. Nothing here
     * changes what was written — switching directories on somebody would be a worse
     * surprise than a thin document — but the line is impossible to miss.
     *
     * @param  OutputInterface $output  Console output
     * @param  string          $base    Project root
     * @param  string          $scanned The directory that was scanned
     * @param  int             $found   Operations it yielded
     * @return void
     */
    private function warnIfASiblingWouldHaveFoundMore(
        OutputInterface $output,
        string $base,
        string $scanned,
        int $found
    ): void {
        foreach (self::CONTROLLER_CANDIDATES as $candidate) {
            if ($candidate === $scanned || !is_dir($base . '/' . $candidate)) {
                continue;
            }

            $namespace = $this->detectNamespace($base, $candidate);
            if ($namespace === '') {
                continue;
            }

            $other = (new OpenApiGenerator(['version' => '0'], [], []))
                ->fromDirectory($base . '/' . $candidate, $namespace);

            $otherCount = 0;
            foreach ($other['paths'] ?? [] as $methods) {
                $otherCount += count($methods);
            }

            if ($otherCount > $found) {
                $output->writeln(sprintf(
                    '<comment>%s holds %d operation(s) — more than the %d found in %s. '
                    . 'Re-run with --controllers=%s if that is the API.</comment>',
                    $candidate,
                    $otherCount,
                    $found,
                    $scanned,
                    $candidate
                ));
            }
        }
    }

    /**
     * The namespace of classes in a controllers directory.
     *
     * Reads the application namespace from `app/app.php` and **follows the
     * directory**, rather than appending a fixed `\Controllers`. The fixed suffix
     * was why this command's own documented example did not work: passing
     * `--controllers=src/Api/Controllers` without `--namespace` looked for
     * `App\Controllers\*` inside `src/Api/Controllers` and found nothing, which
     * presents as an application with no API rather than as an error.
     *
     * `src/Controllers` still yields `App\Controllers`, so nothing that worked
     * changes.
     *
     * @param  string $base                Project root
     * @param  string $controllersRelative Directory being scanned, relative to root
     * @return string Empty when the application namespace cannot be read
     */
    private function detectNamespace(string $base, string $controllersRelative = 'src/Controllers'): string
    {
        $appFile = $base . '/app/app.php';
        if (!is_file($appFile)) {
            return '';
        }
        if (!preg_match("/'namespace'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($appFile), $m)) {
            return '';
        }

        $root = rtrim($m[1], '\\');

        // Strip the PSR-4 source root; what remains are namespace segments.
        $relative = trim(str_replace('\\', '/', $controllersRelative), '/');
        if (str_starts_with($relative, 'src/')) {
            $relative = substr($relative, 4);
        }

        $segments = array_filter(explode('/', $relative), static fn ($s): bool => $s !== '');
        if ($segments === []) {
            return $root;
        }

        return $root . '\\' . implode('\\', $segments);
    }
}
