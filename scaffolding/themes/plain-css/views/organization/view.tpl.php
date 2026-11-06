@php /**
 * One organisation, for the person who manages it (plain CSS theme).
 *
 * Variables:
 *   $this->organization_id, $this->name
 *   $this->members     — userid, username, email, name, roles[roleid, role_name, counts]
 *   $this->roles       — the organisation's roles: roleid, role_name, grantable
 *   $this->invitations — invitation_id, email, role_id, state, created_at, expires_at
 */
$orgId       = (int) ($this->organization_id ?? 0);
$members     = is_array($this->members ?? null) ? $this->members : [];
$roles       = is_array($this->roles ?? null) ? $this->roles : [];
$invitations = is_array($this->invitations ?? null) ? $this->invitations : [];
$givable     = array_values(array_filter($roles, static fn (array $r): bool => !empty($r['grantable'])));
$e           = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$post        = static fn (string $action): string => sURL . 'organization/' . $action . '/' . $orgId;
$roleSelect  = static function (string $name, string $blank) use ($givable, $e): string {
    $html = '<select name="' . $name . '" class="form-control" style="width:auto;display:inline-block"><option value="">' . $blank . '</option>';
    foreach ($givable as $r) {
        $html .= '<option value="' . (int) $r['roleid'] . '">' . $e($r['role_name']) . '</option>';
    }

    return $html . '</select>';
};
// Inside the account area: the same breadcrumb, sidebar and messages as every account page.
$this->activeNav = 'organization_view'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <p><a href="{!! sURL !!}organization">{{ t('Your organisations') }}</a></p>
            <h2 class="mb-3">{{ $this->name ?? '' }}</h2>

            <div class="card p-3 mb-3">
                <h3 class="mt-4">{{ t('Add somebody') }}</h3>
                <form method="post" action="{!! $post('add') !!}" class="d-flex" style="gap:8px;flex-wrap:wrap;align-items:center">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="email" name="email" required placeholder="{{ t('Email address') }}" class="form-control" style="width:auto;display:inline-block" style="max-width:280px">
                    {!! $roleSelect('roleid', $e(t('No role'))) !!}
                    <button type="submit" class="btn btn-primary">{{ t('Add') }}</button>
                </form>
                <p class="pf-muted small">{{ t('An address with an account becomes a member at once; one without is sent an invitation into the organisation.') }}</p>
            </div>

            <h3 class="mt-4">{{ t('Members') }}</h3>
            <table class="table table-hover">
                <thead><tr><th>{{ t('Member') }}</th><th>{{ t('Roles') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($members as $m) @php $uid = (int) $m['userid']; @endphp
                    <tr>
                        <td>{{ $m['name'] !== '' ? $m['name'] : $m['username'] }} <span class="pf-muted small">{{ $m['email'] }}</span></td>
                        <td>
                            @foreach ($m['roles'] as $r)
                                <span class="badge">{{ $r['role_name'] }}{!! empty($r['counts']) ? ' ' . $e(t('(does not count)')) : '' !!}</span>
                                @foreach ($givable as $g) @if ((int) $g['roleid'] === (int) $r['roleid'])
                                    <form method="post" action="{!! $post('takerole') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="userid" value="{!! $uid !!}"><input type="hidden" name="roleid" value="{!! (int) $r['roleid'] !!}"><button type="submit" class="btn btn-sm btn-outline-secondary" title="{{ t('Take this role away') }}" aria-label="{{ t('Take %s away', (string) $r['role_name']) }}">×</button></form>
                                @endif @endforeach
                            @endforeach
                            @if ($givable !== [])
                                <form method="post" action="{!! $post('giverole') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="userid" value="{!! $uid !!}">{!! $roleSelect('roleid', $e(t('Give a role…'))) !!}<button type="submit" class="btn btn-sm btn-outline-secondary">{{ t('Give') }}</button></form>
                            @endif
                        </td>
                        <td><form method="post" action="{!! $post('remove') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="userid" value="{!! $uid !!}"><button type="submit" class="btn btn-sm btn-danger" data-confirm="{{ t('Remove %s from the organisation?', (string) $m['email']) }}">{{ t('Remove') }}</button></form></td>
                    </tr>
                @endforeach
                @if ($members === [])
                    <tr><td colspan="3" class="pf-muted small">{{ t('No members yet.') }}</td></tr>
                @endif
                </tbody>
            </table>

            @if ($invitations !== [])
            <h3 class="mt-4">{{ t('Invitations') }}</h3>
            <table class="table table-hover">
                <thead><tr><th>{{ t('Address') }}</th><th>{{ t('State') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($invitations as $i)
                    <tr>
                        <td>{{ $i['email'] }}</td>
                        <td>{{ $i['state'] }}</td>
                        <td>@if (($i['state'] ?? '') === 'waiting')<form method="post" action="{!! $post('withdraw') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="invitation" value="{!! (int) $i['invitation_id'] !!}"><button type="submit" class="btn btn-sm btn-outline-secondary">{{ t('Withdraw') }}</button></form>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif

            @if (count($givable) < count($roles))
                <p class="pf-muted small">{{ t('Some of the organisation\'s roles allow things you are not allowed yourself, so you cannot give them.') }}</p>
            @endif
        </div>
    </div>
</div>
