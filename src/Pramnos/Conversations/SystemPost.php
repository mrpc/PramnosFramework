<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * What the application tells its team: a line, a card, in a channel of its own or to one person —
 * in the shape a chat webhook takes, so code that posted to one posts here instead.
 *
 * ```php
 * SystemPost::post('#payments', 'Νέα πληρωμή', [[
 *     'title' => 'Τιμολόγιο 2291', 'title_link' => $url, 'text' => '29,90 € · Premium',
 *     'color' => 'good',                                   // good, warning, danger, or #hex
 *     'fields' => [['title' => 'Πελάτης', 'value' => 'Ελένη Δ.']],
 * ]], ['username' => 'Nannuka Bot']);
 * SystemPost::post(48211, 'Σου ανατέθηκε το NAN-6212');      // to one person, by account id
 * SystemPost::post('@maria', '…');                           // by a name the application resolves
 * ```
 *
 * - `#name` is the system channel of that name, made the first time something is posted to it;
 *   the team joins it as any open channel.
 * - An account id — an int, or `@id:48211` — is that person's own conversation with the bot,
 *   listed with their direct messages under the bot's name. `@name` is resolved by the
 *   application (`resolvePeople()`: a name from another system, say), else as a username. Somebody
 *   who may not use the conversations, or nobody, gets nothing: the post returns null.
 * - The text is a message's Markdown ({@see Markdown}); a line break stays one.
 * - Each attachment is a card under the text: `title`, `title_link`, `text` (or `fallback`),
 *   `pretext` before it, `image_url`, `fields` as label and value, `color` as one of the card's
 *   tones. A second attachment is a second message.
 * - `username` signs it — "Nannuka Bot"; `source` and `externalid` make a repeat the same message,
 *   for a sender that retries; the same post twice in one request is written once.
 */
final class SystemPost
{
    /** Who signs a post that names nobody. */
    public const DEFAULT_FROM = 'System';

    /** @var (callable(string): ?int)|null How the application finds a person by a name of its own */
    private static $resolver = null;

    /** @var array<string, int> What this request posted already, by what was posted */
    private static array $sent = [];

    /**
     * How `@name` finds a person: the application's own names — another system's, during a move
     * from it — to an account id, or null for nobody. Null restores looking up usernames.
     *
     * @param (callable(string): ?int)|null $resolver
     */
    public static function resolvePeople(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Forget what this request posted — for a worker that runs many. */
    public static function reset(): void
    {
        self::$sent = [];
    }

    /**
     * Post to a channel or a person.
     *
     * @param list<array<string, mixed>> $attachments Each a card — see {@see card()}
     * @param array{username?: string, source?: string, externalid?: string, thread?: int, topic?: string} $options
     * @return int|null The (first) message, or null where there is nobody to post to
     */
    public static function post(int|string $channel, string $text = '', array $attachments = [], array $options = []): ?int
    {
        $key = md5((string) json_encode([$channel, $text, $attachments, $options]));
        if (isset(self::$sent[$key])) {
            return self::$sent[$key];
        }
        $conversation = self::conversation(is_int($channel) ? $channel : trim($channel), (string) ($options['username'] ?? ''), (string) ($options['topic'] ?? ''));
        if ($conversation === null) {
            return null;
        }
        $from  = trim((string) ($options['username'] ?? ''));
        $cards = array_values(array_filter(array_map(static fn ($a): ?array => is_array($a) ? self::card($a) : null, $attachments)));
        $first = null;
        foreach ($cards === [] ? [null] : $cards as $i => $card) {
            if ($from !== '') {
                $card = ($card ?? []) + ['from' => $from];
            }
            $id = Messages::post($conversation, null, $i === 0 ? $text : '', array_filter([
                'card'       => $card,
                'source'     => $options['source'] ?? null,
                'externalid' => isset($options['externalid']) ? $options['externalid'] . ($i > 0 ? '#' . $i : '') : null,
                'thread'     => $options['thread'] ?? null,
            ], static fn ($v): bool => $v !== null));
            $first ??= $id;
        }

        return self::$sent[$key] = (int) $first;
    }

    /**
     * The conversation an address names: `#name` a system channel, made when missing;
     * `@username` or `@id` that person's conversation with the bot.
     */
    public static function conversation(int|string $channel, string $from = '', string $topic = ''): ?int
    {
        if (is_int($channel) || str_starts_with($channel, '@')) {
            $person = self::person(is_int($channel) ? $channel : substr($channel, 1));
            if ($person === null) {
                return null;
            }

            return Conversations::create('bot', $from !== '' ? $from : t(self::DEFAULT_FROM), [$person]);
        }
        $slug     = Conversations::slug($channel);
        $existing = Conversations::bySlug($slug);
        if ($existing !== null) {
            return $existing['id'];
        }

        return Conversations::create('system', $slug, [], null, $topic);
    }

    /**
     * An attachment as a card: `title`, `title_link`, `text` (or `fallback`), `pretext`, `image_url`,
     * `fields`, `color`.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>|null
     */
    public static function card(array $a): ?array
    {
        $text = trim((string) ($a['text'] ?? $a['fallback'] ?? ''));
        if (trim((string) ($a['pretext'] ?? '')) !== '') {
            $text = trim((string) $a['pretext'] . "\n" . $text);
        }
        $fields = [];
        foreach ((array) ($a['fields'] ?? []) as $label => $field) {
            if (is_array($field)) {
                $fields[(string) ($field['title'] ?? '')] = (string) ($field['value'] ?? '');
            } else {
                $fields[(string) $label] = (string) $field;
            }
        }
        $card = array_filter([
            'title'  => trim((string) ($a['title'] ?? '')),
            'url'    => self::url((string) ($a['title_link'] ?? '')),
            'text'   => $text,
            'image'  => self::url((string) ($a['image_url'] ?? '')),
            'fields' => array_filter($fields, static fn (string $v, string $k): bool => $k !== '' || $v !== '', ARRAY_FILTER_USE_BOTH),
            'color'  => self::tone((string) ($a['color'] ?? '')),
        ], static fn ($v): bool => $v !== '' && $v !== []);

        return $card === [] || array_keys($card) === ['color'] ? null : $card;
    }

    /**
     * A colour as one of a card's tones: `good`, `warning`, `danger`, or a hex by its hue —
     * red an error, orange and yellow a warning, green a success, blue and violet information,
     * grey neutral.
     */
    public static function tone(string $color): string
    {
        $color = strtolower(trim($color));
        $named = ['good' => 'success', 'warning' => 'warning', 'danger' => 'error'];
        if (isset($named[$color]) || in_array($color, ['info', 'success', 'error', 'neutral'], true)) {
            return $named[$color] ?? $color;
        }
        if (!preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $color, $m)) {
            return '';
        }
        $hex = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];
        [$r, $g, $b] = array_map(static fn (string $h): float => hexdec($h) / 255, str_split((string) $hex, 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        if ($max - $min < 0.15) {
            return 'neutral';
        }
        $d   = $max - $min;
        $hue = match ($max) {
            $r      => 60 * fmod(($g - $b) / $d, 6),
            $g      => 60 * (($b - $r) / $d + 2),
            default => 60 * (($r - $g) / $d + 4),
        };
        $hue = $hue < 0 ? $hue + 360 : $hue;

        return match (true) {
            $hue < 18 || $hue >= 330 => 'error',
            $hue < 70                => 'warning',
            $hue < 170               => 'success',
            default                  => 'info',
        };
    }

    /** A person by account id, `id:N`, or a name — the application's, else a username — who may use the conversations; null otherwise. */
    private static function person(int|string $who): ?int
    {
        if (is_string($who) && preg_match('/^id:(\d+)$/', $who, $m)) {
            $who = (int) $m[1];
        } elseif (is_string($who) && self::$resolver !== null) {
            $who = (self::$resolver)($who);
            if ($who === null) {
                return null;
            }
        }
        $query = Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.usertype', 'users.active']);
        is_int($who) ? $query->where('users.userid', $who) : $query->where('users.username', $who);
        $row = $query->limit(1)->getAllOrFail()[0] ?? null;

        return $row !== null && Access::canUse((object) $row) ? (int) $row['userid'] : null;
    }

    /** An address a card may link to: http(s), or a path of this site — never a script. */
    private static function url(string $url): string
    {
        return preg_match('#^(https?://|/(?!/))#i', trim($url)) ? trim($url) : '';
    }
}
