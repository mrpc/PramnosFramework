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
     * The people named, as `name => userid`: by username or email, never the person sharing. A
     * name that is nobody is left out, and reported as such.
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
            ->select(['userid', 'username', 'email'])
            ->where(static fn ($q) => $q->whereIn('username', $names)->whereIn('email', $names, 'or'))
            ->get();
        while (($row = $result->fetch()) !== null) {
            if ((int) $row['userid'] === $by->userId) {
                continue;
            }
            foreach ([(string) $row['username'], (string) $row['email']] as $name) {
                if (in_array($name, $names, true)) {
                    $found[$name] = (int) $row['userid'];
                }
            }
        }

        return $found;
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
