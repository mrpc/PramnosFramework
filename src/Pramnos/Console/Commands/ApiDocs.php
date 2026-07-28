<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

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
 * php pramnos api:docs                                  # scan src/Controllers, write www/api/openapi.json
 * php pramnos api:docs --namespace='App\Controllers'    # explicit controllers namespace
 * php pramnos api:docs --controllers=src/Api/Controllers --output=www/api/openapi.json
 * php pramnos api:docs --overrides=src/openapi-overrides.json  # deep-merge hand-written schemas
 * ```
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
            ->addOption('controllers', null, InputOption::VALUE_REQUIRED, 'Controllers directory (relative to project root)', 'src/Controllers')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Controllers namespace (auto-detected from app/app.php when omitted)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file for the OpenAPI JSON', 'www/api/openapi.json')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'API title')
            ->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'API version', '1.0.0')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'API description')
            ->addOption('server', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Server URL (repeatable)')
            ->addOption('overrides', null, InputOption::VALUE_REQUIRED, 'Path to an openapi-overrides.json to deep-merge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $this->baseDir();

        $controllersPath = $this->resolve($base, (string) $input->getOption('controllers'));
        $namespace       = (string) ($input->getOption('namespace') ?? $this->detectNamespace($base));

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

        $outputPath = $this->resolve($base, (string) $input->getOption('output'));
        $dir        = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $outputPath,
            (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );

        $output->writeln(sprintf(
            '<info>Wrote %d path(s), %d operation(s) to %s</info>',
            count($document['paths'] ?? []),
            $operationCount,
            $outputPath
        ));

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
     * Best-effort read of the app namespace from app/app.php, appending
     * \Controllers (the convention Router::loadFromDirectory uses).
     */
    private function detectNamespace(string $base): string
    {
        $appFile = $base . '/app/app.php';
        if (!is_file($appFile)) {
            return '';
        }
        if (preg_match("/'namespace'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($appFile), $m)) {
            return rtrim($m[1], '\\') . '\\Controllers';
        }
        return '';
    }
}
