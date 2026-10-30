<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A journal a day, kept by the assistant in the person's words — on with
 * `'llm' => ['chat' => ['journal' => true]]`, in the `journal` skill.
 *
 * `journal` writes a line in today's page: a text artifact titled `Journal Y-m-d` on the person's
 * clock, made on the day's first entry and added to after it, tagged `personal`. Being an
 * artifact, a page is read, found and pinned with the artifact tools, and its history is theirs.
 *
 * {@see TEMPLATES} offers an evening routine that asks how the day went and writes it down.
 */
final class Journal
{
    /**
     * The routine it offers beside the application's own `routine_templates`, which override it
     * by key: the day's review, in the evening.
     */
    public const TEMPLATES = [
        'evening_review' => [
            'title'   => 'The day\'s review',
            'prompt'  => 'The day is ending. Ask me briefly what I got done and how I feel, so we can close the day in my journal. '
                . 'If today\'s plan is pinned, unpin it.',
            'cadence' => 'daily',
            'at'      => '21:30',
        ],
    ];

    /** A page's title, before the day it is for. */
    public const TITLE = 'Journal';

    public function __construct(private readonly Scope $scope, private readonly string $timezone = 'UTC')
    {
    }

    /** Whether the application turned it on, for this scope's space: a person's own conversations only. */
    public static function on(Scope $scope): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return $scope->space === Scope::CHAT && is_array($chat) && !empty($chat['journal']);
    }

    /**
     * `journal`, where artifacts can be kept.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        if (!Artifacts::available()) {
            return [];
        }

        return ['journal' => [
            'description' => 'Write in the person\'s journal for today: what they got done, what they thought, what they learned. '
                . 'In their words where you can, one entry at a time.',
            'parameters'  => ['type' => 'object', 'properties' => ['entry' => ['type' => 'string']], 'required' => ['entry']],
            'run'         => function (array $a): string {
                $entry = trim((string) ($a['entry'] ?? ''));
                if ($entry === '') {
                    return 'Error: there is nothing to write.';
                }
                $artifacts = new Artifacts($this->scope);
                $now       = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
                $title     = self::TITLE . ' ' . $now->format('Y-m-d');
                $line      = '- ' . $now->format('H:i') . ' ' . $entry;
                foreach ($artifacts->search(['kind' => 'text', 'query' => $title]) as $found) {
                    if ($found['title'] === $title) {
                        $done = $artifacts->update((int) $found['artifact_id'], 'append', ['text' => $line]);

                        return is_string($done) ? 'Error: ' . $done : 'Written in ' . $title . '.';
                    }
                }
                $made = $artifacts->create(['kind' => 'text', 'title' => $title, 'text' => $line]);

                return is_string($made) ? 'Error: ' . $made : 'Written in ' . $title . ', a new page.';
            },
            'writes'      => true,
            'tags'        => ['personal' => true],
        ]];
    }
}
