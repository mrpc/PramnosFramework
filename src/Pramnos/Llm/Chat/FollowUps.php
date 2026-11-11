<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A check a few hours later — "how is the X going?" — when the person says what they are doing
 * now. On with `'llm' => ['chat' => ['follow_up' => true]]` (or `['daily' => 3]`), in a person's
 * own conversations.
 *
 * `follow_up(about, in_hours)` schedules one question, a one-off routine
 * ({@see Routines}) titled `Follow-up: <about>`:
 *
 * - **one at a time for a thing**: a second for the same thing is refused, saying when the first is;
 * - **none in the person's quiet hours** ({@see Settings}): one that would fall in them is asked
 *   when they end;
 * - **a few a day** at most ({@see DAILY}, or the application's `daily`);
 * - **forgotten when the person speaks of it first**: a question whose words name it takes the
 *   pending check away ({@see heard()});
 * - **asked once**: a check that gets no answer is not asked again — it is a one-off.
 */
final class FollowUps
{
    /** A follow-up's title, before what it is about. */
    public const TITLE = 'Follow-up: ';

    /** How many a day, unless the application says. */
    public const DAILY = 3;

    /** How many hours ahead at most. */
    public const MAX_HOURS = 12;

    public function __construct(private readonly Routines $routines, private readonly Scope $scope, private readonly string $timezone = 'UTC')
    {
    }

    /** Whether the application turned it on, for a person's own conversations. */
    public static function on(Scope $scope): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return $scope->space === Scope::CHAT && $scope->userId > 0 && is_array($chat) && !empty($chat['follow_up']);
    }

    /** How many a day the application allows. */
    public static function daily(): int
    {
        $given = ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['follow_up'] ?? []))['daily'] ?? null;

        return is_numeric($given) && (int) $given > 0 ? (int) $given : self::DAILY;
    }

    /**
     * `follow_up`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return ['follow_up' => [
            'description' => 'When the person says what they are doing now ("I\'m doing the accounts and then the shopping"), '
                . 'schedule one check a few hours later to ask how it went. One for a thing; never for something they did '
                . 'not say they are doing. Do not tell them at length: a word that you will ask later is enough.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'about'    => ['type' => 'string', 'description' => 'The thing, in a few of their words: "the accounts".'],
                'in_hours' => ['type' => 'integer', 'description' => 'Hours from now, 1 to ' . self::MAX_HOURS . '; 2 or 3 usually.'],
            ], 'required' => ['about', 'in_hours']],
            'run'         => fn (array $a): string => $this->schedule((string) ($a['about'] ?? ''), (int) ($a['in_hours'] ?? 0), time()),
            'writes'      => true,
            'tags'        => ['personal' => true],
        ]];
    }

    /** One check scheduled, or why not. */
    public function schedule(string $about, int $hours, int $now): string
    {
        $about = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $about)), 0, 100);
        if ($about === '') {
            return 'Error: say what to ask about.';
        }
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            return 'Error: in_hours is 1 to ' . self::MAX_HOURS . '.';
        }
        $zone = new \DateTimeZone($this->timezone);
        // Not in the person's quiet hours: then, when they end.
        $at    = (new \DateTimeImmutable('@' . ($now + $hours * 3600)))->setTimezone($zone);
        $quiet = Settings::of(new \Pramnos\User\User($this->scope->userId), $this->scope->persona)['quiet'];
        if (Settings::quietAt($quiet, $at->format('H:i'))) {
            [$h, $m] = array_map('intval', explode(':', (string) $quiet['to']));
            $end     = $at->setTime($h, $m);
            $at      = $end <= $at ? $end->modify('+1 day') : $end;
        }
        $sameDay = 0;
        foreach ($this->mine() as $one) {
            if ($one['active'] && $one['next_run_at'] !== null && mb_strtolower($one['title']) === mb_strtolower(self::TITLE . $about)) {
                return 'Error: a check on ' . $about . ' is already set, for ' . $this->local($one['next_run_at']) . '. One is enough.';
            }
            // A few a day: counted by the day each is asked on.
            $sameDay += $one['run_at'] !== null && $this->day((string) $one['run_at']) === $at->format('Y-m-d') ? 1 : 0;
        }
        if ($sameDay >= self::daily()) {
            return 'Error: ' . self::daily() . ' checks a day are enough; this one is not set. Do not mention it.';
        }
        $done = $this->routines->create($this->scope, [
            'title'    => self::TITLE . $about,
            'prompt'   => 'Earlier I said I was doing this: ' . $about . '. Ask me, in one short question, how it went — '
                . 'nothing more, no list, no advice unless I ask.',
            'cadence'  => 'once',
            'run_at'   => $at->format('Y-m-d H:i'),
            'timezone' => $this->timezone,
        ]);

        return is_string($done) ? 'Error: ' . $done : 'I will ask about ' . $about . ' at ' . $at->format('H:i') . '.';
    }

    /**
     * The person spoke of something a check waits on: that check taken away, so they are not asked
     * what they already said. A check is named when every longer word of what it is about is in the
     * question, by its first five letters — "accounts" for "the accounts", "μαθηματικών" for
     * "μαθηματικά".
     *
     * @return int How many were taken away
     */
    public function heard(string $question): int
    {
        // ponytail: matching by word stems; a check on "the car" said as "the Toyota" stays — a model's judgment if that matters.
        $said  = mb_strtolower($question);
        $taken = 0;
        foreach ($this->mine() as $one) {
            if (!$one['active'] || $one['next_run_at'] === null) {
                continue;
            }
            $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(mb_substr($one['title'], mb_strlen(self::TITLE)))) ?: [], static fn (string $w): bool => mb_strlen($w) >= 4);
            if ($words !== [] && array_filter($words, static fn (string $w): bool => !str_contains($said, mb_substr($w, 0, 5))) === []) {
                $taken += $this->routines->delete($this->scope, (int) $one['routine_id']) ? 1 : 0;
            }
        }

        return $taken;
    }

    /**
     * This person's follow-ups, pending or done.
     *
     * @return list<array<string, mixed>>
     */
    private function mine(): array
    {
        return array_values(array_filter($this->routines->mine($this->scope), static fn (array $r): bool => str_starts_with((string) $r['title'], self::TITLE)));
    }

    /** A stored moment's day on the person's clock. */
    private function day(string $stored): string
    {
        $at = Routines::utc($stored);

        return $at === null ? '' : (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
    }

    /** A stored moment on the person's clock, `H:i`. */
    private function local(string $stored): string
    {
        $at = Routines::utc($stored);

        return $at === null ? $stored : (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->timezone))->format('H:i');
    }
}
