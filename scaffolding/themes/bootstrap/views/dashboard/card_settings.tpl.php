@php /**
 * A dashboard card's settings form (bootstrap theme) — what card.html.php draws under a card's
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
<form class="pf-dashboard-settings d-flex flex-wrap align-items-end gap-2 mb-3" data-pf-widget-settings hidden>
    @foreach ($fields as $name => $field)
    @php $box = in_array($field['input'], ['list', 'filters'], true); @endphp
    <{{ $box ? 'div' : 'label' }} class="small"{!! $box ? ' style="flex-basis:100%"' : '' !!}{!! $field['when'] ? ' data-pf-when="' . $e(json_encode((object) $field['when'])) . '"' . ($shown($field) ? '' : ' hidden') : '' !!}>{{ $t((string) ($field['label'] ?? ucfirst(str_replace('_', ' ', (string) $name)))) }}
        @if ($field['input'] === 'select')
        <select class="form-select form-select-sm" name="{{ $name }}">
            @foreach ($field['values'] as $value => $label)<option value="{{ $value }}"{!! (string) ($settings[$name] ?? '') === (string) $value ? ' selected' : '' !!}>{{ $t((string) $label) }}</option>@endforeach
        </select>
        @elseif ($field['input'] === 'textarea')
        <textarea class="form-control form-control-sm" name="{{ $name }}" rows="4" style="min-width:16rem">{{ is_scalar($settings[$name] ?? null) ? $settings[$name] : '' }}</textarea>
        @elseif ($box)
        <input type="hidden" name="{{ $name }}" data-pf-json value="{{ json_encode($settings[$name] ?? null, JSON_UNESCAPED_UNICODE) }}">
        <div data-pf-structured="{{ $field['input'] }}" data-spec="{{ json_encode(array_diff_key($field, ['label' => true, 'when' => true]), JSON_UNESCAPED_UNICODE) }}" data-classes='{"select":"form-select form-select-sm","input":"form-control form-control-sm","button":"btn btn-sm btn-outline-secondary"}' data-say="{{ json_encode(['add' => $t('Add'), 'remove' => $t('Remove'), 'move' => $t('Drag to move'), 'any' => $t('Choose…')], JSON_UNESCAPED_UNICODE) }}"></div>
        @else
        <input class="form-control form-control-sm" type="{{ $field['input'] }}" name="{{ $name }}" value="{{ is_scalar($settings[$name] ?? null) ? $settings[$name] : '' }}">
        @endif
    </{{ $box ? 'div' : 'label' }}>
    @endforeach
    <button type="submit" class="btn btn-sm btn-primary">{{ $t('Apply') }}</button>
</form>
@endif
