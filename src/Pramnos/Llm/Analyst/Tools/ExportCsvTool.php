<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Exports;
use Pramnos\Llm\Analyst\Limits;
use Pramnos\Llm\Analyst\ReadOnlySql;
use Pramnos\Mcp\McpToolInterface;

/**
 * The same SELECT as `sql`, written to a CSV file the administrator downloads — up to
 * {@see MAX_ROWS} rows, or `'llm' => ['analyst' => ['export_rows' => …]]` (a number, or
 * `fn(User): int` for a per-role ceiling — {@see Limits}). A short list — up to `'llm' =>
 * ['analyst' => ['export_inline' => 100]]` rows — comes back whole as well, to be shown as a
 * table in the answer beside the link; a longer one reaches the model as the link, the count
 * and five rows to describe it by.
 *
 * With a UTF-8 byte-order mark, so a spreadsheet reads Greek as Greek. Off unless the application
 * turns it on: `'llm' => ['analyst' => ['export' => true]]`.
 */
final class ExportCsvTool implements McpToolInterface
{
    public const MAX_ROWS = 100000;

    /** Rows shown in the answer itself, unless the application says otherwise. */
    public const INLINE = 100;

    /** @param string $area The area's URL as the asking request saw it: where the link goes */
    public function __construct(private readonly int $adminId, private readonly string $area = '')
    {
    }

    public function name(): string
    {
        return 'export_csv';
    }

    public function description(): string
    {
        return 'Write the rows of one read-only SELECT to a CSV file the administrator downloads, when '
            . 'they ask for a list or a file rather than an answer — up to ' . self::maxRows($this->adminId) . ' rows. '
            . 'You get the link, the row count and five sample rows. Give the link as a Markdown link. '
            . 'The file is theirs alone and is kept ' . Exports::DAYS . ' days. Use it for more rows than an artifact holds '
            . '(' . \Pramnos\Llm\Chat\Artifacts::ROWS . '), or rows whose columns come from the database, written in one SELECT '
            . 'without retyping them; otherwise save_artifact. Never both for the same rows.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['sql'], 'properties' => [
            'sql'   => ['type' => 'string', 'description' => 'One SELECT statement, with real table names.'],
            'title' => ['type' => 'string', 'description' => 'A few words for the file name, such as "active members".'],
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

        $name   = Exports::name($this->adminId, (string) ($input['title'] ?? ''));
        $inline = self::inline();
        $max    = self::maxRows($this->adminId);
        $rows   = [];
        $file   = $directory . '/' . $name . '.csv';
        $handle = fopen($file, 'w');
        $sample = [];
        fwrite($handle, "\xEF\xBB\xBF");

        try {
            $count = ReadOnlySql::each(Factory::getDatabase(), (string) ($input['sql'] ?? ''), $max, static function (array $row) use ($handle, &$sample, &$rows, $inline): void {
                if ($sample === []) {
                    fputcsv($handle, array_keys($row), ',', '"', '');
                }
                fputcsv($handle, array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $row), ',', '"', '');
                if (count($sample) < 5) {
                    $sample[] = $row;
                }
                if (count($rows) <= $inline) {
                    $rows[] = $row;
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

        $answer = [
            'link'      => Exports::url($name, $this->area),
            // The same rows as a spreadsheet, for whoever asks for Excel.
            'xlsx'      => Exports::url($name, $this->area) . '?as=xlsx',
            'rows'      => $count,
            'truncated' => $count >= $max,
            'expires'   => gmdate('Y-m-d', time() + Exports::DAYS * 86400),
        ];

        // Short enough to read: all of it, to show as a table, and the file as well.
        return $count <= $inline
            ? $answer + ['rows_inline' => $rows, 'show' => 'Show every row as a Markdown table in the answer, then give the link to the file.']
            : $answer + ['sample' => $sample];
    }

    /** How many rows are shown in the answer itself. */
    private static function inline(): int
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);

        return max(0, (int) (is_array($config) && isset($config['export_inline']) ? $config['export_inline'] : self::INLINE));
    }

    /** The row ceiling: `export_rows`, or {@see MAX_ROWS}. */
    private static function maxRows(int $adminId): int
    {
        return max(1, (int) Limits::of('export_rows', self::MAX_ROWS, $adminId));
    }
}
