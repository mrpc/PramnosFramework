@php /**
 * An A/B test's form: what the code asks for, the variants and their shares, who takes part, and
 * the goal — an event picked from the searchable list — within days. The same markup in every theme.
 *
 * Variables:
 *   $this->test     — Experiments::get(), or null when creating
 *   $this->segments — Segments::all(), to limit the test to one
 *   $this->events   — PersonEvents::all(), for the goal
 */
$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$test     = $this->test ?? null;
$isNew    = $test === null;
$variants = $test['variants'] ?? [['key' => 'A', 'share' => 50, 'label' => ''], ['key' => 'B', 'share' => 50, 'label' => '']];
$groups   = [];
foreach ($this->events as $key => $event) {
    $heading = (string) ($event['group'] ?? '') !== '' ? (string) $event['group'] : t('Other');
    $groups[$heading] ??= ['label' => $heading, 'options' => []];
    $groups[$heading]['options'][] = ['value' => (string) $key, 'label' => (string) $event['label'], 'hint' => (string) $key];
} @endphp
<div class="pf-ui">
    @php $this->activeNav = 'experiments_edit'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $isNew ? t('New A/B test') : t('Edit A/B test') }}</h1>
        </div>
    </header>
    <form method="post" action="{{ adminUrl('Experiments/save') }}">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        @if (!$isNew)<input type="hidden" name="experimentid" value="{!! (int) $test['id'] !!}">@endif
        <div class="pf-card"><div class="pf-card-body pf-grid-2">
            <label class="pf-field">
                <span class="pf-label">{{ t('Name') }}</span>
                <input type="text" name="name" maxlength="150" required class="pf-input" value="{{ $test['name'] ?? '' }}">
            </label>
            <label class="pf-field">
                <span class="pf-label">{{ t('Name for the code') }}</span>
                <input type="text" name="slug" maxlength="64" pattern="[a-z0-9][a-z0-9._-]*" {!! $isNew ? 'required' : 'readonly' !!} class="pf-input" value="{{ $test['slug'] ?? '' }}" placeholder="premium-annual-first">
                <span class="pf-hint">{{ t('What the code asks for: lower-case letters, digits, . _ -. It cannot change later.') }}</span>
            </label>
        </div></div>
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title">{{ t('Test variants') }}</h2>
            <div class="pf-stack">
                @php for ($i = 0; $i < \Pramnos\Application\Controllers\Experiments::FORM_VARIANTS; $i++): $v = $variants[$i] ?? ['key' => '', 'share' => '', 'label' => '']; @endphp
                    <div class="pf-rule-sentence">
                        <input type="text" name="variants[{!! $i !!}][key]" maxlength="32" class="pf-input pf-input-short" value="{{ $v['key'] }}" placeholder="{{ t('Variant key') }}" aria-label="{{ t('Variant key') }}">
                        <input type="text" name="variants[{!! $i !!}][label]" class="pf-input pf-input-grow" value="{{ $v['label'] }}" placeholder="{{ t('What it is') }}" aria-label="{{ t('What it is') }}">
                        <input type="number" name="variants[{!! $i !!}][share]" min="0" max="100" class="pf-input pf-input-short" value="{{ $v['share'] }}" aria-label="{{ t('Share %') }}">
                        <span class="pf-rule-word">%</span>
                    </div>
                @endfor
            </div>
            <p class="pf-hint">{{ t('Two variants or more, their shares adding up to 100. Leave a row empty to leave it out.') }}</p>
        </div></div>
        <div class="pf-card"><div class="pf-card-body pf-grid-2">
            <label class="pf-field">
                <span class="pf-label">{{ t('Who takes part') }}</span>
                <select name="segment" class="pf-select">
                    <option value="">{{ t('Everybody, visitors too') }}</option>
                    @foreach ($this->segments as $segment)
                        <option value="{!! (int) $segment['id'] !!}"{!! ($test['segment'] ?? null) === $segment['id'] ? ' selected' : '' !!}>{{ $segment['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <div class="pf-field">
                <span class="pf-label">{{ t('Goal of the test') }}</span>
                <div class="pf-rule-sentence">
                    <div class="pf-combo-field" data-pf-combobox data-groups="{{ (string) json_encode(array_values($groups), JSON_UNESCAPED_UNICODE) }}"
                         data-placeholder="{{ t('Choose an event…') }}" data-label="{{ t('Goal of the test') }}" data-search="{{ t('Search…') }}" data-empty="{{ t('Nothing found') }}">
                        <input type="hidden" name="goalevent" value="{{ $test['goalEvent'] ?? '' }}">
                    </div>
                    <span class="pf-rule-word">{{ t('within') }}</span>
                    <input type="number" name="goaldays" min="1" class="pf-input pf-input-short" value="{!! (int) ($test['goalDays'] ?? 7) !!}" aria-label="{{ t('Within days') }}">
                    <span class="pf-rule-word">{{ t('days') }}</span>
                </div>
                <span class="pf-hint">{{ t('Counted from when each person is given a variant.') }}</span>
            </div>
        </div></div>
        <div class="pf-form-footer">
            <a href="{{ $isNew ? adminUrl('Experiments') : adminUrl('Experiments/view/') . (int) $test['id'] }}" class="pf-btn pf-btn-ghost">{{ t('Cancel') }}</a>
            <button type="submit" class="pf-btn pf-btn-primary">{{ t('Save') }}</button>
        </div>
    </form>
    <script src="{{ assetUrl('assets/js/pf-audience.js') }}" defer></script>
</div>
