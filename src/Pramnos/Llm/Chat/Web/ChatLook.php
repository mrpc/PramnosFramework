<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

/**
 * What the conversations page takes from the application to look like its own: the accent
 * colour, and the `/` commands its composer offers.
 *
 * Set in `'llm' => ['chat' => [...]]` beside `name`, `suggestions` and `waiting_lines`:
 *
 * ```php
 * 'chat' => [
 *     'name'        => 'Naya',
 *     'greeting'    => 'Hi, I am Naya. What shall we look at?',
 *     'placeholder' => 'Ask Naya…',
 *     'accent'      => '#7c3aed',
 *     'commands'    => [
 *         ['name' => 'week', 'says' => 'This week in numbers', 'text' => 'How did this week go, against last week?'],
 *         ['name' => 'help', 'says' => 'How Naya works', 'url' => '/help/naya'],
 *     ],
 * ],
 * ```
 */
final class ChatLook
{
    /**
     * A colour as CSS takes it — `#7c3aed`, `rgb(124 58 237)`, `oklch(…)`, `rebeccapurple` — or
     * empty. Anything else is empty: the value lands in a `style` attribute, where `;` or `:`
     * would add rules of its own.
     */
    public static function accent(string $colour): string
    {
        $colour = trim($colour);

        return preg_match('/^[#a-zA-Z0-9(),.%\s\/-]{1,64}$/', $colour) === 1 ? $colour : '';
    }

    /**
     * The commands typed after `/` in the composer: the page's own (a new conversation, the
     * scheduled tasks where the page has them, attaching a document, what it remembers), then the
     * application's.
     *
     * An application's command has a `name` (letters, digits, `-`, `_`), what it `says` in the
     * menu, and either `text`, put into the composer to send or change, or `url`, gone to. One
     * without either, or with a name already taken, is left out.
     *
     * @param array<string, string>            $urls The page's addresses: `page`, `scheduled`
     * @param array<int, array<string, mixed>> $own  The application's
     * @return list<array{name: string, says: string, url?: string, text?: string, action?: string}>
     */
    public static function commands(array $urls, array $own = []): array
    {
        $commands = [['name' => 'new', 'says' => t('Start a new conversation'), 'url' => (string) ($urls['page'] ?? '')]];
        if (($urls['scheduled'] ?? '') !== '') {
            $commands[] = ['name' => 'scheduled', 'says' => t('Your scheduled tasks'), 'url' => (string) $urls['scheduled']];
        }
        $commands[] = ['name' => 'attach', 'says' => t('Attach a document'), 'action' => 'attach'];
        $commands[] = ['name' => 'memory', 'says' => t('What it remembers'), 'action' => 'memory'];

        $taken = array_column($commands, 'name');
        foreach ($own as $one) {
            $name = is_array($one) ? (string) ($one['name'] ?? '') : '';
            $does = array_filter(['text' => $one['text'] ?? null, 'url' => $one['url'] ?? null], static fn ($v): bool => is_string($v) && $v !== '');
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/i', $name) !== 1 || $does === [] || in_array($name, $taken, true)) {
                continue;
            }
            $commands[] = ['name' => $name, 'says' => (string) ($one['says'] ?? '')] + array_slice($does, 0, 1, true);
            $taken[]    = $name;
        }

        return $commands;
    }
}
