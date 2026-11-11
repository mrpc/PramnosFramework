<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Auth\AdminAccess;
use Pramnos\Llm\Connection;
use Pramnos\Llm\Pricing;
use Pramnos\Llm\Providers;
use Pramnos\Application\Settings;

/**
 * The models a person may choose for their Ask questions, each behind a floor: a usertype, or an
 * ability decided by {@see AdminAccess::allows()}.
 *
 * Kept as one setting, {@see SETTING}, edited on the card of `/admin/Ai/settings` this class also
 * is. A person's choice is kept with their account ({@see USER_DETAIL}) and checked again on every
 * question ({@see chosen()}): a choice unknown, or behind a floor the person no longer reaches, is
 * "Automatic". Automatic picks among the choices this person may have, cheapest first, by
 * {@see \Pramnos\Llm\Policy\ModelCascade::choose()} — so it rises at most to the dearest their
 * role allows.
 */
final class ModelChoices implements \Pramnos\Llm\Admin\Section
{
    /** The setting the choices are kept in, as a JSON list. */
    public const SETTING = 'llm_model_choices';

    /** The person's choice, a choice's key; empty is Automatic. Not `llm_…`: `User::save()` keeps no detail whose fourth character is `_`. */
    public const USER_DETAIL = 'askmodel';

    /**
     * Every choice, cleaned: those without a known provider or a model are left out.
     *
     * @return list<array{key: string, name: string, provider: string, model: string, reasoning: bool, usertype: int, ability: string}>
     */
    public static function all(): array
    {
        $stored = json_decode((string) Settings::getSetting(self::SETTING, ''), true);
        $out    = [];
        foreach (is_array($stored) ? $stored : [] as $row) {
            $choice = is_array($row) ? self::clean($row) : null;
            if ($choice !== null && !isset($out[$choice['key']])) {
                $out[$choice['key']] = $choice;
            }
        }

        return array_values($out);
    }

    /**
     * The choices this person may have, cheapest first.
     *
     * @return list<array{key: string, name: string, provider: string, model: string, reasoning: bool, usertype: int, ability: string}>
     */
    public static function allowed(?object $user): array
    {
        if ($user === null || (int) ($user->userid ?? 0) < 1) {
            return [];
        }
        $allowed = array_values(array_filter(self::all(), static fn (array $c): bool => $c['ability'] !== ''
            ? AdminAccess::allows($user, $c['ability'], $c['usertype'])
            : (int) ($user->usertype ?? 0) >= $c['usertype']));

        return self::byCost($allowed);
    }

    /**
     * The choice this person made, if they may still have it; null for Automatic.
     *
     * @return array{key: string, name: string, provider: string, model: string, reasoning: bool, usertype: int, ability: string}|null
     */
    public static function chosen(?object $user): ?array
    {
        $key = trim((string) ($user->{self::USER_DETAIL} ?? ''));
        foreach ($key === '' ? [] : self::allowed($user) as $choice) {
            if ($choice['key'] === $key) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * Keep a person's choice: a key among those they may have, or '' for Automatic. Answers false,
     * keeping nothing, for a key they may not have.
     */
    public static function choose(object $user, string $key): bool
    {
        $key = trim($key);
        if ($key !== '' && !in_array($key, array_column(self::allowed($user), 'key'), true)) {
            return false;
        }
        $user->{self::USER_DETAIL} = $key;
        $user->save();

        return true;
    }

    /** The connection a choice answers on: its provider's key and settings, its model and reasoning. */
    public static function connection(array $choice): Connection
    {
        return new Connection($choice['provider'], null, ['model' => $choice['model'], 'reasoning' => $choice['reasoning']]);
    }

    /**
     * Choices ordered by what a million tokens in and a million out cost on the stored prices; an
     * unpriced model last, as if dearest, and with reasoning after the same model without.
     *
     * @param list<array<string, mixed>> $choices
     * @return list<array<string, mixed>>
     */
    public static function byCost(array $choices): array
    {
        $prices = new Pricing();
        $cost   = static function (array $c) use ($prices): float {
            $p = $prices->forModel((string) $c['model']);

            return $p === null ? INF : (float) $p['cache_miss'] + (float) $p['output'];
        };
        usort($choices, static fn (array $a, array $b): int => [$cost($a), (int) $a['reasoning']] <=> [$cost($b), (int) $b['reasoning']]);

        return $choices;
    }

    /**
     * A row as stored, or null when it names no known provider or no model.
     *
     * @param array<string, mixed> $row
     * @return array{key: string, name: string, provider: string, model: string, reasoning: bool, usertype: int, ability: string}|null
     */
    private static function clean(array $row): ?array
    {
        $provider = trim((string) ($row['provider'] ?? ''));
        $model    = trim((string) ($row['model'] ?? ''));
        $name     = trim((string) ($row['name'] ?? ''));
        if ($model === '' || !Providers::isKnown($provider)) {
            return null;
        }
        $key = trim((string) ($row['key'] ?? ''));

        return [
            'key'       => $key !== '' ? $key : substr(hash('sha256', $provider . '|' . $model . '|' . (empty($row['reasoning']) ? 0 : 1)), 0, 10),
            'name'      => $name !== '' ? $name : $model,
            'provider'  => $provider,
            'model'     => $model,
            'reasoning' => !empty($row['reasoning']),
            'usertype'  => max(0, (int) ($row['usertype'] ?? 0)),
            'ability'   => trim((string) ($row['ability'] ?? '')),
        ];
    }

    /** The card's key on the settings screen. */
    public function key(): string
    {
        return 'modelchoices';
    }

    /** The card's title. */
    public function title(): string
    {
        return 'Models people may choose';
    }

    /**
     * One row per choice and an empty one to add another; a row whose model is emptied is
     * removed on save.
     */
    public function render(): string
    {
        $e    = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = [...self::all(), ['key' => '', 'name' => '', 'provider' => '', 'model' => '', 'reasoning' => false, 'usertype' => 0, 'ability' => '']];
        $html = '<p>Shown in the Ask composer, beside "Automatic", to whoever reaches the floor: the usertype, or — when an ability is named — '
            . 'whoever the access rules let open it. Automatic picks among those a person may have, cheapest first. Empty a model to remove a row.</p>'
            . '<table class="pf-table"><thead><tr><th>Name</th><th>Provider</th><th>Model</th><th>Reasoning</th><th>Lowest usertype</th><th>Or ability</th></tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $options = '<option value=""></option>';
            foreach (Providers::all() as $id => $config) {
                $options .= '<option value="' . $e($id) . '"' . ($id === $row['provider'] ? ' selected' : '') . '>' . $e($config['label']) . '</option>';
            }
            $n     = 'choices[' . $i . ']';
            $html .= '<tr><td><input type="hidden" name="' . $n . '[key]" value="' . $e($row['key']) . '">'
                . '<input type="text" name="' . $n . '[name]" value="' . $e($row['name']) . '" aria-label="Name"></td>'
                . '<td><select name="' . $n . '[provider]" aria-label="Provider">' . $options . '</select></td>'
                . '<td><input type="text" name="' . $n . '[model]" value="' . $e($row['model']) . '" aria-label="Model"></td>'
                . '<td><input type="checkbox" name="' . $n . '[reasoning]" value="1"' . ($row['reasoning'] ? ' checked' : '') . ' aria-label="Reasoning"></td>'
                . '<td><input type="number" min="0" name="' . $n . '[usertype]" value="' . $e($row['usertype']) . '" aria-label="Lowest usertype"></td>'
                . '<td><input type="text" name="' . $n . '[ability]" value="' . $e($row['ability']) . '" placeholder="admin.ai" aria-label="Ability"></td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * Store the posted rows: a row without a model is dropped, one with an unknown provider is an
     * error.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): ?string
    {
        $rows = [];
        foreach (is_array($input['choices'] ?? null) ? $input['choices'] : [] as $row) {
            if (!is_array($row) || trim((string) ($row['model'] ?? '')) === '') {
                continue;
            }
            if (!Providers::isKnown(trim((string) ($row['provider'] ?? '')))) {
                return 'There is no provider called ' . trim((string) ($row['provider'] ?? '')) . '.';
            }
            $rows[] = self::clean($row);
        }
        Settings::setSetting(self::SETTING, (string) json_encode($rows, JSON_UNESCAPED_UNICODE));

        return null;
    }
}
