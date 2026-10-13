<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Exports;
use Pramnos\Llm\Analyst\ReadOnlySql;
use Pramnos\Mcp\McpToolInterface;

/**
 * The same SELECT as `sql`, written to a CSV file the administrator downloads — up to
 * {@see MAX_ROWS} rows. The model gets the link, the count and five rows to describe it by; the
 * rows themselves never reach it.
 *
 * With a UTF-8 byte-order mark, so a spreadsheet reads Greek as Greek. Off unless the application
 * turns it on: `'llm' => ['analyst' => ['export' => true]]`.
 */
final class ExportCsvTool implements McpToolInterface
{
    public const MAX_ROWS = 100000;

    public function __construct(private readonly int $adminId)
    {
    }

    public function name(): string
    {
        return 'export_csv';
    }

    public function description(): string
    {
        return 'Write the rows of one read-only SELECT to a CSV file the administrator downloads, when '
            . 'they ask for a list or a file rather than an answer — up to ' . self::MAX_ROWS . ' rows. '
            . 'You get the link, the row count and five sample rows. Give the link as a Markdown link. '
            . 'The file is theirs alone and is kept ' . Exports::DAYS . ' days.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['sql'], 'properties' => [
            'sql' => ['type' => 'string', 'description' => 'One SELECT statement, with real table names.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        if ($this->adminId <= 0) {
            return 'Error: an export is made for the administrator asking, and there is none.';
        }

        Exports::prune();
        $directory = Exports::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return 'Error: there is nowhere to write the file.';
        }

        $name   = Exports::name($this->adminId);
        $file   = $directory . '/' . $name . '.csv';
        $handle = fopen($file, 'w');
        $sample = [];
        fwrite($handle, "\xEF\xBB\xBF");

        try {
            $count = ReadOnlySql::each(Factory::getDatabase(), (string) ($input['sql'] ?? ''), self::MAX_ROWS, static function (array $row) use ($handle, &$sample): void {
                if ($sample === []) {
                    fputcsv($handle, array_keys($row), ',', '"', '');
                }
                fputcsv($handle, array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $row), ',', '"', '');
                if (count($sample) < 5) {
                    $sample[] = $row;
                }
            });
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($file);

            return $e instanceof \InvalidArgumentException
                ? 'Refused: ' . $e->getMessage()
                : 'The database refused it: ' . ReadOnlySql::said($e);
        }

        fclose($handle);

        return [
            'link'      => Exports::url($name),
            'rows'      => $count,
            'truncated' => $count >= self::MAX_ROWS,
            'sample'    => $sample,
            'expires'   => gmdate('Y-m-d', time() + Exports::DAYS * 86400),
        ];
    }
}
