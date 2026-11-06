<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Assist\Help;
use Pramnos\Mcp\McpToolInterface;

/**
 * The installation's help pages, for a question about how to do something here rather than
 * about its figures — answered by section, not by page.
 *
 * A question brings the section that answers it best, whole, with its link to the heading, and
 * the next few by their path («Page › Section») to read if it does not. A page and a section
 * name read that section; a page alone gives its opening and the list of its sections; `map`
 * gives every page and section by title only. So an answer is the part of a page that answers,
 * however long the page is, and costs what that part costs.
 *
 * What it was asked is kept with each answer, among the tools it called; {@see lookedUp()} reads
 * one call back as the help answers it now.
 */
final class HelpTool implements McpToolInterface
{
    /** How many more sections a question names, after the one it brings whole. */
    public const ALSO = 3;

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
            . 'a question to get the section that answers it best, whole, and the paths of the next '
            . 'best; give a page and a section from those to read another one; give a page alone for '
            . 'its sections; set map for every page and section by title. Each section comes with its '
            . 'link; quote the link so the administrator can open it.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'question' => ['type' => 'string', 'description' => 'What the administrator wants to know.'],
            'page'     => ['type' => 'string', 'description' => 'A page name, such as users/edit, from an earlier answer.'],
            'section'  => ['type' => 'string', 'description' => 'A section of that page, by the anchor or heading an earlier answer gave.'],
            'map'      => ['type' => 'boolean', 'description' => 'Every page and section by title, to see what there is.'],
            'topic'    => ['type' => 'string', 'description' => 'Only the pages of this group, for a question or the map.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $book  = Help::admin();
        $topic = trim((string) ($input['topic'] ?? ''));

        if (!empty($input['map'])) {
            $map = $book->map($topic);

            return $map === [] ? 'There are no help pages' . ($topic !== '' ? ' in ' . $topic : '') . '.' : ['map' => $map];
        }

        $name = trim((string) ($input['page'] ?? ''));
        if ($name !== '') {
            return $this->page($name, trim((string) ($input['section'] ?? '')));
        }

        $found = $book->searchSections((string) ($input['question'] ?? ''), 1 + self::ALSO, $topic);
        if ($found === []) {
            return 'No help page answers that.';
        }

        $answer = ['best' => $this->whole($found[0])];
        if (count($found) > 1) {
            $answer['also'] = array_map(fn (array $s): array => $this->named($s), array_slice($found, 1));
        }

        return $answer;
    }

    /** A page's section, or — without one — its opening and the list of its sections. */
    private function page(string $name, string $section): mixed
    {
        $book = Help::admin();
        $page = $book->page($name);
        if ($page === null) {
            return 'There is no help page called ' . $name . '.';
        }

        $slug  = (string) $page['slug'];
        $parts = array_values(array_filter($book->sections(), static fn (array $s): bool => $s['page'] === $slug));
        if ($section !== '') {
            $found = $book->section($slug, $section);
            if ($found !== null) {
                return $this->whole($found);
            }
        }

        $opening = $parts !== [] && $parts[0]['heading'] === '' ? array_shift($parts) : null;

        return ($section !== '' ? ['note' => 'The page has no section called ' . $section . '.'] : [])
            + ($opening !== null ? $this->whole($opening) : ['page' => $slug, 'title' => $page['title'], 'link' => Help::url($slug, $this->area)])
            + ['sections' => array_map(fn (array $s): array => $this->named($s), $parts)];
    }

    /**
     * What one call of this tool looked up, as the help answers it now: for a question, the best
     * section's `path` and `score` (`found` 0 when nothing answers); for a page and a section, the
     * section `read`; `map` for the map. The calls are kept with each answer (`chat_messages.tools`),
     * so the conversation screen and `help:gaps` show what the help gave without a log of their own.
     *
     * @param array<string, mixed> $arguments The call's arguments, as stored
     * @return array{kind: string, found: int, best: string, path: string, score: float}
     */
    public static function lookedUp(array $arguments): array
    {
        $book = Help::admin();
        if (!empty($arguments['map'])) {
            return ['kind' => 'map', 'found' => 0, 'best' => '', 'path' => '', 'score' => 0.0];
        }
        $page = trim((string) ($arguments['page'] ?? ''));
        if ($page !== '') {
            $section = trim((string) ($arguments['section'] ?? ''));
            $found   = $section !== '' ? $book->section($page, $section) : null;
            $where   = Help::slug($page) . ($found !== null && $found['anchor'] !== '' ? '#' . $found['anchor'] : '');

            return ['kind' => 'read', 'found' => $found !== null ? 1 : 0, 'best' => $where, 'path' => (string) ($found['path'] ?? $page), 'score' => 0.0];
        }
        $found = $book->searchSections((string) ($arguments['question'] ?? ''), 1, trim((string) ($arguments['topic'] ?? '')));
        if ($found === []) {
            return ['kind' => 'question', 'found' => 0, 'best' => '', 'path' => '', 'score' => 0.0];
        }

        return [
            'kind'  => 'question',
            'found' => 1,
            'best'  => $found[0]['page'] . ($found[0]['anchor'] !== '' ? '#' . $found[0]['anchor'] : ''),
            'path'  => (string) $found[0]['path'],
            'score' => (float) $found[0]['score'],
        ];
    }

    /** @param array<string, mixed> $section @return array<string, mixed> A section with its text */
    private function whole(array $section): array
    {
        return $this->named($section) + ['text' => Help::excerpt((string) $section['text'])];
    }

    /** @param array<string, mixed> $section @return array<string, mixed> A section by its path and link */
    private function named(array $section): array
    {
        $link = Help::url((string) $section['page'], $this->area) . ($section['anchor'] !== '' ? '#' . $section['anchor'] : '');

        return ['page' => $section['page'], 'section' => $section['anchor'], 'path' => $section['path'], 'link' => $link];
    }
}
