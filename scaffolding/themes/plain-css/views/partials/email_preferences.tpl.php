@php /**
 * "Email" — what this account receives, and turning it on or off at the person's request.
 *
 * Inserted by the user's page. Data: `$this->emailPreferences` from
 * `UsersController::emailPreferencesFor()` — `all` (left everything) and `types`, each with
 * list, label, description, optIn and state (`on`/`off` for mail sent unless they say stop;
 * `off`, `pending`, `confirmed`, `unsubscribed` for an opt-in list).
 *
 * Turning an opt-in list on sends the confirmation mail: consent to marketing is the person's
 * to give, and confirming is how they give it.
 */
$_ep = $this->emailPreferences ?? null;
if (!is_array($_ep) || (empty($_ep['types']) && empty($_ep['all']))) {
    return;
}
$_e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_action = adminUrl('users/emailpreference/') . (int) ($this->user['userid'] ?? 0);
$_button = static function (string $list, string $state, string $label, string $confirm = '') use ($_action, $_e): string {
    return '<form method="post" action="' . $_e($_action) . '" style="display:inline;margin:0">'
        . \Pramnos\Http\Session::getInstance()->getTokenField()
        . '<input type="hidden" name="list" value="' . $_e($list) . '"><input type="hidden" name="state" value="' . $state . '">'
        . '<button type="submit" class="btn btn-sm btn-outline-secondary"' . ($confirm !== '' ? ' data-confirm="' . $_e($confirm) . '"' : '') . '>' . $label . '</button></form>';
};
$_words = ['on' => t('On'), 'off' => t('Off'), 'pending' => t('Waiting to confirm'), 'confirmed' => t('Subscribed'), 'unsubscribed' => t('Left')]; @endphp
<div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px" id="email-preferences">
    <div class="card-header">{!! $_e(t('Email')) !!}</div>
    <div class="card-body" style="padding:16px">
        @if (!empty($_ep['all']))
            <div role="status" class="alert alert-warning">
                {!! sprintf($_e(t('This address left %s optional mail.')), '<strong>' . $_e(t('every')) . '</strong>') !!}
                {!! $_e(t('Nothing below reaches it until that is cleared.')) !!}
                {!! $_button('all', 'on', $_e(t('Clear')), t('Only at the person\'s own request. Clear their unsubscribe from everything?')) !!}
            </div>
        @endif
        <table class="table">
            <thead><tr><th>{!! $_e(t('Mail')) !!}</th><th>{!! $_e(t('State')) !!}</th><th></th></tr></thead>
            <tbody>
            @foreach ($_ep['types'] as $_t) @php $_on = in_array($_t['state'], ['on', 'confirmed', 'pending'], true); @endphp
                <tr>
                    <td>{!! $_e($_t['label']) !!}@if (!empty($_t['optIn'])) <span class="pf-muted small">{!! $_e(t('opt-in list')) !!}</span>@endif
                        <div class="pf-muted small">{!! $_e($_t['description']) !!}</div></td>
                    <td>{!! $_e($_words[$_t['state']] ?? $_t['state']) !!}</td>
                    <td style="white-space:nowrap">
                        @if ($_on)
                            {!! $_button($_t['list'], 'off', $_e(t('Turn off'))) !!}
                        @elseif (!empty($_t['optIn']))
                            {!! $_button($_t['list'], 'on', $_e(t('Send confirmation')), t('Only at the person\'s own request. Send them the confirmation mail?')) !!}
                        @else
                            {!! $_button($_t['list'], 'on', $_e(t('Turn on')), t('Only at the person\'s own request. Turn this back on?')) !!}
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if (empty($_ep['all']))
            {!! $_button('all', 'off', $_e(t('Unsubscribe from everything optional')), t('Take this address off every optional mail?')) !!}
        @endif
    </div>
</div>
