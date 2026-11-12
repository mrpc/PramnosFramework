<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A person's companion settings in one place, each persona's own where it makes sense — read and
 * changed from the application (`GET`/`PUT {chat}/settings`) and by talking (`change_setting`):
 *
 * - `persona` — whom they talk to ({@see Personas});
 * - `voice`, `style` — the persona's voice and pose ({@see \Pramnos\Llm\Speech\Speech}, {@see Acts});
 * - `thought_at` — when the morning's thought is written and told (`H:i`), `off` for never, null
 *   for the application's (`today.morning.at`);
 * - `quiet` — `{from, to}`, the hours nothing is sent in, null for the application's
 *   (`today.morning.quiet`);
 * - `notify` — `{thought, diary, reminders}`, each on or off;
 * - `app` — the application's own, declared with their values and kept without the framework
 *   knowing what they mean:
 *
 * ```php
 * 'llm' => ['chat' => ['settings' => ['place' => ['values' => ['hill', 'beach'], 'default' => 'hill', 'label' => 'Where she is']]]],
 * ```
 *
 * What is not one of theirs is kept with the account, a JSON detail for each persona; resetting a
 * person's experience ({@see People::reset()}) takes it away with the other choices.
 */
final class Settings
{
    /** The detail the persona's settings are kept in, before the persona's key. */
    public const DETAIL = 'askset';

    /** What a person is told of, each on or off. */
    public const NOTIFY = ['thought', 'diary', 'reminders'];

    /** What a tool changed in the answer under way, by user: the answer carries it. @var array<int, array<string, mixed>> */
    private static array $changed = [];

    /**
     * A person's settings for a persona (null: the default one), defaults filled in — in a
     * partition of their account, its own over the holder's.
     *
     * @return array{persona: ?string, voice: ?string, style: ?string, thought_at: ?string, quiet: ?array{from: string, to: string}, notify: array<string, bool>, app: array<string, string>}
     */
    public static function of(object $user, ?string $persona = null, ?int $partition = null): array
    {
        $kept    = self::kept($user, $persona, $partition);
        $morning = self::morning();
        $app     = [];
        foreach (self::declared() as $key => $declared) {
            $app[$key] = isset($kept['app'][$key]) && in_array($kept['app'][$key], $declared['values'], true) ? $kept['app'][$key] : $declared['default'];
        }
        $notify = [];
        foreach (self::NOTIFY as $kind) {
            $notify[$kind] = (bool) ($kept['notify'][$kind] ?? true);
        }

        return [
            'persona'    => Personas::on() ? Personas::chosen($user) : null,
            'voice'      => \Pramnos\Llm\Speech\Speech::chosen($user, $persona),
            'style'      => Acts::style($user, $persona),
            'thought_at' => array_key_exists('thought_at', $kept) ? $kept['thought_at'] : ($morning['at'] ?? null),
            'quiet'      => array_key_exists('quiet', $kept) ? $kept['quiet'] : ($morning['quiet'] ?? null),
            'notify'     => $notify,
            'app'        => $app,
        ];
    }

    /**
     * Change some of a person's settings for a persona: any of {@see of()}'s fields, `app` by its
     * own keys. All or nothing. In a partition, its own are written, the holder's left as they are.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|string What changed, or the field that is wrong and why
     */
    public static function change(object $user, ?string $persona, array $changes, ?int $partition = null): array|string
    {
        $kept    = self::kept($user, $persona, $partition);
        $changed = [];
        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'persona':
                case 'voice':
                case 'style':
                    break;
                case 'thought_at':
                    $value = $value === null || $value === '' ? null : (string) $value;
                    if ($value !== null && $value !== 'off' && self::time($value) === null) {
                        return 'thought_at: a time, H:i, or "off".';
                    }
                    $kept['thought_at'] = $value === null || $value === 'off' ? $value : self::time($value);
                    break;
                case 'quiet':
                    if ($value !== null && (!is_array($value) || self::time((string) ($value['from'] ?? '')) === null || self::time((string) ($value['to'] ?? '')) === null)) {
                        return 'quiet: {from, to}, each H:i, or null.';
                    }
                    $kept['quiet'] = $value === null ? null : ['from' => self::time((string) $value['from']), 'to' => self::time((string) $value['to'])];
                    break;
                case 'notify':
                    if (!is_array($value) || array_diff(array_keys($value), self::NOTIFY) !== []) {
                        return 'notify: on or off for ' . implode(', ', self::NOTIFY) . '.';
                    }
                    foreach ($value as $kind => $on) {
                        $kept['notify'][$kind] = filter_var($on, FILTER_VALIDATE_BOOLEAN);
                    }
                    break;
                case 'app':
                    $declared = self::declared();
                    if (!is_array($value)) {
                        return 'app: the application\'s settings, by name.';
                    }
                    foreach ($value as $key => $one) {
                        if (!isset($declared[$key]) || !in_array((string) $one, $declared[$key]['values'], true)) {
                            return 'app.' . $key . ': one of ' . implode(', ', $declared[$key]['values'] ?? []) . '.';
                        }
                        $kept['app'][$key] = (string) $one;
                    }
                    break;
                default:
                    return $field . ': not a setting.';
            }
            $changed[$field] = $value;
        }
        // The choices of their own, each where it is kept already; refused before anything is written.
        if (array_key_exists('persona', $changes) && !Personas::on()) {
            return 'persona: there are no personas.';
        }
        if (array_key_exists('persona', $changes) && !isset(Personas::all()[(string) $changes['persona']])) {
            return 'persona: one of ' . implode(', ', array_keys(Personas::all())) . '.';
        }
        if (array_key_exists('voice', $changes) && (string) $changes['voice'] !== '' && !isset(\Pramnos\Llm\Speech\Speech::voices()[(string) $changes['voice']])) {
            return 'voice: one of ' . implode(', ', array_keys(\Pramnos\Llm\Speech\Speech::voices())) . ', or "" for the default.';
        }
        if (array_key_exists('style', $changes) && (string) $changes['style'] !== '' && !in_array((string) $changes['style'], \Pramnos\Assist\AvatarSets::poses(), true)) {
            return 'style: one of the picture\'s poses, or "" for none.';
        }
        if (array_key_exists('persona', $changes)) {
            Personas::choose($user, (string) $changes['persona']);
        }
        if (array_key_exists('voice', $changes)) {
            \Pramnos\Llm\Speech\Speech::choose($user, (string) $changes['voice'], $persona);
        }
        if (array_key_exists('style', $changes)) {
            Acts::choose($user, (string) $changes['style'], $persona);
        }
        if (array_diff_key($changed, array_flip(['persona', 'voice', 'style'])) !== []) {
            $user->{self::detail($persona, $partition)} = (string) json_encode($kept, JSON_UNESCAPED_UNICODE);
            $user->save();
        }

        return $changed;
    }

    /**
     * The companion's tool: a setting changed by talking, told back in the answer and carried with
     * it (`settings.changed`), so the application changes at once.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tools(Scope $scope): array
    {
        // Only where there is something to set by talking: the application's morning, or its own settings.
        $morning = ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['today'] ?? []))['morning'] ?? null;
        if (!is_array($morning) && self::declared() === []) {
            return [];
        }
        $app = [];
        foreach (self::declared() as $key => $declared) {
            $app[$key] = ['type' => 'string', 'enum' => $declared['values'], 'description' => $declared['label']];
        }

        return ['change_setting' => [
            'description' => 'Change one of the person\'s own settings, when they ask: when you send the morning\'s thought (thought_at, H:i, or "off"), '
                . 'the hours nothing is sent (quiet {from, to}), what they are told of (notify {thought, diary, reminders}: true or false)'
                . ($app !== [] ? ', and ' . implode(', ', array_keys($app)) . ' (in app)' : '') . '. Then say in your answer what you changed.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'thought_at' => ['type' => 'string'],
                'quiet'      => ['type' => 'object', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']]],
                'notify'     => ['type' => 'object', 'properties' => array_fill_keys(self::NOTIFY, ['type' => 'boolean'])],
            ] + ($app !== [] ? ['app' => ['type' => 'object', 'properties' => $app]] : [])],
            'run'         => static function (array $a) use ($scope): string {
                $user = new \Pramnos\User\User($scope->userId);
                $done = self::change($user, $scope->persona, array_intersect_key($a, array_flip(['thought_at', 'quiet', 'notify', 'app'])));
                if (is_string($done)) {
                    return 'Error: ' . $done;
                }
                self::$changed[$scope->userId] = $done + (self::$changed[$scope->userId] ?? []);

                return 'Changed: ' . json_encode($done, JSON_UNESCAPED_UNICODE) . '. Tell them, in your answer.';
            },
        ]];
    }

    /**
     * What a tool changed for a person in the answer under way, taken: the answer carries it once.
     *
     * @return array<string, mixed>
     */
    public static function taken(int $userId): array
    {
        $changed = self::$changed[$userId] ?? [];
        unset(self::$changed[$userId]);

        return $changed;
    }

    /**
     * The application's own settings, each with its values, default and label.
     *
     * @return array<string, array{values: list<string>, default: string, label: string}>
     */
    public static function declared(): array
    {
        $out = [];
        foreach ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['settings'] ?? []) as $key => $given) {
            $values = array_values(array_map('strval', (array) ($given['values'] ?? [])));
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) === 1 && $values !== []) {
                $default   = (string) ($given['default'] ?? $values[0]);
                $out[$key] = ['values' => $values, 'default' => in_array($default, $values, true) ? $default : $values[0], 'label' => (string) ($given['label'] ?? $key)];
            }
        }

        return $out;
    }

    /**
     * The application's morning: `today.morning` — when the thought is written and the quiet hours.
     *
     * @return array{at: ?string, quiet: ?array{from: string, to: string}, notify: bool, url: string}
     */
    public static function morning(): array
    {
        $today   = ((array) \Pramnos\Llm\Llm::config('chat', []))['today'] ?? [];
        $morning = is_array($today) && is_array($today['morning'] ?? null) ? $today['morning'] : [];
        $quiet   = (array) ($morning['quiet'] ?? []);
        $from    = self::time((string) ($quiet['from'] ?? $quiet[0] ?? ''));
        $to      = self::time((string) ($quiet['to'] ?? $quiet[1] ?? ''));

        return ['at' => self::time((string) ($morning['at'] ?? '')), 'quiet' => $from !== null && $to !== null ? ['from' => $from, 'to' => $to] : null,
            'notify' => !empty($morning['notify']), 'url' => (string) ($morning['url'] ?? '')];
    }

    /** Whether a moment on the person's clock (`H:i`) falls in their quiet hours, which may cross midnight. */
    public static function quietAt(?array $quiet, string $time): bool
    {
        if ($quiet === null) {
            return false;
        }

        return $quiet['from'] <= $quiet['to'] ? $time >= $quiet['from'] && $time < $quiet['to'] : $time >= $quiet['from'] || $time < $quiet['to'];
    }

    /** `H:i`, padded, or null for what is not a time of day. */
    public static function time(string $time): ?string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    /** @return array<string, mixed> What is kept for a persona */
    /** @return array<string, mixed> What is kept — a partition's own over the holder's. */
    private static function kept(object $user, ?string $persona, ?int $partition = null): array
    {
        $read = static function (string $detail) use ($user): array {
            $kept = json_decode((string) ($user->{$detail} ?? ''), true);

            return is_array($kept) ? $kept : [];
        };
        $own = $read(self::detail($persona));

        return $partition === null ? $own : array_replace_recursive($own, $read(self::detail($persona, $partition)));
    }

    private static function detail(?string $persona, ?int $partition = null): string
    {
        return ($persona === null ? self::DETAIL : self::DETAIL . '-' . $persona) . ($partition === null ? '' : '@' . $partition);
    }
}
