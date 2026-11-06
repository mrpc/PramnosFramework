@php /**
 * A segment's form: its name, what it is for, and the filter with the live count beside it. The
 * buttons stay in reach at the foot of the window. The same markup in every theme.
 *
 * Variables:
 *   $this->segment — Segments::get(), or null when creating
 *   $this->draft   — a new one's name, description and filter from Ask's draft, or null
 */
$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment ?? null;
$isNew   = $segment === null;
$values  = $segment ?? ($this->draft ?? null) ?? []; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'segments_edit'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $isNew ? t('New segment') : t('Edit segment') }}</h1>
        </div>
    </header>
    <form method="post" action="{{ adminUrl('Segments/save') }}">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        @if (!$isNew)
            <input type="hidden" name="segmentid" value="{!! (int) $segment['id'] !!}">
        @endif
        <div class="pf-card"><div class="pf-card-body pf-stack">
            <div class="pf-grid-2">
                <label class="pf-field">
                    <span class="pf-label">{{ t('Name') }}</span>
                    <input type="text" name="name" maxlength="150" required class="pf-input" value="{{ $values['name'] ?? '' }}" placeholder="{{ t('Professionals without a photo') }}">
                </label>
                <label class="pf-field">
                    <span class="pf-label">{{ t('What it is for') }}</span>
                    <input type="text" name="description" class="pf-input" value="{{ $values['description'] ?? '' }}">
                    <span class="pf-hint">{{ t('Shown under the name in the list.') }}</span>
                </label>
            </div>
        </div></div>
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title">{{ t('Who is in it') }}</h2>
            {!! \Pramnos\Html\AudienceBuilder::filter('filter', $values['filter'] ?? null) !!}
        </div></div>
        <div class="pf-form-footer">
            <p class="pf-hint">{{ $isNew ? t('Saving works it out at once, without announcing the people already in it.') : t('Saving works it out again.') }}</p>
            <a href="{{ $isNew ? adminUrl('Segments') : adminUrl('Segments/view/') . (int) $segment['id'] }}" class="pf-btn pf-btn-ghost">{{ t('Cancel') }}</a>
            <button type="submit" class="pf-btn pf-btn-primary">{{ t('Save') }}</button>
        </div>
    </form>
</div>
