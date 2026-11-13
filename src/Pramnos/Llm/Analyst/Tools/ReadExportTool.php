<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Llm\Analyst\Exports;
use Pramnos\Mcp\McpToolInterface;

/**
 * One of this administrator's own `export_csv` files, read back as text — while it still
 * exists ({@see Exports::days()}) — so a past report's actual rows can be compared against a
 * newer one, rather than only the narrative text a scheduled task's run kept.
 */
final class ReadExportTool implements McpToolInterface
{
    /** The most read back in one call — large enough for a typical export, bounded all the same. */
    public const MAX_BYTES = 200_000;

    public function __construct(private readonly int $adminId)
    {
    }

    public function name(): string
    {
        return 'read_export';
    }

    public function description(): string
    {
        return 'The rows of one of this administrator\'s own export_csv files, read back as text — give the link '
            . 'export_csv or a past report\'s answer gave (the full URL, or just its name). Exact rows for comparing '
            . 'one export against another, not the narrative text around it. Error once the file is gone, kept '
            . Exports::days() . ' days unless the application keeps them longer.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['link'], 'properties' => [
            'link' => ['type' => 'string', 'description' => 'The export\'s link, or its bare name.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $name = basename((string) (parse_url((string) ($input['link'] ?? ''), PHP_URL_PATH) ?: ''));
        $file = $name !== '' ? Exports::path($name, $this->adminId) : null;
        if ($file === null) {
            return 'Error: that export is gone, not theirs, or the link is not one.';
        }

        $bytes     = (string) file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        $truncated = strlen($bytes) > self::MAX_BYTES;
        $text      = ltrim(substr($bytes, 0, self::MAX_BYTES), "\xEF\xBB\xBF");

        return $truncated
            ? $text . "\n… (truncated at " . number_format(self::MAX_BYTES) . ' bytes — the link downloads the rest)'
            : $text;
    }
}
