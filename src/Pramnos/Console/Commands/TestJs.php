<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * test:js — run the application's JavaScript tests with `node --test`.
 *
 * ```
 * php bin/<app> test:js                     # every *.test.js under tests/js
 * php bin/<app> test:js --dir=www/tests     # another directory
 * php bin/<app> test:js tests/js/notes.test.js
 * ```
 *
 * The directory is `js_tests_dir` in `app/app.php`, or `tests/js`. The framework's helpers —
 * a small DOM, a browser-script loader, fetch and jQuery stubs — are on `NODE_PATH`, so a test
 * has them as `require('pramnos-testing')` wherever the framework is installed. No npm install:
 * `node --test` and the helpers use nothing but Node itself.
 *
 * Exit code: node's — 0 when every test passed.
 */
class TestJs extends JsToolCommand
{
    protected function configure(): void
    {
        $this
            ->setName('test:js')
            ->setDescription("Run the application's JavaScript tests (node --test), with the framework's test helpers")
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Test files to run instead of the whole directory')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'The tests directory (default: js_tests_dir in app.php, or tests/js)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->has('node')) {
            return $this->noNode($output);
        }

        $files = array_values(array_map('strval', (array) $input->getArgument('files')));
        if ($files === []) {
            $dir = trim((string) ($input->getOption('dir') ?? $this->config('js_tests_dir') ?? 'tests/js'), '/');
            $files = $this->tests($dir);
            if ($files === []) {
                $output->writeln("<comment>No *.test.js under {$dir}/.</comment>");

                return Command::SUCCESS;
            }
        }

        $nodePath = self::toolingDir() . (getenv('NODE_PATH') ? PATH_SEPARATOR . getenv('NODE_PATH') : '');

        return $this->runTool(['node', '--test', ...$files], ['NODE_PATH' => $nodePath], $output);
    }

    /**
     * The test files under `$dir`, relative to the project, in order.
     *
     * @return list<string>
     */
    private function tests(string $dir): array
    {
        $root = $this->base() . '/' . $dir;
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file->getPathname();
            if (str_ends_with($path, '.test.js') && !str_contains($path, '/node_modules/')) {
                $found[] = $dir . '/' . substr($path, strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }
}
