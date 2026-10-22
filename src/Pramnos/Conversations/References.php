<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * What a `#type:id` in a message is: a card the application draws for whoever reads it — a user,
 * an invoice, a job post — with what that reader may see.
 *
 * ```php
 * References::register('user', function (string $id, object $viewer): ?array {
 *     $user = Users::find((int) $id);
 *     return $user === null ? null : [
 *         'title'  => $user->fullName(),
 *         'url'    => adminUrl('Users/view/') . $user->userid,
 *         'text'   => $user->roleLabel(),
 *         'fields' => ['Συνδρομή' => $user->planLabel(), 'Πληρωμές 30 ημ.' => $user->paidLabel()],
 *         'color'  => 'info',
 *     ];
 * });
 * ```
 *
 * A resolver answers with a card — `title`, `url`, `text` (a line under the title: "Γονέας · Αθήνα"),
 * `fields`, `color`, `image` (an avatar, a thumbnail), as a message's — or null for nothing to show,
 * which is what a reader who may not see it gets: the text stays as written, never an error. One
 * that throws shows nothing and is logged. A type nobody registered stays the text it was. Besides
 * `#type:id`, `match()` makes other text a reference — a Jira key, an address.
 */
final class References
{
    /** The most references read at once. */
    public const MAX = 50;

    /** @var array<string, callable(string, object): ?array<string, mixed>> */
    private static array $resolvers = [];

    /** @var list<array{type: string, pattern: string}> What else in a text is a reference */
    private static array $patterns = [];

    /**
     * How a type of reference becomes a card.
     *
     * @param callable(string, object): ?array<string, mixed> $resolve The id, the reader — a card or null
     */
    public static function register(string $type, callable $resolve): void
    {
        self::$resolvers[strtolower($type)] = $resolve;
    }

    /**
     * What else in a message is a reference of a type, besides `#type:id`: a Jira key, an address
     * of the administration. The pattern's first group is the id.
     *
     * ```php
     * References::match('jira', '/\\b(NAN-\\d+)\\b/');
     * References::match('jira', '#https://[a-z0-9-]+\\.atlassian\\.net/browse/([A-Z]+-\\d+)#');
     * References::match('user', '#/admin/users/view/(\\d+)\\b#i');
     * ```
     */
    public static function match(string $type, string $pattern): void
    {
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('Not a pattern: ' . $pattern);
        }
        self::$patterns[] = ['type' => strtolower($type), 'pattern' => $pattern];
    }

    /**
     * The references a text holds by the application's patterns, as `type:id`, each once.
     *
     * @return list<string>
     */
    public static function find(string $text): array
    {
        $found = [];
        foreach (self::$patterns as $match) {
            if (preg_match_all($match['pattern'], $text, $m) > 0) {
                foreach ($m[1] ?? [] as $id) {
                    $found[] = $match['type'] . ':' . $id;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** The types the application draws. @return list<string> */
    public static function types(): array
    {
        return array_keys(self::$resolvers);
    }

    /**
     * The cards of these references, for this reader: `user:48211` => a card, or null.
     *
     * @param list<string> $keys
     * @return array<string, array<string, mixed>|null>
     */
    public static function resolve(array $keys, object $viewer): array
    {
        $cards = [];
        foreach (array_slice(array_values(array_unique(array_map('strval', $keys))), 0, self::MAX) as $key) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
            $resolve     = self::$resolvers[strtolower($type)] ?? null;
            if ($resolve === null || $id === '') {
                continue;
            }
            try {
                $card = $resolve($id, $viewer);
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('Conversations: the reference ' . $key . ' could not be drawn: ' . $e->getMessage(), 'conversations');
                $card = null;
            }
            $cards[$key] = is_array($card) ? self::card($card) : null;
        }

        return $cards;
    }

    /** Forget the resolvers: tests. */
    public static function reset(): void
    {
        self::$resolvers = [];
        self::$patterns  = [];
    }

    /**
     * A card as a message's: its known parts, a link only to an http(s) address or a path here.
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public static function card(array $card): array
    {
        $link = static fn ($u): string => is_string($u) && preg_match('#^(https?://|/(?!/))#i', trim($u)) ? trim($u) : '';

        return array_filter([
            'title'  => (string) ($card['title'] ?? ''),
            'url'    => $link($card['url'] ?? ''),
            'text'   => (string) ($card['text'] ?? ''),
            'image'  => $link($card['image'] ?? ''),
            'fields' => array_map('strval', array_filter((array) ($card['fields'] ?? []), 'is_scalar')),
            'color'  => in_array($card['color'] ?? '', ['info', 'warning', 'success', 'error', 'neutral'], true) ? $card['color'] : '',
        ], static fn ($v): bool => $v !== '' && $v !== []);
    }
}
