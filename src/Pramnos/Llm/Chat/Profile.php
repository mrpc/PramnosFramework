<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Llm\CallLog;

/**
 * What the assistant holds true about a person that should not change on its own word —
 * `chat_dossier`. On with `'llm' => ['chat' => ['profile' => true]]`: in every prompt of the
 * person's own conversations, with its tools in the `profile` skill.
 *
 * | Area | Changed | |
 * |---|---|---|
 * | `identity`, `values`, `goals` | on the person's confirmation | who they are, what they hold to, what they work towards |
 * | `relationships` | on the person's confirmation | one entry per person in their life, `subject` naming them: the relationship graph |
 * | `mood` | at once, only with consent | how they have felt lately — kept only once they agreed ({@see FEELINGS}) |
 *
 * Habits and preferences are not here: they are {@see \Pramnos\Llm\Chat\Memory}, learned and
 * corrected as the person talks. What is here changes by proposal: the model proposes, the
 * entry holds the proposal as `pending`, and only the person's yes, in a later message,
 * makes it true. A model cannot propose and confirm in the same answer. A wrong guess about who
 * somebody is never becomes a fact in every prompt after it.
 *
 * Every confirmed change raises the entry's `version`, and its before and after go to
 * `pramnos.changelog_events` ({@see history()}): the versions are in the log, the table holds what
 * is true now. So erasing a profile is two deletes, not one — {@see erase()} does both.
 */
final class Profile
{
    /** Areas that change only on the person's confirmation. */
    public const CONFIRMED = ['identity', 'values', 'goals', 'relationships'];

    /** Every area. */
    public const AREAS = [...self::CONFIRMED, 'mood'];

    /** The person's own setting that lets how they feel be kept. */
    public const FEELINGS = 'ai_profile_feelings';

    /** Entries kept per person, and characters per entry. */
    public const MAX   = 60;
    public const CHARS = 500;

    /**
     * What the prompt carries of it at most, in characters: who they are, what they value and work
     * towards first, the people in their life after. The rest is a `recall_person` away.
     */
    public const CONTEXT = 2000;

    /** The order the prompt reads the areas in, the most telling first. */
    private const ORDER = ['identity' => 0, 'values' => 1, 'goals' => 2, 'relationships' => 3];

    /** The changelog's entity. */
    public const ENTITY = 'chat_dossier';

    /** @var array<int, true> Entries proposed during this answer: not confirmable in it. */
    private array $proposedNow = [];

    public function __construct(private readonly Scope $scope)
    {
    }

    /** Whether the application turned it on, for this scope's space: a person's own conversations only. */
    public static function on(Scope $scope): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return $scope->space === Scope::CHAT && is_array($chat) && !empty($chat['profile']);
    }

    /**
     * Whether an entry waits for the person's yes — the default — or is kept at once:
     * `'llm' => ['chat' => ['profile' => ['confirm' => false]]]`. Kept at once, every change is still
     * a version in the log, and the person still forgets or corrects it with a word.
     */
    public static function confirms(): bool
    {
        $profile = \Pramnos\Llm\Llm::config('chat', [])['profile'] ?? true;

        return !is_array($profile) || ($profile['confirm'] ?? true) !== false;
    }

    /** Whether the table is there. */
    public static function available(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('chat_dossier');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Erase the profiles of a person (`userid`) or of an organisation (`organization_id`), with
     * every earlier version of each entry in the event log — what a person said about their values
     * and the people close to them is not to outlive the erasure there either. Answers the
     * entries erased.
     */
    public static function erase(string $by, int $id, ?\Pramnos\Database\Database $database = null): int
    {
        if ($by !== 'userid' && $by !== 'organization_id') {
            throw new \InvalidArgumentException('A profile is erased by userid or organization_id, not ' . $by . '.');
        }
        $database ??= \Pramnos\Framework\Factory::getDatabase();
        $schema = $database->schema();
        if (!$schema->hasTable('chat_dossier')) {
            return 0;
        }

        $entries = [];
        $result  = $database->queryBuilder()->table('#PREFIX#chat_dossier')->select(['dossier_id'])->where($by, $id)->get();
        while ($result && ($row = $result->fetch()) !== null) {
            $entries[] = (string) $row['dossier_id'];
        }
        if ($entries === []) {
            return 0;
        }
        if ($schema->hasTable(ChangelogWriter::EVENTS_TABLE)) {
            $database->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)
                ->where('entity', self::ENTITY)->whereIn('itemid', $entries)->delete();
        }
        $database->queryBuilder()->table('#PREFIX#chat_dossier')->whereIn('dossier_id', $entries)->delete();

        return count($entries);
    }

    /** Whether this person agreed to how they feel being kept. */
    public function keepsFeelings(): bool
    {
        try {
            return (bool) (new \Pramnos\User\User($this->scope->userId))->getSetting(self::FEELINGS, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Propose an entry, or a change to one: `mood` is kept at once (with consent), the others wait
     * for the person's word. Answers what the model is told.
     */
    public function note(string $area, string $content, ?string $subject = null, ?int $replaces = null): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));
        $subject = $subject === null ? null : trim((string) preg_replace('/\s+/u', ' ', $subject));
        if (!in_array($area, self::AREAS, true)) {
            return 'Error: an area is one of ' . implode(', ', self::AREAS) . '.';
        }
        if ($content === '' || mb_strlen($content) > self::CHARS) {
            return 'Error: say it in 1 to ' . self::CHARS . ' characters.';
        }
        if ($area === 'relationships' && ($subject === null || $subject === '')) {
            return 'Error: a relationship names the person it is about, in subject.';
        }
        if ($area === 'mood' && !$this->keepsFeelings()) {
            return 'Not kept: this person has not agreed to how they feel being kept. Ask them, if it would help them; '
                . 'they turn it on in their settings.';
        }

        $entry = $replaces !== null ? $this->find($replaces) : $this->match($area, $subject);
        if ($replaces !== null && $entry === null) {
            return 'Error: there is no entry ' . $replaces . '.';
        }
        if ($entry === null && $this->count() >= self::MAX) {
            return 'Not kept: the profile is full (' . self::MAX . ' entries). Ask the person which to forget.';
        }

        $now = CallLog::instant(time());
        if ($entry === null) {
            $this->table()->insert($this->scope->columns() + [
                'area' => $area, 'subject' => $subject, 'content' => null, 'pending' => null, 'version' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $entry = $this->find((int) \Pramnos\Framework\Factory::getDatabase()->getInsertId());
        }
        $id = (int) $entry['dossier_id'];

        if ($area === 'mood' || !self::confirms()) {
            $this->change($entry, $content);

            return 'Kept: ' . $content;
        }

        $this->scoped($id)->update(['pending' => $content, 'updated_at' => $now]);
        $this->proposedNow[$id] = true;

        return 'Proposed as entry ' . $id . ', not kept yet. Ask the person whether it is right, in their words: "'
            . $content . '". When they say yes, in their next message, call confirm_profile with ' . $id . '; if not, reject_profile.';
    }

    /** The person said yes: the proposal becomes the entry. Answers what the model is told. */
    public function confirm(int $id): string
    {
        $entry = $this->find($id);
        if ($entry === null || $entry['pending'] === null) {
            return 'Error: entry ' . $id . ' has nothing waiting to be confirmed.';
        }
        if (isset($this->proposedNow[$id])) {
            return 'Not confirmed: it was proposed in this answer. The person confirms it in their own next message.';
        }
        $this->change($entry, (string) $entry['pending']);

        return 'Confirmed: ' . $entry['pending'];
    }

    /** The person said no: the proposal is dropped, and an entry that was only a proposal goes. */
    public function reject(int $id): string
    {
        $entry = $this->find($id);
        if ($entry === null || $entry['pending'] === null) {
            return 'Error: entry ' . $id . ' has nothing waiting to be confirmed.';
        }
        $entry['content'] === null
            ? $this->scoped($id)->delete()
            : $this->scoped($id)->update(['pending' => null, 'updated_at' => CallLog::instant(time())]);

        return 'Dropped.';
    }

    /** Forget an entry altogether; the log keeps that it was. */
    public function forget(int $id): bool
    {
        $entry = $this->find($id);
        if ($entry === null) {
            return false;
        }
        $this->scoped($id)->delete();
        ChangelogWriter::event(self::ENTITY, $id, 'forgotten', ['area' => $entry['area'], 'subject' => $entry['subject']], 0, null, $this->scope->userId);

        return true;
    }

    /**
     * This person's entries: what is true, and what waits for their word.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $rows = $this->scope->apply($this->table())->orderBy('area')->orderBy('dossier_id')->get();
        $out  = [];
        while (($row = $rows->fetch()) !== null) {
            $out[] = self::shape($row);
        }

        return $out;
    }

    /**
     * The people in this person's life: each relationship's subject and what it is.
     *
     * @return array<string, string> Name to what is known of them
     */
    public function people(): array
    {
        $out = [];
        foreach ($this->entries() as $entry) {
            if ($entry['area'] === 'relationships' && $entry['content'] !== null) {
                $out[(string) $entry['subject']] = (string) $entry['content'];
            }
        }

        return $out;
    }

    /**
     * An entry's versions, oldest first, from the changelog — once its spool has drained.
     *
     * @return list<array{version: int, from: string|null, to: string|null, at: string}>
     */
    public function history(int $id): array
    {
        if ($this->find($id) === null) {
            return [];
        }
        $out = [];
        foreach (\Pramnos\Changelog\ChangelogReader::history(self::ENTITY, $id, ['events'], 500) as $row) {
            if (($row['event'] ?? '') !== 'changed') {
                continue;
            }
            $details = (array) ($row['details'] ?? []);
            $out[]   = ['version' => (int) ($details['version'] ?? 0), 'from' => $details['from'] ?? null, 'to' => $details['to'] ?? null, 'at' => (string) ($row['created_at'] ?? '')];
        }
        // By version, not by time: two changes in one second have no order there.
        usort($out, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);

        return $out;
    }

    /**
     * What goes into the prompt's cached zone: what is true, and what waits for the person's word,
     * within {@see CONTEXT} characters. Not how they feel: that changes too often for a cached
     * zone, and is {@see moodContext()}'s. Empty with nothing.
     */
    public function context(): string
    {
        $true    = [];
        $waiting = [];
        $entries = array_values(array_filter($this->entries(), static fn (array $e): bool => $e['area'] !== 'mood'));
        usort($entries, static fn (array $a, array $b): int => [self::ORDER[$a['area']] ?? 9, $a['dossier_id']] <=> [self::ORDER[$b['area']] ?? 9, $b['dossier_id']]);
        $room = self::CONTEXT;
        $left = 0;
        foreach ($entries as $e) {
            $line = mb_strlen((string) ($e['content'] ?? '')) + mb_strlen((string) ($e['pending'] ?? '')) + 30;
            if ($room - $line < 0) {
                $left++;
                continue;
            }
            $room -= $line;
            $label = $e['area'] . ($e['subject'] !== null ? ': ' . $e['subject'] : '');
            if ($e['content'] !== null) {
                $true[] = '- [' . $e['dossier_id'] . ', ' . $label . '] ' . $e['content'];
            }
            if ($e['pending'] !== null) {
                $waiting[] = '- [' . $e['dossier_id'] . ', ' . $label . '] ' . $e['pending'];
            }
        }

        return trim(($true === [] ? '' : "What this person confirmed about themselves (id, area):\n" . implode("\n", $true))
            . ($waiting === [] ? '' : "\n\nProposed, not confirmed — not true until they say so (id, area):\n" . implode("\n", $waiting))
            . ($left === 0 ? '' : "\n\n" . $left . ' more entries are kept; recall_person finds the people among them by name.'));
    }

    /** How they have felt lately, for the part of the prompt that changes with every question. Empty with nothing. */
    public function moodContext(): string
    {
        foreach ($this->entries() as $e) {
            if ($e['area'] === 'mood' && $e['content'] !== null) {
                return 'How this person has felt lately: ' . $e['content'];
            }
        }

        return '';
    }

    /**
     * The model's tools: propose, confirm, reject, and recall a person. Each writes, so after
     * reading somebody else's words it waits for the person (`Client::HELD_WRITE`), and each
     * result is tagged `personal`, for a data rule that keeps it to one provider.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        if (!self::available()) {
            return [];
        }
        $id       = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
        $confirms = self::confirms();

        $tools = [
            'note_profile' => [
                'description' => ($confirms ? 'Propose' : 'Keep') . ' something lasting about the person: who they are (identity), what they hold to (values), what they work towards (goals), '
                    . 'a person in their life (relationships, with subject naming them), or how they feel lately (mood, kept at once and only with their consent). '
                    . ($confirms ? 'All but mood wait for the person to confirm. ' : 'Only what they said plainly; correct an entry with replaces. ')
                    . 'Habits and preferences go to remember, not here.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'area'     => ['type' => 'string', 'enum' => self::AREAS],
                    'content'  => ['type' => 'string'],
                    'subject'  => ['type' => 'string', 'description' => 'relationships: the person it is about.'],
                    'replaces' => ['type' => 'integer', 'description' => 'The id of the entry this corrects.'],
                ], 'required' => ['area', 'content']],
                'run'    => fn (array $a): string => $this->note((string) $a['area'], (string) $a['content'], isset($a['subject']) ? (string) $a['subject'] : null, isset($a['replaces']) ? (int) $a['replaces'] : null),
                'writes' => true,
                'tags'   => ['personal' => true],
            ],
            'confirm_profile' => [
                'description' => 'The person said yes to a proposed entry, in their own message: it becomes true.',
                'parameters'  => $id,
                'run'    => fn (array $a): string => $this->confirm((int) $a['id']),
                'writes' => true,
                'tags'   => ['personal' => true],
            ],
            'reject_profile' => [
                'description' => 'The person said no to a proposed entry: it is dropped.',
                'parameters'  => $id,
                'run'    => fn (array $a): string => $this->reject((int) $a['id']),
                'writes' => true,
                'tags'   => ['personal' => true],
            ],
            'recall_person' => [
                'description' => 'What is known about a person in this person\'s life, by name, or everybody when no name is given.',
                'parameters'  => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                'run'    => function (array $a): string {
                    $name   = mb_strtolower(trim((string) ($a['name'] ?? '')));
                    $people = array_filter($this->people(), static fn (string $what, string $who): bool => $name === '' || str_contains(mb_strtolower($who), $name), ARRAY_FILTER_USE_BOTH);

                    return $people === [] ? 'Nobody of that name is known.' : implode("\n", array_map(static fn (string $who, string $what): string => $who . ': ' . $what, array_keys($people), $people));
                },
                'tags'   => ['personal' => true],
            ],
        ];

        // Kept at once, there is nothing to confirm: two tools fewer in every request.
        return $confirms ? $tools : array_diff_key($tools, ['confirm_profile' => true, 'reject_profile' => true]);
    }

    /** Make `$content` the entry, a version on, and log the change. */
    private function change(array $entry, string $content): void
    {
        $version = (int) $entry['version'] + 1;
        $this->scoped((int) $entry['dossier_id'])->update(['content' => $content, 'pending' => null, 'version' => $version, 'updated_at' => CallLog::instant(time())]);
        ChangelogWriter::event(self::ENTITY, (int) $entry['dossier_id'], 'changed', [
            'area' => $entry['area'], 'subject' => $entry['subject'], 'version' => $version, 'from' => $entry['content'], 'to' => $content,
        ], 0, null, $this->scope->userId);
    }

    /** @return array<string, mixed>|null */
    private function find(int $id): ?array
    {
        $row = $this->scoped($id)->first();

        return $row && (int) $row->numRows > 0 ? self::shape($row->fields) : null;
    }

    /** The entry an area holds for one subject — a relationship's person — or for none. */
    private function match(string $area, ?string $subject): ?array
    {
        if (in_array($area, ['relationships'], true)) {
            foreach ($this->entries() as $entry) {
                if ($entry['area'] === $area && mb_strtolower((string) $entry['subject']) === mb_strtolower((string) $subject)) {
                    return $entry;
                }
            }

            return null;
        }
        // One entry per area otherwise would lose the second goal: only mood has one.
        if ($area !== 'mood') {
            return null;
        }
        foreach ($this->entries() as $entry) {
            if ($entry['area'] === 'mood') {
                return $entry;
            }
        }

        return null;
    }

    private function count(): int
    {
        return $this->scope->apply($this->table())->count();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'dossier_id' => (int) $row['dossier_id'],
            'area'       => (string) $row['area'],
            'subject'    => $row['subject'] === null ? null : (string) $row['subject'],
            'content'    => $row['content'] === null ? null : (string) $row['content'],
            'pending'    => $row['pending'] === null ? null : (string) $row['pending'],
            'version'    => (int) $row['version'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function scoped(int $id): \Pramnos\Database\QueryBuilder
    {
        return $this->scope->apply($this->table())->where('dossier_id', $id);
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_dossier');
    }
}
