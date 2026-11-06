@php /**
 * A dashboard card's settings form (tailwind theme) — what card.html.php draws under a card's
 * heading, and tabs.html.php in each tab's panel, so a card in a group of tabs is set the same way.
 *
 * Variables: `$widget` — a card as DashboardController::cardFor() gives it: settings, fields (or
 * the older `options`).
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$options  = (array) ($widget['options'] ?? []);
$settings = (array) ($widget['settings'] ?? []);
// The fields, as DashboardController::cardFor() describes them; a card drawn by an older
// controller has only `options` (a list, or null for a free value), read the same way.
$fields = (array) ($widget['fields'] ?? array_map(static fn ($choices): array => ['input' => is_array($choices) ? 'select' : 'text', 'label' => null, 'values' => (array) $choices, 'when' => []], $options));
/** Whether a field is shown for these settings: every setting it names has the value it names. */
$shown = static fn (array $field): bool => array_reduce(array_keys((array) ($field['when'] ?? [])), static fn (bool $all, string $other): bool => $all && (string) ($settings[$other] ?? '') === (string) $field['when'][$other], true); @endphp
@if ($fields)
<form class="pf-dashboard-settings flex flex-wrap items-end gap-2" data-pf-widget-settings hidden>
    @foreach ($fields as $name => $field)
    <label class="flex items-center gap-2 text-sm"{!! $field['when'] ? ' data-pf-when="' . $e(json_encode((object) $field['when'])) . '"' . ($shown($field) ? '' : ' hidden') : '' !!}>{{ $t((string) ($field['label'] ?? ucfirst(str_replace('_', ' ', (string) $name)))) }}
        @if ($field['input'] === 'select')
        <select class="select select-sm select-bordered" name="{{ $name }}">
            @foreach ($field['values'] as $value => $label)<option value="{{ $value }}"{!! (string) ($settings[$name] ?? '') === (string) $value ? ' selected' : '' !!}>{{ $t((string) $label) }}</option>@endforeach
        </select>
        @else
        <input class="input input-sm input-bordered" type="{{ $field['input'] }}" name="{{ $name }}" value="{{ is_scalar($settings[$name] ?? null) ? $settings[$name] : '' }}">
        @endif
    </label>
    @endforeach
    <button type="submit" class="btn btn-sm btn-primary">{{ $t('Apply') }}</button>
</form>
@endif
