<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Assist\Help;
use Pramnos\Mcp\McpToolInterface;

/**
 * The installation's help pages, for a question about how to do something here rather than
 * about its figures: the pages that answer it, each with its address in the administration.
 */
final class HelpTool implements McpToolInterface
{
    /** @param string $area The area's URL as the asking request saw it: where the links go */
    public function __construct(private readonly string $area = '')
    {
    }

    public function name(): string
    {
        return 'help';
    }

    public function description(): string
    {
        return 'This installation\'s help pages: how a screen works and how to do something here. Give '
            . 'a question to find the pages that answer it, or a page name to read one. Each page '
            . 'comes with its link; quote the link so the administrator can open the page.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'question' => ['type' => 'string', 'description' => 'What the administrator wants to know.'],
            'page'     => ['type' => 'string', 'description' => 'A page name, such as users/edit, from an earlier answer.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $name = trim((string) ($input['page'] ?? ''));

        if ($name !== '') {
            $page = Help::page($name);

            return $page === null ? 'There is no help page called ' . $name . '.' : $this->shown($page, true);
        }

        $found = Help::search((string) ($input['question'] ?? ''), 3);

        if ($found === []) {
            return 'No help page answers that.';
        }

        // The best page in full; the others by name, to read if it does not answer.
        return ['pages' => array_map(fn (array $page, int $at): array => $this->shown($page, $at === 0), $found, array_keys($found))];
    }

    /** @param array<string, mixed> $page @return array<string, mixed> */
    private function shown(array $page, bool $whole): array
    {
        return ['page' => $page['slug'], 'title' => $page['title'], 'link' => Help::url((string) $page['slug'], $this->area)]
            + ($whole ? ['text' => Help::excerpt((string) $page['body'])] : ['questions' => $page['questions']]);
    }
}
