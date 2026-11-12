<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

use Pramnos\Database\Expression;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Chat\People;
use Pramnos\Llm\Chat\Scope;

/**
 * The assistants' control centre at a glance ({@see Review}): how much each channel is used, and
 * by whom — the figures, the people, one person in full. Read only.
 *
 * A channel is a stored space (`admin` for the Ask, `chat` and `chat:<persona>` for the
 * companion's personas, any other the application has); `''` is every channel.
 */
final class Overview
{
    /** The most people the list gives. */
    public const PEOPLE = 200;

    /** How the list can be sorted. */
    public const SORTS = ['last', 'name', 'messages', 'cost'];

    /**
     * A channel's figures: people, conversations, messages and cost over today, 7 and 30 days; for
     * the companion's, first meetings, app subscribers, morning thoughts and diary pages too; and
     * the messages of each of the last 30 days, for a chart.
     *
     * @return array<string, mixed>
     */
    public function stats(string $channel = '', ?int $now = null): array
    {
        $now   = $now ?? time();
        $since = ['today' => strtotime('today', $now), '7' => $now - 7 * 86400, '30' => $now - 30 * 86400];
        $out   = ['people' => [], 'new' => 0, 'conversations' => [], 'messages' => [], 'cost' => []];
        foreach ($since as $key => $from) {
            $conversations = $this->conversations($channel)->where('updated_at', '>=', CallLog::instant($from));
            $out['people'][$key]        = count(array_unique(array_column($conversations->select(['userid'])->getAllOrFail(), 'userid')));
            $out['conversations'][$key] = $this->conversations($channel)->where('created_at', '>=', CallLog::instant($from))->count();
            [$messages, $cost]          = $this->messages($channel, $from);
            $out['messages'][$key]      = $messages;
            $out['cost'][$key]          = $cost;
        }
        $out['people']['all'] = count(array_unique(array_column($this->conversations($channel)->select(['userid'])->getAllOrFail(), 'userid')));
        // New: whose first conversation here is in the last 30 days.
        $firsts = $this->conversations($channel)->select(['userid', new Expression('MIN(created_at) AS first')])->groupBy('userid')->getAllOrFail();
        $out['new'] = count(array_filter($firsts, static fn (array $r): bool => (\Pramnos\Llm\Chat\Routines::utc((string) $r['first']) ?? 0) >= $since['30']));
        if ($channel === '' || People::companion($channel)) {
            $out['companion'] = [
                'subscribers' => $this->count('pramnos.pushsubscriptions', static fn ($q) => $q->where('app', Scope::CHAT), 'userid'),
                'thoughts'    => $this->artifacts($channel, \Pramnos\Llm\Chat\Today::THOUGHT, $since['30']),
                'diaries'     => $this->artifacts($channel, \Pramnos\Llm\Chat\Diary::TITLE, $since['30']),
                'moods'       => $this->moods($channel, $since['30']),
            ];
        }
        $out['daily'] = $this->daily($channel, $now);

        return $out;
    }

    /**
     * The people who talked to the assistants, on a channel or every one: each with their chosen
     * persona, last activity, conversations and messages by channel, memories and cost.
     *
     * @return list<array<string, mixed>>
     */
    public function people(string $channel = '', string $search = '', string $sort = 'last'): array
    {
        $query = $this->conversations($channel)->select(['conversation_id', 'userid', 'space', 'updated_at']);
        if (trim($search) !== '') {
            $like  = '%' . addcslashes(trim($search), '%_\\') . '%';
            $found = array_map('intval', array_column($this->db()->queryBuilder()->table('#PREFIX#users')->select(['userid'])
                ->where(static function ($q) use ($like): void {
                    $q->whereLike('username', $like)->orWhereLike('email', $like)->orWhereLike('firstname', $like)->orWhereLike('lastname', $like);
                })->limit(500)->getAllOrFail(), 'userid'));
            if ($found === []) {
                return [];
            }
            $query->whereIn('userid', $found);
        }
        $people = [];
        $owner  = [];
        foreach ($query->getAllOrFail() as $row) {
            $id    = (int) $row['userid'];
            $space = (string) $row['space'];
            $owner[(int) $row['conversation_id']] = [$id, $space];
            $people[$id] ??= ['userid' => $id, 'last' => '', 'channels' => [], 'conversations' => 0, 'messages' => 0, 'cost' => 0.0, 'memories' => 0];
            $people[$id]['channels'][$space] ??= ['conversations' => 0, 'messages' => 0];
            $people[$id]['channels'][$space]['conversations']++;
            $people[$id]['conversations']++;
            $people[$id]['last'] = max($people[$id]['last'], (string) $row['updated_at']);
        }
        if ($people === []) {
            return [];
        }
        foreach (array_chunk(array_keys($owner), 1000) as $ids) {
            foreach ($this->db()->queryBuilder()->table('assist.chat_messages')->select(['conversation_id', new Expression('COUNT(*) AS n'), new Expression('SUM(cost) AS cost')])
                ->whereIn('conversation_id', $ids)->groupBy('conversation_id')->getAllOrFail() as $row) {
                [$id, $space] = $owner[(int) $row['conversation_id']];
                $people[$id]['channels'][$space]['messages'] += (int) $row['n'];
                $people[$id]['messages'] += (int) $row['n'];
                $people[$id]['cost']     += (float) ($row['cost'] ?? 0);
            }
        }
        foreach ($this->db()->queryBuilder()->table('assist.chat_memory')->select(['userid', new Expression('COUNT(*) AS n')])
            ->whereIn('userid', array_keys($people))->groupBy('userid')->getAllOrFail() as $row) {
            $people[(int) $row['userid']]['memories'] = (int) $row['n'];
        }
        $names = \Pramnos\Conversations\People::names(array_keys($people));
        $personas = \Pramnos\Llm\Chat\Personas::on();
        foreach ($people as $id => $one) {
            $people[$id]['name']    = $names[$id] ?? '#' . $id;
            $people[$id]['persona'] = $personas ? \Pramnos\Llm\Chat\Personas::chosen(new \Pramnos\User\User($id)) : null;
        }
        $list = array_values($people);
        usort($list, match (in_array($sort, self::SORTS, true) ? $sort : 'last') {
            'name'     => static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
            'messages' => static fn (array $a, array $b): int => $b['messages'] <=> $a['messages'],
            'cost'     => static fn (array $a, array $b): int => $b['cost'] <=> $a['cost'],
            default    => static fn (array $a, array $b): int => strcmp($b['last'], $a['last']),
        });

        return array_slice($list, 0, self::PEOPLE);
    }

    /**
     * One person's conversations, by channel, newest first.
     *
     * @return array<string, list<array{conversation_id: int, title: string, updated_at: string}>>
     */
    public function conversationsOf(int $userId): array
    {
        $out = [];
        foreach ($this->db()->queryBuilder()->table('assist.chat_conversations')->select(['conversation_id', 'space', 'title', 'updated_at'])
            ->where('userid', $userId)->orderBy('updated_at', 'desc')->limit(500)->getAllOrFail() as $row) {
            $out[(string) $row['space']][] = ['conversation_id' => (int) $row['conversation_id'], 'title' => (string) $row['title'], 'updated_at' => (string) $row['updated_at']];
        }

        return $out;
    }

    /**
     * One person's memories, by channel and then topic.
     *
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public function memoriesOf(int $userId): array
    {
        $out = [];
        foreach (array_keys($this->conversationsOf($userId)) as $space) {
            foreach ((new \Pramnos\Llm\Chat\Memory(new Scope($userId, null, $space)))->all() as $memory) {
                $out[$space][$memory['topic'] ?? 'other'][] = $memory;
            }
        }

        return $out;
    }

    /**
     * Everything else the assistant holds for one person, by section — routines (reminders and
     * timers among them), calendar, artifacts made and shared with them, shares, questions under
     * way, dossier and skills — each a list of rows, the newest first, at most 100. A section whose
     * table this installation does not have is left out, and so is an empty one.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function holdingsOf(int $userId): array
    {
        $q    = fn (string $table) => $this->db()->queryBuilder()->table($table);
        $from = [
            'routines'  => fn () => $q('pramnos.ai_routines')->select(['routine_id', 'kind', 'title', 'space', 'cadence', 'at', 'next_run_at', 'last_run_at', 'is_active', 'last_error', 'conversation_id'])
                ->where('userid', $userId)->orderBy('routine_id', 'desc'),
            'calendar'  => fn () => $q('assist.chat_calendar')->select(['item_id', 'kind', 'title', 'space', 'starts_at', 'due_at', 'done'])
                ->where('userid', $userId)->orderBy('item_id', 'desc'),
            'artifacts' => fn () => $q('assist.chat_artifacts')->select(['artifact_id', 'kind', 'title', 'space', 'conversation_id', new Expression("'owner' AS role")])
                ->where('userid', $userId)->orderBy('artifact_id', 'desc'),
            'shared'    => fn () => $q(\Pramnos\Llm\Chat\Artifacts::MEMBERS . ' AS m')->join('assist.chat_artifacts AS a', 'a.artifact_id', '=', 'm.artifact_id')
                ->select(['a.artifact_id', 'a.kind', 'a.title', 'a.space', 'm.role'])->where('m.userid', $userId)->orderBy('m.member_id', 'desc'),
            'shares'    => fn () => $q('assist.chat_shares')->select(['share_id', 'kind', 'title', 'space', 'recipient', 'created_at'])
                ->where('userid', $userId)->orderBy('share_id', 'desc'),
            'asks'      => fn () => $q('assist.chat_asks')->select(['ask_id', 'status', 'conversation_id'])
                ->where('userid', $userId)->orderBy('ask_id', 'desc'),
            'dossier'   => fn () => $q('assist.chat_dossier')->select(['dossier_id', 'space', 'area', 'subject', 'content', 'pending'])
                ->where('userid', $userId)->orderBy('dossier_id', 'desc'),
            'skills'    => fn () => $q(\Pramnos\Llm\Chat\StoredSkills::TABLE)->select(['skill_id', 'name', 'description', 'status', 'updated_at'])
                ->where('userid', $userId)->orderBy('skill_id', 'desc'),
        ];
        $out = [];
        foreach ($from as $section => $query) {
            try {
                $rows = $query()->limit(100)->getAllOrFail();
            } catch (\Throwable) {
                continue; // A feature this installation has not migrated.
            }
            if ($rows !== []) {
                $out[$section] = $rows;
            }
        }

        return $out;
    }

    /** The conversations of a channel, or of every one. */
    private function conversations(string $channel): \Pramnos\Database\QueryBuilder
    {
        $query = $this->db()->queryBuilder()->table('assist.chat_conversations');

        return $channel === '' ? $query : $query->where('space', $channel);
    }

    /** @return array{0: int, 1: float} The messages since a moment, and what they cost */
    private function messages(string $channel, int $from): array
    {
        $query = $this->db()->queryBuilder()->table('assist.chat_messages AS m')->select([new Expression('COUNT(*) AS n'), new Expression('SUM(m.cost) AS cost')])
            ->where('m.created_at', '>=', CallLog::instant($from));
        if ($channel !== '') {
            $query->join('assist.chat_conversations AS c', 'c.conversation_id', '=', 'm.conversation_id')->where('c.space', $channel);
        }
        $row = $query->getAllOrFail()[0] ?? [];

        return [(int) ($row['n'] ?? 0), (float) ($row['cost'] ?? 0)];
    }

    /** @return array{labels: list<string>, values: list<int>} Messages on each of the last 30 days */
    private function daily(string $channel, int $now): array
    {
        // The 30 days up to and including today, on the page's clock — its days, and each message's.
        $day  = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0)->modify('-29 days');
        $from = $day->getTimestamp();
        $days = [];
        for ($i = 0; $i < 30; $i++) {
            $days[$day->format('Y-m-d')] = 0;
            $day = $day->modify('+1 day');
        }
        $query = $this->db()->queryBuilder()->table('assist.chat_messages AS m')->select(['m.created_at'])->where('m.created_at', '>=', CallLog::instant($from));
        if ($channel !== '') {
            $query->join('assist.chat_conversations AS c', 'c.conversation_id', '=', 'm.conversation_id')->where('c.space', $channel);
        }
        // ponytail: counted in PHP, one row per message — a GROUP BY on a day expression when a channel reaches millions a month.
        foreach ($query->getAllOrFail() as $row) {
            $at  = \Pramnos\Llm\Chat\Routines::utc((string) $row['created_at']);
            $key = $at === null ? '' : date('Y-m-d', $at);
            if (isset($days[$key])) {
                $days[$key]++;
            }
        }

        return ['labels' => array_keys($days), 'values' => array_values($days)];
    }

    /** Artifacts titled "<prefix> Y-m-d" of a channel since a moment: diaries, thoughts. */
    private function artifacts(string $channel, string $prefix, int $from): int
    {
        try {
            $query = $this->db()->queryBuilder()->table('assist.chat_artifacts')->whereLike('title', $prefix . ' %')->where('created_at', '>=', CallLog::instant($from));

            return ($channel === '' ? $query->whereLike('space', Scope::CHAT . '%') : $query->where('space', $channel))->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, int> The moods the conversations were left in, how many of each */
    private function moods(string $channel, int $from): array
    {
        try {
            $query = $this->conversations($channel)->select(['mood', new Expression('COUNT(*) AS n')])->whereNotNull('mood')
                ->where('updated_at', '>=', CallLog::instant($from))->groupBy('mood');
            $out = array_column($query->getAllOrFail(), 'n', 'mood');
            arsort($out);

            return array_map('intval', $out);
        } catch (\Throwable) {
            return [];
        }
    }

    /** How many distinct values of a column a table has, narrowed. */
    private function count(string $table, callable $narrow, string $column): int
    {
        try {
            $query = $this->db()->queryBuilder()->table($table)->select([$column]);
            $narrow($query);

            return count(array_unique(array_column($query->getAllOrFail(), $column)));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
