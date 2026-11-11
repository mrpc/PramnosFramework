<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The day's cards, shown above the assistant on a companion's page: `GET {chat}/today`.
 *
 * Each card is `{kind, title, text, action}`, `action` what opening it does (`{type, id}`):
 *
 * - **thought** — a line from the assistant for the morning, written once a day with the cheapest
 *   model, the first time the day's cards are asked for, and kept as a text artifact `Thought
 *   Y-m-d` of the persona's. Only with `'chat' => ['today' => ['thought' => true]]`: it costs a call.
 * - **event** — the day's appointments ({@see Calendar}).
 * - **reminder** — the tasks due today or overdue ({@see Calendar}), and the person's reminders
 *   ({@see Routines}) that go off today.
 * - **question** — what the assistant noted and waits for the person's word on ({@see Profile}).
 * - **diary** — the assistant's diary pages the person has not read ({@see Diary}).
 *
 * Each source counts only where the application has it on.
 */
final class Today
{
    /** A thought's title, before its day. */
    public const THOUGHT = 'Thought';

    /** The most of each kind of card. */
    public const MOST = 5;

    /** Whether the application has a morning thought written. */
    public static function thinks(): bool
    {
        $today = ((array) \Pramnos\Llm\Llm::config('chat', []))['today'] ?? null;

        return is_array($today) && !empty($today['thought']);
    }

    /**
     * What the model is told to write as the morning's thought.
     */
    public static function prompt(string $name, string $person = ''): string
    {
        return 'You are ' . $name . '. Write one or two warm sentences to ' . ($person !== '' ? $person : 'the person')
            . ' for the start of their day, from what you know of them below: a thought, a small encouragement, '
            . 'or a gentle question — never a list, never advice they did not ask for, never anything you do not know. '
            . 'Write in the language of what you know of them; Greek where there is nothing.';
    }

    /**
     * The day's cards, the thought first.
     *
     * @param callable(Scope, string): ?string $think Writes the day's thought: {@see Conversations::thought()}
     * @param Routines|null $routines The application's own, where it has one
     * @return list<array{kind: string, title: string, text: string, action: array<string, mixed>|null}>
     */
    public static function cards(Scope $scope, object $user, string $day, string $timezone, ?callable $think = null, ?Routines $routines = null): array
    {
        $cards = [];
        if ($think !== null && self::thinks() && Artifacts::available()) {
            $thought = $think($scope, $day);
            if ($thought !== null && $thought !== '') {
                $cards[] = ['kind' => 'thought', 'title' => '', 'text' => $thought, 'action' => ['type' => 'chat']];
            }
        }
        if (Calendar::on($scope) && Calendar::available()) {
            $calendar = new Calendar($scope, $timezone);
            foreach (array_slice($calendar->events($day, $day), 0, self::MOST) as $event) {
                $cards[] = ['kind' => 'event', 'title' => (string) $event['title'],
                    'text' => $event['all_day'] ? '' : substr((string) $event['start'], 11) . ($event['end'] ? '–' . substr((string) $event['end'], 11) : ''),
                    'action' => ['type' => 'event', 'id' => (int) $event['id']]];
            }
            $due = array_filter($calendar->tasks(), static fn (array $task): bool => $task['due'] !== null && substr((string) $task['due'], 0, 10) <= $day);
            foreach (array_slice(array_values($due), 0, self::MOST) as $task) {
                $cards[] = ['kind' => 'reminder', 'title' => (string) $task['title'], 'text' => substr((string) $task['due'], 0, 10) < $day
                    ? substr((string) $task['due'], 0, 10) : substr((string) $task['due'], 11), 'action' => ['type' => 'task', 'id' => (int) $task['id']]];
            }
        }
        $cards = [...$cards, ...self::reminders($routines ?? new Routines(new Conversations(new BaseAssistant())), $scope, $day, $timezone)];
        if (Profile::on($scope) && Profile::available()) {
            $waiting = array_filter((new Profile($scope))->entries(), static fn (array $entry): bool => $entry['pending'] !== null && $entry['pending'] !== '');
            foreach (array_slice(array_values($waiting), 0, self::MOST) as $entry) {
                $cards[] = ['kind' => 'question', 'title' => (string) ($entry['subject'] ?? $entry['area']), 'text' => (string) $entry['pending'],
                    'action' => ['type' => 'profile', 'id' => (int) $entry['dossier_id']]];
            }
        }
        if (Diary::on($scope) && Artifacts::available()) {
            $unread = array_filter((new Diary($scope))->pages($user, 20), static fn (array $page): bool => $page['unread']);
            foreach (array_slice(array_values($unread), 0, self::MOST) as $page) {
                $cards[] = ['kind' => 'diary', 'title' => Diary::TITLE . ' ' . $page['day'], 'text' => '',
                    'action' => ['type' => 'diary', 'id' => $page['artifact_id']]];
            }
        }

        return $cards;
    }

    /**
     * The person's reminders that go off today, on their clock.
     *
     * @return list<array{kind: string, title: string, text: string, action: array<string, mixed>}>
     */
    private static function reminders(Routines $routines, Scope $scope, string $day, string $timezone): array
    {
        $out  = [];
        $zone = new \DateTimeZone($timezone);
        try {
            $mine = $routines->mine($scope);
        } catch (\Throwable) {
            return [];  // No routines table: the application has none.
        }
        foreach ($mine as $routine) {
            if ($routine['kind'] !== Routines::REMINDER || !$routine['active'] || $routine['next_run_at'] === null) {
                continue;
            }
            $next = Routines::utc($routine['next_run_at']);
            $at   = (new \DateTimeImmutable('@' . (int) $next))->setTimezone($zone);
            if ($next !== null && $at->format('Y-m-d') === $day && count($out) < self::MOST) {
                $out[] = ['kind' => 'reminder', 'title' => $routine['title'], 'text' => $at->format('H:i'),
                    'action' => ['type' => 'routine', 'id' => $routine['routine_id']]];
            }
        }

        return $out;
    }
}
