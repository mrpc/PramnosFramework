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

        $question = (string) ($input['question'] ?? '');
        $found    = $book->searchSections($question, 1 + self::ALSO, $topic);
        self::note(['question' => $question, 'found' => count($found)] + ($found === [] ? [] : [
            'best'  => $found[0]['page'] . ($found[0]['anchor'] !== '' ? '#' . $found[0]['anchor'] : ''),
            'score' => $found[0]['score'],
        ]));
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
                // Asked for by name: what a search did not bring.
                self::note(['read' => $slug . ($found['anchor'] !== '' ? '#' . $found['anchor'] : '')]);

                return $this->whole($found);
            }
        }

        $opening = $parts !== [] && $parts[0]['heading'] === '' ? array_shift($parts) : null;

        return ($section !== '' ? ['note' => 'The page has no section called ' . $section . '.'] : [])
            + ($opening !== null ? $this->whole($opening) : ['page' => $slug, 'title' => $page['title'], 'link' => Help::url($slug, $this->area)])
            + ['sections' => array_map(fn (array $s): array => $this->named($s), $parts)];
    }

    /**
     * What the help was asked, for `help:gaps`: a line in the `help` log per search — the
     * question, how many sections answered, the best and its score — and per section read by
     * name. Off with `'help' => ['log' => false]`. Never in the way of the answer.
     *
     * @param array<string, mixed> $what
     */
    private static function note(array $what): void
    {
        $app = \Pramnos\Application\Application::currentInstance();
        if (is_object($app) && ($app->applicationInfo['help']['log'] ?? true) === false) {
            return;
        }
        try {
            \Pramnos\Logs\Logger::log(isset($what['read']) ? 'help read' : 'help search', 'help', 'log', false, $what);
        } catch (\Throwable) {
            // A log that cannot be written is no reason to keep the answer back.
        }
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
