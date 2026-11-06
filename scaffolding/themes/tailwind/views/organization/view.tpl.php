@php /**
 * One organisation, for the person who manages it (Tailwind theme).
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
    $html = '<select name="' . $name . '" class="select select-sm w-auto"><option value="">' . $blank . '</option>';
    foreach ($givable as $r) {
        $html .= '<option value="' . (int) $r['roleid'] . '">' . $e($r['role_name']) . '</option>';
    }

    return $html . '</select>';
};
// Inside the account area: the same breadcrumb, sidebar and messages as every account page.
$this->activeNav = 'organization_view'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success mb-4">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3 min-w-0">
            <p><a href="{!! sURL !!}organization">{{ t('Your organisations') }}</a></p>
            <h2 class="text-lg font-semibold mb-4">{{ $this->name ?? '' }}</h2>

            <div class="card bg-base-100 border border-base-300 p-4 mb-4">
                <h3 class="font-medium mt-6 mb-2">{{ t('Add somebody') }}</h3>
                <form method="post" action="{!! $post('add') !!}" class="flex flex-wrap gap-2 items-center">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="email" name="email" required placeholder="{{ t('Email address') }}" class="input input-sm" style="max-width:280px">
                    {!! $roleSelect('roleid', $e(t('No role'))) !!}
                    <button type="submit" class="btn btn-primary btn-sm">{{ t('Add') }}</button>
                </form>
                <p class="text-sm opacity-70">{{ t('An address with an account becomes a member at once; one without is sent an invitation into the organisation.') }}</p>
            </div>

            <h3 class="font-medium mt-6 mb-2">{{ t('Members') }}</h3>
            <table class="table w-full">
                <thead><tr><th>{{ t('Member') }}</th><th>{{ t('Roles') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($members as $m) @php $uid = (int) $m['userid']; @endphp
                    <tr>
                        <td>{{ $m['name'] !== '' ? $m['name'] : $m['username'] }} <span class="text-sm opacity-70">{{ $m['email'] }}</span></td>
                        <td>
                            @foreach ($m['roles'] as $r)
                                <span class="badge badge-ghost mr-1">{{ $r['role_name'] }}{!! empty($r['counts']) ? ' ' . $e(t('(does not count)')) : '' !!}</span>
                                @foreach ($givable as $g) @if ((int) $g['roleid'] === (int) $r['roleid'])
                                    <form method="post" action="{!! $post('takerole') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="userid" value="{!! $uid !!}"><input type="hidden" name="roleid" value="{!! (int) $r['roleid'] !!}"><button type="submit" class="btn btn-outline btn-xs" title="{{ t('Take this role away') }}" aria-label="{{ t('Take %s away', (string) $r['role_name']) }}">×</button></form>
                                @endif @endforeach
                            @endforeach
                            @if ($givable !== [])
                                <form method="post" action="{!! $post('giverole') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="userid" value="{!! $uid !!}">{!! $roleSelect('roleid', $e(t('Give a role…'))) !!}<button type="submit" class="btn btn-outline btn-xs">{{ t('Give') }}</button></form>
                            @endif
                        </td>
                        <td><form method="post" action="{!! $post('remove') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="userid" value="{!! $uid !!}"><button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="{{ t('Remove %s from the organisation?', (string) $m['email']) }}">{{ t('Remove') }}</button></form></td>
                    </tr>
                @endforeach
                @if ($members === [])
                    <tr><td colspan="3" class="text-sm opacity-70">{{ t('No members yet.') }}</td></tr>
                @endif
                </tbody>
            </table>

            @if ($invitations !== [])
            <h3 class="font-medium mt-6 mb-2">{{ t('Invitations') }}</h3>
            <table class="table w-full">
                <thead><tr><th>{{ t('Address') }}</th><th>{{ t('State') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($invitations as $i)
                    <tr>
                        <td>{{ $i['email'] }}</td>
                        <td>{{ $i['state'] }}</td>
                        <td>@if (($i['state'] ?? '') === 'waiting')<form method="post" action="{!! $post('withdraw') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="invitation" value="{!! (int) $i['invitation_id'] !!}"><button type="submit" class="btn btn-outline btn-xs">{{ t('Withdraw') }}</button></form>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif

            @if (count($givable) < count($roles))
                <p class="text-sm opacity-70">{{ t("Some of the organisation's roles allow things you are not allowed yourself, so you cannot give them.") }}</p>
            @endif
        </div>
    </div>
</div>
