<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The assistant's own diary: a page each evening about the day it spent with the person, from its
 * own point of view — beside the person's journal ({@see Journal}), on the same footing.
 *
 * ```php
 * 'llm' => ['chat' => ['journal' => ['diary' => true]]],               // or ['diary' => ['at' => '22:00']]
 * ```
 *
 * - **A journal page of a second kind**: a text artifact titled `Diary Y-m-d`, so it is kept, found,
 *   pinned and exported as any artifact is. It is the persona's ({@see Scope::$persona}), not the
 *   person's: each character keeps its own diary.
 * - **Written in the evening by `llm:routines`**, from the day's conversations, with the cheapest
 *   model that will do ({@see \Pramnos\Llm\Client::askCheaply()}): only for a day there was talk,
 *   never from a work conversation ({@see Work}), once a day.
 * - **Remembered**: the latest page, if it is from the last two days, is in what the assistant knows
 *   when it answers.
 * - **Read by the person** through `GET {chat}/diary` (the pages, and how many are unread) and
 *   `GET {chat}/diary/{id}` (one, which marks it read).
 */
final class Diary
{
    /** A page's title, before its day. */
    public const TITLE = 'Diary';

    /** When the day's page is written, by default, on the person's clock. */
    public const AT = '21:30';

    /** The most of the day's conversations a page is written from, in characters. */
    public const READS = 8000;

    /** The person's last read page, kept with their account; one for each persona. */
    public const READ_DETAIL = 'askdiary';

    public function __construct(private readonly Scope $scope)
    {
    }

    /** Whether the application keeps a diary, for this scope's space: the conversation page only. */
    public static function on(?Scope $scope = null): bool
    {
        $journal = ((array) \Pramnos\Llm\Llm::config('chat', []))['journal'] ?? null;

        return is_array($journal) && !empty($journal['diary']) && ($scope === null || $scope->space === Scope::CHAT);
    }

    /** When the day's page is written, `H:i` on the person's clock. */
    public static function at(): string
    {
        $diary = (((array) \Pramnos\Llm\Llm::config('chat', []))['journal'] ?? [])['diary'] ?? null;
        $at    = is_array($diary) ? (string) ($diary['at'] ?? '') : '';

        return preg_match('/^\d{1,2}:\d{2}$/', $at) === 1 ? $at : self::AT;
    }

    /**
     * What the model is told to write, as the character it is: about the person in the third
     * person, by their name — a diary is not a letter to them — and with the application's own
     * rules (`diaryRules()` of its assistant, a name spelled its way, say).
     */
    public static function prompt(string $name, string $person = '', string $rules = ''): string
    {
        return 'You are ' . $name . ', and you keep a diary. Write tonight\'s page about your day with '
            . ($person !== '' ? $person : 'this person') . ', from your own point of view: what you talked about, what you noticed '
            . 'about them, how it felt to you. You write in the first person, and of them in the third' . ($person !== '' ? ', as ' . $person : '')
            . ' — never "you": a diary is not a letter to them. Warm and honest, four to eight sentences, no heading, no date. '
            . 'Only what happened in the conversations below — never invent anything. Write in the language the conversations were in.'
            . (trim($rules) !== '' ? "\n" . trim($rules) : '');
    }

    /** The day's page, if it has been written: the artifact, or null. */
    public function page(string $day): ?array
    {
        foreach ((new Artifacts($this->scope))->search(['kind' => 'text', 'query' => self::TITLE . ' ' . $day]) as $found) {
            if ($found['title'] === self::TITLE . ' ' . $day) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Keep the day's page.
     *
     * @return array<string, mixed>|string The artifact, or why not
     */
    public function keep(string $day, string $text): array|string
    {
        return (new Artifacts($this->scope))->create(['kind' => 'text', 'title' => self::TITLE . ' ' . $day, 'text' => trim($text)]);
    }

    /**
     * The pages, newest first, each with whether the person has read it.
     *
     * @return list<array{artifact_id: int, day: string, unread: bool}>
     */
    public function pages(object $user, int $limit = 60): array
    {
        $read = (int) ($user->{$this->readDetail()} ?? 0);
        $out  = [];
        foreach ((new Artifacts($this->scope))->list(500) as $one) {
            if ($one['kind'] === 'text' && preg_match('/^' . self::TITLE . ' (\d{4}-\d\d-\d\d)$/', $one['title'], $m) === 1) {
                $out[] = ['artifact_id' => (int) $one['artifact_id'], 'day' => $m[1], 'unread' => (int) $one['artifact_id'] > $read];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * One page, read: its words, and the person's mark moved past it. Null for one not the persona's.
     *
     * @return array{artifact_id: int, day: string, text: string}|null
     */
    public function read(object $user, int $artifactId): ?array
    {
        $found = (new Artifacts($this->scope))->find($artifactId);
        if ($found === null || preg_match('/^' . self::TITLE . ' (\d{4}-\d\d-\d\d)$/', (string) $found['title'], $m) !== 1) {
            return null;
        }
        if ($artifactId > (int) ($user->{$this->readDetail()} ?? 0)) {
            $user->{$this->readDetail()} = (string) $artifactId;
            $user->save();
        }

        return ['artifact_id' => $artifactId, 'day' => $m[1], 'text' => (string) ($found['content']['text'] ?? '')];
    }

    /**
     * What the assistant remembers of its diary when it answers: the latest page, if it is from the
     * last two days; '' otherwise.
     */
    public function recall(string $today): string
    {
        foreach ([0, 1] as $back) {
            $day  = (new \DateTimeImmutable($today))->modify('-' . $back . ' day')->format('Y-m-d');
            $page = $this->page($day);
            if ($page !== null) {
                $found = (new Artifacts($this->scope))->find((int) $page['artifact_id']);
                $text  = trim((string) ($found['content']['text'] ?? ''));
                if ($text !== '') {
                    return 'Your own diary, ' . $day . ' (you wrote it; keep to it):' . "\n" . mb_substr($text, 0, 1200);
                }
            }
        }

        return '';
    }

    /** The detail the last read page is kept in: the persona's own. */
    private function readDetail(): string
    {
        return $this->scope->persona === null ? self::READ_DETAIL : self::READ_DETAIL . '-' . $this->scope->persona;
    }
}
