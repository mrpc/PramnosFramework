<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The rules around sharing a conversation or an artifact (`'llm' => ['chat' => ['share_policy' =>
 * …]]`). Every method has a default, so an application overrides only what it has.
 *
 * By default anybody signed in may share, with anybody they name by username or email: the person
 * sharing chooses, and answers for what they show — the conversation holds what was read with
 * their rights. An application that must keep data within a role or an organisation narrows
 * {@see recipients()}.
 */
class SharePolicy
{
    /** The policy the application configured, or this one. */
    public static function current(): self
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) ? ($chat['share_policy'] ?? null) : null;

        return is_string($class) && is_a($class, self::class, true) ? new $class() : new self();
    }

    /** Why this person may not share at all, or null when they may. */
    public function refusal(Scope $by): ?string
    {
        return null;
    }

    /**
     * The people named, as `name => userid`, in the order the names were given: by username or
     * email, never the person sharing. A name that is nobody is left out, and reported as such.
     *
     * @param list<string> $names
     * @return array<string, int>
     */
    public function recipients(Scope $by, array $names): array
    {
        $names = array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $names), static fn (string $n): bool => $n !== ''));
        if ($names === []) {
            return [];
        }
        $found  = [];
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->select(['userid', 'username', 'email', 'usertype'])
            ->where(static fn ($q) => $q->whereIn('username', $names)->whereIn('email', $names, 'or'))
            ->get();
        // The analyst's conversations go only to people who may open them: one below its floor
        // would be told a conversation exists, and its title, and could not read it.
        $floor = $by->space === Scope::ADMIN ? TaskPolicy::analystFloor() : 0;
        while (($row = $result->fetch()) !== null) {
            if ((int) $row['userid'] === $by->userId || (int) ($row['usertype'] ?? 0) < $floor) {
                continue;
            }
            foreach ([(string) $row['username'], (string) $row['email']] as $name) {
                if (in_array($name, $names, true)) {
                    $found[$name] = (int) $row['userid'];
                }
            }
        }

        // In the order the names were given, not the order the database read the rows in — which
        // PostgreSQL changes once a row is updated.
        $ordered = [];
        foreach ($names as $name) {
            if (isset($found[$name])) {
                $ordered[$name] = $found[$name];
            }
        }

        return $ordered;
    }

    /**
     * The people offered as the sharer types a name — asked for through the API as it is typed
     * (`GET /chat/people?q=`): in the analyst's space, the active accounts at or above its floor
     * whose username, email or name holds `$term`, never the sharer, the first `$limit` by
     * username. Two characters at least; fewer is nobody. A person's own Ask offers nobody: there
     * the accounts are the site's users, and a list of them is not one user's to read. An
     * application whose own Ask is among colleagues overrides it.
     *
     * @return list<array{value: string, label: string}>
     */
    public function candidates(Scope $by, string $term, int $limit = 10): array
    {
        $term = trim($term);
        if ($by->space !== Scope::ADMIN || mb_strlen($term) < 2) {
            return [];
        }
        $like   = '%' . addcslashes($term, '%_\\') . '%';
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->select(['userid', 'username', 'firstname', 'lastname'])
            ->where('usertype', '>=', TaskPolicy::analystFloor())
            ->where('active', 1)
            ->where('userid', '!=', $by->userId)
            ->where(static fn ($q) => $q->whereLike('username', $like)->orWhereLike('email', $like)
                ->orWhereLike('firstname', $like)->orWhereLike('lastname', $like))
            ->orderBy('username')
            ->limit(max(1, min(50, $limit)))
            ->get();
        $people = [];
        while (($row = $result->fetch()) !== null) {
            $name     = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
            $people[] = ['value' => (string) $row['username'], 'label' => $name !== '' ? $name : (string) $row['username']];
        }

        return $people;
    }

    /**
     * Where the recipient's notification leads: the page that opens the share. The analyst's
     * conversations open in the administration area, as the analyst does.
     *
     * @param array<string, mixed> $share As {@see Shares::withMe()} answers
     */
    public function link(array $share): string
    {
        return ($share['space'] ?? Scope::CHAT) === Scope::ADMIN
            ? \Pramnos\Llm\Analyst\AnalystAssistant::areaUrl('Ai/shared/' . (int) $share['share_id'])
            : \Pramnos\Http\SiteUrl::to('chat/shared/' . (int) $share['share_id']);
    }
}
