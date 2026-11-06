@php /**
 * A consent list's form: its name, channel, where it reads from, and what it means to the person.
 * The same markup in every theme.
 *
 * Variables:
 *   $this->list     — ConsentLists::get(), or null when creating
 *   $this->sources  — the declared sources' labels, by key
 *   $this->channels — the channels' words, by key
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$list  = $this->list ?? null;
$isNew = $list === null; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'consentlists_edit'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $isNew ? t('New consent list') : t('Edit consent list') }}</h1>
        </div>
    </header>
    <form method="post" action="{{ adminUrl('ConsentLists/save') }}">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        @if (!$isNew)
            <input type="hidden" name="listid" value="{!! (int) $list['id'] !!}">
        @endif
        <div class="pf-card"><div class="pf-card-body pf-stack">
            <div class="pf-grid-2">
                <label class="pf-field">
                    <span class="pf-label">{{ t('Name') }}</span>
                    <input type="text" name="name" maxlength="150" required class="pf-input" value="{{ $list['name'] ?? '' }}">
                </label>
                <label class="pf-field">
                    <span class="pf-label">{{ t('Channel') }}</span>
                    <select name="channel" class="pf-select">
                        @foreach ($this->channels as $key => $word)
                            <option value="{{ $key }}"{!! ($list['channel'] ?? 'email') === $key ? ' selected' : '' !!}>{{ $word }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="pf-field">
                    <span class="pf-label">{{ t('Read from') }}</span>
                    <select name="source" class="pf-select">
                        @foreach ($this->sources as $key => $label)
                            <option value="{{ $key }}"{!! ($list['source'] ?? 'everyone') === $key ? ' selected' : '' !!}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="pf-hint">{{ t('Where it finds who agreed — the application declares the choices.') }}</span>
                </label>
                <label class="pf-field">
                    <span class="pf-label">{{ t('Email opt-out list (optional)') }}</span>
                    <input type="text" name="optoutlist" maxlength="64" class="pf-input" value="{{ $list['optOutList'] ?? '' }}" placeholder="newsletter">
                    <span class="pf-hint">{{ t('People who unsubscribed from this list are left out too.') }}</span>
                </label>
            </div>
            <label class="pf-field">
                <span class="pf-label">{{ t('What it means, as the person reads it') }}</span>
                <input type="text" name="description" class="pf-input" value="{{ $list['description'] ?? '' }}">
            </label>
            <label class="pf-check">
                <input type="checkbox" name="defaulton" value="1"{!! !empty($list['defaultOn']) ? ' checked' : '' !!}>
                <span>{{ t('New accounts start in this list') }}</span>
            </label>
        </div></div>
        <div class="pf-form-footer">
            <a href="{{ adminUrl('ConsentLists') }}" class="pf-btn pf-btn-ghost">{{ t('Cancel') }}</a>
            <button type="submit" class="pf-btn pf-btn-primary">{{ t('Save') }}</button>
        </div>
    </form>
</div>
