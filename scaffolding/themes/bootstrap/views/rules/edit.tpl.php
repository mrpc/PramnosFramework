@php /**
 * A rule's editor: its name, then when, who, what and until as numbered cards. The buttons stay in
 * reach at the foot of the window. The same markup in every theme.
 *
 * Variables:
 *   $this->rule — Rules::get(), or null when creating
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule  = $this->rule ?? null;
$isNew = $rule === null; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'rules_edit'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $isNew ? t('New rule') : t('Edit rule') }}</h1>
        </div>
    </header>
    <form method="post" action="{{ adminUrl('Rules/save') }}" class="pf-stack">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        @if (!$isNew)
            <input type="hidden" name="ruleid" value="{!! (int) $rule['id'] !!}">
        @endif
        <div class="pf-card"><div class="pf-card-body">
            <label class="pf-field">
                <span class="pf-label">{{ t('Name') }}</span>
                <input type="text" name="name" maxlength="150" required class="pf-input" value="{{ $rule['name'] ?? '' }}" placeholder="{{ t('Welcome series') }}">
            </label>
        </div></div>
        {!! \Pramnos\Html\RuleEditor::render($rule['definition'] ?? null) !!}
        <div class="pf-form-footer">
            <p class="pf-hint">{{ $isNew ? t('A new rule is a draft until it is switched on.') : t('Saving makes a new version: people already on their way finish on theirs.') }}</p>
            <a href="{{ $isNew ? adminUrl('Rules') : adminUrl('Rules/view/') . (int) $rule['id'] }}" class="pf-btn pf-btn-ghost">{{ t('Cancel') }}</a>
            <button type="submit" class="pf-btn pf-btn-primary">{{ t('Save') }}</button>
        </div>
    </form>
</div>
