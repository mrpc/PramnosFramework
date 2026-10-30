<?php

declare(strict_types=1);

namespace Pramnos\Llm\Companion;

use Pramnos\Llm\Chat\Scope;

/**
 * The personal-companion layer of a person's own conversations, on with
 * `'llm' => ['chat' => ['companion' => true]]`: what they confirmed about themselves and the
 * people in their life ({@see Dossier}), in every prompt, and the tools to propose and confirm it;
 * their appointments and tasks ({@see Calendar}); a journal a day; `set_busy`; and the morning
 * and evening routines of {@see TEMPLATES}. When it reaches out on its own is
 * {@see \Pramnos\Llm\Policy\Proactivity}'s to say.
 *
 * Off by default: an assistant for work has no business keeping who somebody's brother is. Never
 * in an answer others read too, nor in the administrators' analyst.
 */
final class Companion
{
    /**
     * Routines the companion offers beside the application's own `routine_templates`, which
     * override them by key: the morning plan and the evening review of the day.
     */
    public const TEMPLATES = [
        'companion_morning' => [
            'title'   => 'Good morning',
            'prompt'  => 'Plan my day: read today\'s appointments and my open tasks, keep the plan as today\'s artifact, '
                . 'and greet me with what the day holds, in two or three sentences.',
            'cadence' => 'daily',
            'at'      => '08:00',
        ],
        'companion_evening' => [
            'title'   => 'The day\'s review',
            'prompt'  => 'The day is ending. Ask me briefly what I got done and how I feel, so we can close the day in my journal.',
            'cadence' => 'daily',
            'at'      => '21:30',
        ],
    ];

    /** The journal's title, before the day it is for. */
    public const JOURNAL = 'Journal';

    private ?Dossier $dossier = null;

    private ?Calendar $calendar = null;

    /** @param string $timezone The person's clock, for their calendar */
    public function __construct(private readonly Scope $scope, private readonly string $timezone = 'UTC')
    {
    }

    /** Whether the application turned it on, for this scope's space. */
    public static function on(Scope $scope): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return $scope->space === Scope::CHAT && is_array($chat) && !empty($chat['companion']);
    }

    /** The person's profile. */
    public function dossier(): Dossier
    {
        return $this->dossier ??= new Dossier($this->scope);
    }

    /** The person's appointments and tasks. */
    public function calendar(): Calendar
    {
        return $this->calendar ??= new Calendar($this->scope, $this->timezone);
    }

    /**
     * The companion's tools, one set per answer: a proposal made in it is known to it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->dossier()->tools() + $this->calendar()->tools() + $this->busyTool() + $this->journalTool();
    }

    /**
     * Send the reminders of appointments starting within {@see Calendar::REMIND} minutes, each
     * once, as {@see \Pramnos\Llm\Policy\Proactivity} allows — `llm:routines` calls it every
     * minute. Answers how many went.
     *
     * @param (callable(int, object): void)|null $deliver A seam: tell a person, by id
     */
    public static function remind(int $now, ?callable $deliver = null): int
    {
        $sent = 0;
        foreach (Calendar::startingSoon($now, Calendar::REMIND) as $row) {
            $scope = new Scope((int) $row['userid'], $row['organization_id'] === null ? null : (int) $row['organization_id'], (string) $row['space']);
            $id    = (int) $row['item_id'];
            try {
                $zone = \Pramnos\Llm\Chat\Chat::assistant()->timezone($scope);
                $go   = self::on($scope) && \Pramnos\Llm\Llm::proactivity()->when($scope->userId, $zone, $now) === $now;
                Calendar::alerted($id, $go);
                if (!$go) {
                    continue;
                }
                $starts  = (new \DateTimeImmutable((string) $row['starts_at'], new \DateTimeZone('UTC')))->getTimestamp();
                $minutes = max(1, (int) round(($starts - $now) / 60));
                $words   = 'In ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ': ' . $row['title'] . '.';
                $notice  = new \Pramnos\Llm\Chat\TaskReminder($words, '');
                $deliver !== null ? $deliver($scope->userId, $notice) : (new \Pramnos\User\User($scope->userId))->notify($notice);
                $sent++;
            } catch (\Throwable $why) {
                // One reminder that cannot go does not stop the others.
                \Pramnos\Logs\Logger::log('companion reminder ' . $id . ': ' . $why->getMessage(), 'llm');
            }
        }

        return $sent;
    }

    /**
     * `set_busy`: the person says they are busy until a time, or no longer — no ping meanwhile.
     *
     * @return array<string, array<string, mixed>>
     */
    private function busyTool(): array
    {
        return ['set_busy' => [
            'description' => 'The person says they are busy — in a meeting, driving, resting — until a time; or, with no time, that they no longer are. '
                . 'Nothing is sent to them on the assistant\'s own initiative meanwhile.',
            'parameters'  => ['type' => 'object', 'properties' => ['until' => ['type' => 'string', 'description' => 'Y-m-d H:i, on the person\'s clock (' . $this->timezone . ').']]],
            'run'         => function (array $a): string {
                $until = trim((string) ($a['until'] ?? ''));
                $at    = $until === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $until, new \DateTimeZone($this->timezone));
                if ($until !== '' && $at === false) {
                    return 'Error: a time is Y-m-d H:i.';
                }
                (new \Pramnos\User\User($this->scope->userId))->setSetting(\Pramnos\Llm\Policy\QuietProactivity::BUSY, $at === false ? '0' : (string) $at->getTimestamp());

                return $at === false ? 'No longer busy.' : 'Busy until ' . $until . '.';
            },
            'writes'      => true,
        ]];
    }

    /**
     * `journal`: a line in today's journal — a text artifact a day, `Journal Y-m-d` on the
     * person's clock — made on the day's first entry, added to after it. Tagged `personal`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function journalTool(): array
    {
        if (!\Pramnos\Llm\Chat\Artifacts::available()) {
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
                $artifacts = new \Pramnos\Llm\Chat\Artifacts($this->scope);
                $title     = self::JOURNAL . ' ' . (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
                $line      = '- ' . (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('H:i') . ' ' . $entry;
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

    /** What the companion adds to the prompt's second zone. Empty with nothing. */
    public function context(): string
    {
        return Dossier::available() ? $this->dossier()->context() : '';
    }
}
