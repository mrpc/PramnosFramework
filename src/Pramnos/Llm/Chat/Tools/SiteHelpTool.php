<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Tools;

use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Pramnos\Assist\HelpReader;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Mcp\McpToolInterface;

/**
 * The site's help pages, for the application's own assistant — what a customer asks "how do I"
 * of, rather than the administration's analyst.
 *
 * ```php
 * public function tools(Turn $turn): array
 * {
 *     return ['help' => SiteHelpTool::tool($turn->scope, fn (string $slug): string => '/app/help?p=' . $slug)];
 * }
 * ```
 *
 * One page per call — the best for a question, or the one named — cut to fit one tool result,
 * with the names of the next best to ask for. Read as the scope's person and organisation, so a
 * page or a block their plan does not include is not there (see {@see \Pramnos\Assist\HelpGate}).
 */
final class SiteHelpTool implements McpToolInterface
{
    /**
     * @param \Closure(string): string|null $link The address of a page, by its name; the site's `/Help` by default
     * @param int                           $chars The most of a page's text one answer carries
     */
    public function __construct(
        private readonly Scope $scope,
        private readonly ?\Closure $link = null,
        private readonly int $chars = 18000
    ) {
    }

    /**
     * The tool as an assistant's `tools()` lists it.
     *
     * @param \Closure(string): string|null $link
     * @return array{description: string, parameters: array<string, mixed>, run: \Closure}
     */
    public static function tool(Scope $scope, ?\Closure $link = null, int $chars = 18000): array
    {
        return \Pramnos\Llm\Tools::fromMcp([new self($scope, $link, $chars)])['help'];
    }

    public function name(): string
    {
        return 'help';
    }

    public function description(): string
    {
        return 'The help pages of this application: how to do something here, and how a screen works. Give a '
            . 'question for the page that answers it best, or a page name from an earlier answer to read that '
            . 'one. One page comes back, with its link and the names of other pages that may answer; quote '
            . 'the link so the person can open the page.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'question' => ['type' => 'string', 'description' => 'What the person wants to know.'],
            'page'     => ['type' => 'string', 'description' => 'A page name, such as editorial, from an earlier answer.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $book = Help::site()->for(HelpReader::fromScope($this->scope));
        $name = trim((string) ($input['page'] ?? ''));

        if ($name !== '') {
            $page = $book->page($name);

            return $page === null ? 'There is no help page called ' . $name . '.' : $this->answer($book, $page, []);
        }

        $found = $book->search((string) ($input['question'] ?? ''), 4);

        return $found === [] ? 'No help page answers that.' : $this->answer($book, $found[0], array_slice($found, 1));
    }

    /**
     * One page, whole as far as it fits, and the others by name.
     *
     * @param array<string, mixed>       $page
     * @param list<array<string, mixed>> $others
     * @return array<string, mixed>
     */
    private function answer(HelpBook $book, array $page, array $others): array
    {
        $slug   = (string) $page['slug'];
        $answer = [
            'page'  => $slug,
            'title' => (string) $page['title'],
            'link'  => $this->link !== null ? ($this->link)($slug) : $book->url($slug),
            'text'  => Help::excerpt((string) $page['body'], $this->chars),
        ];
        if ($others !== []) {
            $answer['others'] = array_map(static fn (array $p): array => ['page' => $p['slug'], 'title' => $p['title']], $others);
        }

        // Encoded, a newline or a quote takes two characters: shrink the text until the whole
        // answer fits in one tool result, rather than have the client cut it mid-sentence.
        $limit = \Pramnos\Llm\Client::RESULT_LIMIT;
        while (($over = mb_strlen(\Pramnos\Llm\Tools::text($answer)) - $limit) > 0) {
            $answer['text'] = Help::excerpt((string) $page['body'], max(0, mb_strlen($answer['text']) - $over - 16));
        }

        return $answer;
    }
}
