@php /**
 * The presets and dashboards dialog of a board (bootstrap theme), in tabs: the presets to apply
 * (and delete, or make a type's default), saving this board as one, a new dashboard of one's
 * own, one's own board's settings, and — for an administrator — copying it to somebody, picked by
 * name. Plain forms, each posting with the session's token and coming back to the page with this
 * dialog open on the tab used (`#pf-presets=save`).
 *
 * A theme overrides it by copying it. The script reads: data-pf-presets-tabs (data-active-class)
 * and data-pf-presets-tab / data-pf-presets-pane, data-pf-show-when="field=value" on a field
 * shown only for a choice, and data-pf-person-search (data-url) / data-pf-person-results /
 * data-pf-person-id on the picker.
 *
 * Variables: $this->presets, presetUrls, managesPresets, roles (usertype => label, from the
 * board's floor up), organizations (id => name; none, no such choice) and organizationLabel
 * (what the application calls one), peopleUrl, csrfField,
 * userBoard, boardUrls.
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$urls      = (array) ($this->presetUrls ?? []);
$boardUrls = (array) ($this->boardUrls ?? []);
$field     = (string) ($this->csrfField ?? '');
$types     = (array) ($this->roles ?? []);
$orgs      = (array) ($this->organizations ?? []);
// What the application calls one — an organisation, or its own word.
$orgWord   = $t((string) ($this->organizationLabel ?? 'Organisation'));
$own       = $this->userBoard ?? null;
$mine      = is_array($own) && !empty($own['mine']);
$admin     = !empty($this->managesPresets);
$shown = ['private' => $t('Only me'), 'role' => $t('From a user type up'), 'everyone' => $t('Everyone')];
if ($orgs !== []) {
    $shown = ['private' => $shown['private'], 'role' => $shown['role'], 'organization' => sprintf($t('Members of: %s'), $orgWord), 'everyone' => $shown['everyone']];
}
$typeLabel = static fn ($audience): string => ctype_digit((string) $audience) && isset($types[(int) $audience]) ? $t((string) $types[(int) $audience]) : (ctype_digit((string) $audience) && isset($orgs[(int) $audience]) ? (string) $orgs[(int) $audience] : (string) $audience);
$tabs = ['list' => $t('Presets'), 'save' => $t('Save as preset'), 'new' => $t('New dashboard')];
if ($mine) {
    $tabs['mine'] = $t('My dashboard');
}
if ($admin) {
    $tabs['copy'] = $t('Copy to a user');
}
/** The fields that say who sees something, for a form: the choice, then a type or an organisation. */
$whoSees = static function (array $now = []) use ($e, $t, $shown, $types, $orgs, $orgWord): string {
    $html = '<label class="form-label small mb-1 d-block">' . $e($t('Who sees it')) . '<select name="visibility" class="form-select form-select-sm">';
    foreach ($shown as $value => $label) {
        $html .= '<option value="' . $e($value) . '"' . (($now['visibility'] ?? 'private') === $value ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label class="form-label small mb-1 d-block" data-pf-show-when="visibility=role">' . $e($t('From this user type up')) . '<select name="audience_role" class="form-select form-select-sm">';
    foreach ($types as $level => $label) {
        $html .= '<option value="' . (int) $level . '"' . ((string) ($now['audience'] ?? '') === (string) $level ? ' selected' : '') . '>' . $e($t((string) $label)) . '</option>';
    }
    $html .= '</select></label>';
    if ($orgs !== []) {
        $html .= '<label class="form-label small mb-1 d-block" data-pf-show-when="visibility=organization">' . $e($orgWord) . '<select name="audience_org" class="form-select form-select-sm">';
        foreach ($orgs as $id => $name) {
            $html .= '<option value="' . $e($id) . '"' . ((string) ($now['audience'] ?? '') === (string) $id ? ' selected' : '') . '>' . $e($name) . '</option>';
        }
        $html .= '</select></label>';
    }

    return $html;
}; @endphp
<dialog class="border-0 rounded-3 shadow p-0 pf-dashboard-presets-dialog" data-pf-dashboard-presets-dialog aria-labelledby="pf-dashboard-presets-title">
    <div class="pf-dashboard-presets">
        <h3 id="pf-dashboard-presets-title">{{ $t('Presets and dashboards') }}</h3>
        <div class="nav nav-tabs mb-3" role="tablist" data-pf-presets-tabs data-active-class="active">
            @php $first = true; foreach ($tabs as $key => $label): @endphp
            <button type="button" role="tab" class="nav-link{!! $first ? ' active' : '' !!}" data-pf-presets-tab="{{ $key }}" aria-selected="{!! $first ? 'true' : 'false' !!}">{{ $label }}</button>
            @php $first = false; endforeach; @endphp
        </div>

        <div role="tabpanel" data-pf-presets-pane="list">
            @if (empty($this->presets))
            <p class="small text-body-secondary">{{ $t('No presets yet.') }}</p>
            @else
            <ul class="pf-dashboard-presets-list">
                @foreach ($this->presets as $preset)
                <li class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom" data-pf-dashboard-preset="{{ $preset['id'] }}">
                    <div style="flex:1;min-width:10rem">
                        <strong>{{ $preset['kind'] === 'app' ? $t((string) $preset['name']) : $preset['name'] }}</strong>
                        <div class="small text-body-secondary">
                            {{ $preset['kind'] === 'app' ? $t('From the application') : ($preset['mine'] ? $t('Yours') : $t('Shared with you')) }}
                            @if ($preset['kind'] === 'saved') · {{ $shown[$preset['visibility']] ?? $t((string) $preset['visibility']) }}{!! $preset['audience'] !== null ? ' (' . $e($typeLabel($preset['audience'])) . ')' : '' !!}@endif
                            @if (!empty($preset['default_for_role'])) · <span class="badge text-bg-secondary">{{ sprintf($t('Default for %s'), $typeLabel($preset['default_for_role'])) }}</span>@endif
                        </div>
                    </div>
                    <form method="post" action="{{ $urls['apply'] ?? '' }}">{!! $field !!}
                        <input type="hidden" name="_tab" value="list"><input type="hidden" name="preset" value="{{ $preset['id'] }}">
                        <button type="submit" class="btn btn-sm btn-primary" data-confirm="{{ $t('Replace your dashboard with this preset?') }}">{{ $t('Apply') }}</button>
                    </form>
                    @if ($preset['kind'] === 'saved' && ($preset['mine'] || $admin))
                    <form method="post" action="{{ $urls['delete'] ?? '' }}">{!! $field !!}
                        <input type="hidden" name="_tab" value="list"><input type="hidden" name="preset" value="{{ $preset['id'] }}">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="{{ $t('Delete this preset?') }}">{{ $t('Delete') }}</button>
                    </form>
                    @endif
                    @if ($preset['kind'] === 'saved' && $admin)
                    <form method="post" action="{{ $urls['default'] ?? '' }}" class="pf-dashboard-inline-form">{!! $field !!}
                        <input type="hidden" name="_tab" value="list"><input type="hidden" name="preset" value="{{ $preset['id'] }}">
                        <select name="role" class="form-select form-select-sm" aria-label="{{ $t('Default for a user type') }}" style="width:auto">
                            <option value="">{{ $t('No user type') }}</option>
                            @foreach ($types as $level => $label)
                            <option value="{!! (int) $level !!}"{!! (string) ($preset['default_for_role'] ?? '') === (string) $level ? ' selected' : '' !!}>{{ $t((string) $label) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $t('Set as default') }}</button>
                    </form>
                    @endif
                </li>
                @endforeach
            </ul>
            @endif
        </div>

        <div role="tabpanel" data-pf-presets-pane="save" hidden>
            <form method="post" action="{{ $urls['save'] ?? '' }}" class="pf-dashboard-form">{!! $field !!}
                <input type="hidden" name="_tab" value="save">
                <label class="form-label small mb-1 d-block">{{ $t('Name') }}<input type="text" name="name" required maxlength="120" class="form-control form-control-sm"></label>
                {!! $whoSees() !!}
                <div><button type="submit" class="btn btn-sm btn-primary">{{ $t('Save as preset') }}</button></div>
            </form>
        </div>

        <div role="tabpanel" data-pf-presets-pane="new" hidden>
            <form method="post" action="{{ $boardUrls['new'] ?? '' }}" class="pf-dashboard-form">{!! $field !!}
                <input type="hidden" name="_tab" value="new">
                <label class="form-label small mb-1 d-block">{{ $t('Name') }}<input type="text" name="name" required maxlength="120" class="form-control form-control-sm"></label>
                <label class="form-label small mb-1 d-block">{{ $t('Start from') }}
                    <select name="preset" class="form-select form-select-sm">
                        <option value="">{{ $t('An empty dashboard') }}</option>
                        @foreach ((array) ($this->presets ?? []) as $preset)
                        <option value="{{ $preset['id'] }}">{{ $preset['kind'] === 'app' ? $t((string) $preset['name']) : $preset['name'] }}</option>
                        @endforeach
                    </select></label>
                <div><button type="submit" class="btn btn-sm btn-primary">{{ $t('Make it') }}</button></div>
            </form>
        </div>

        @if ($mine)
        <div role="tabpanel" data-pf-presets-pane="mine" hidden>
            <form method="post" action="{{ $boardUrls['settings'] ?? '' }}" class="pf-dashboard-form">{!! $field !!}
                <input type="hidden" name="_tab" value="mine">
                <label class="form-label small mb-1 d-block">{{ $t('Name') }}<input type="text" name="name" required maxlength="120" class="form-control form-control-sm" value="{{ $own['name'] }}"></label>
                {!! $whoSees(['visibility' => $own['visibility'] ?? 'private', 'audience' => $own['audience'] ?? '']) !!}
                <div><button type="submit" class="btn btn-sm btn-primary">{{ $t('Save') }}</button></div>
            </form>
            <form method="post" action="{{ $boardUrls['delete'] ?? '' }}" style="margin-top:1rem">{!! $field !!}
                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="{{ $t('Delete this dashboard, for everybody who has it?') }}">{{ $t('Delete this dashboard') }}</button>
            </form>
        </div>
        @endif

        @if ($admin)
        <div role="tabpanel" data-pf-presets-pane="copy" hidden>
            <form method="post" action="{{ $urls['copy'] ?? '' }}" class="pf-dashboard-form" data-pf-person-form>{!! $field !!}
                <input type="hidden" name="_tab" value="copy">
                <input type="hidden" name="userid" value="" data-pf-person-id>
                <label class="form-label small mb-1 d-block">{{ $t('The user') }}
                    <input type="search" class="form-control form-control-sm" autocomplete="off" data-pf-person-search data-url="{{ $this->peopleUrl ?? '' }}" placeholder="{{ $t('Name, username or email') }}"></label>
                <ul class="list-group pf-dashboard-people" data-pf-person-results data-pick-class="list-group-item list-group-item-action" hidden></ul>
                <p class="small text-body-secondary">{{ $t('Only active users who can open this dashboard are offered. Their dashboard is replaced with yours.') }}</p>
                <div><button type="submit" class="btn btn-sm btn-outline-secondary" data-pf-person-submit disabled data-confirm="{{ $t('Replace their dashboard with yours?') }}">{{ $t('Copy') }}</button></div>
            </form>
        </div>
        @endif

        <div class="pf-dashboard-presets-foot">
            @if ($admin && !empty($urls['list']))<a class="btn btn-sm btn-link" href="{{ $urls['list'] }}">{{ $t('All presets') }}</a>@endif
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pf-dashboard-presets-close>{{ $t('Close') }}</button>
        </div>
    </div>
</dialog>
