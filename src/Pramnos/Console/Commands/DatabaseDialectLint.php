<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Database\Dialect\DialectLinter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `db:dialect-lint` — the SQL in an application's code that would run on PostgreSQL and mean
 * something else than it did on MySQL, by file and line. See {@see DialectLinter}.
 *
 * ```bash
 * php bin/pramnos db:dialect-lint               # src and app
 * php bin/pramnos db:dialect-lint src/Admin --json
 * ```
 *
 * Exits `1` when it finds any. A line ending in `// dialect-ok` is one somebody has looked at.
 */
class DatabaseDialectLint extends Command
{
    protected function configure(): void
    {
        $this->setName('db:dialect-lint')
            ->setDescription('Name the SQL that would mean something else on PostgreSQL than on MySQL')
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Files or directories, under the project (default: src app)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'The findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root     = defined('ROOT') ? (string) ROOT : (string) getcwd();
        $paths    = (array) $input->getArgument('paths') ?: ['src', 'app'];
        $findings = [];
        foreach ($paths as $path) {
            $absolute = str_starts_with((string) $path, '/') ? (string) $path : rtrim($root, '/') . '/' . $path;
            foreach (self::phpFiles($absolute) as $file) {
                foreach (DialectLinter::file($file) as $finding) {
                    $findings[] = ['file' => ltrim(str_replace($root, '', $file), '/')] + $finding;
                }
            }
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $findings === [] ? Command::SUCCESS : Command::FAILURE;
        }
        foreach ($findings as $f) {
            $output->writeln($f['file'] . ':' . $f['line'] . '  <comment>' . $f['rule'] . '</comment>  ' . $f['message']);
        }
        $counts = array_count_values(array_column($findings, 'rule'));
        $output->writeln($findings === []
            ? '<info>Nothing that would mean something else on PostgreSQL.</info>'
            : count($findings) . ' finding(s): ' . implode(', ', array_map(static fn (string $rule, int $n): string => $n . ' ' . $rule, array_keys($counts), $counts)));

        return $findings === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The PHP files of a path: the file itself, or those under a directory, `vendor` left out.
     *
     * @return list<string>
     */
    private static function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [$path] : [];
        }
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $walk  = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !($entry->isDir() && in_array($entry->getFilename(), ['vendor', 'node_modules', '.git'], true))
        ));
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
