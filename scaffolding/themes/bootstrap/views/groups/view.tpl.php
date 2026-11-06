@php /**
 * One user group: its name and description, how many are in it, its order and what uses it; the
 * members a page at a time, with adding and removing; and the roles it holds, where groups are
 * given roles here. The same markup in every theme: it is dressed by the `pf-*` rules in
 * components.css.
 *
 * Variables:
 *   $this->group          — usergroups row
 *   $this->members        — \Pramnos\Html\Datatable of the members, server-side from `Groups/members/{id}`
 *   $this->count          — how many members it has
 *   $this->uses           — what uses it: [name, url|null] (`Audience\Groups::uses()`)
 *   $this->membersUrl     — the application's own list of its members, or null (`audience.group_members_url`)
 *   $this->rolesEnabled   — whether the roles section is shown: the roles store, a role to hold or give, and not `audience.group_roles => false`
 *   $this->roles          — roles the group holds (roleid, role_name, description)
 *   $this->availableRoles — active roles it does not hold
 */
use Pramnos\Html\Icon;

$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$group = $this->group ?? [];
$gid   = (int) ($group['groupid'] ?? 0);
$uses  = (array) ($this->uses ?? []); @endphp
<div class="pf-ui">
    @php $this->activeNav = 'groups_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $group['name'] ?? '' }}</h1>
            @if (trim((string) ($group['description'] ?? '')) !== '')<p class="pf-page-sub">{{ $group['description'] }}</p>@endif
        </div>
        <div class="pf-page-actions">
            <form method="post" action="{{ adminUrl('Groups/delete/') . $gid }}" class="pf-inline-form" data-confirm="{{ t('Delete this group? Its members keep their accounts.') }}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger" {!! $uses === [] ? '' : 'disabled title="' . $e(t('Used by: %s', implode(', ', array_column($uses, 'name')))) . '"' !!}>{{ t('Delete') }}</button>
            </form>
            @if (!empty($this->membersUrl))
                <a href="{{ $this->membersUrl }}" class="pf-btn">{!! Icon::svg('members', '') !!}{{ t('Members in the user list') }}</a>
            @endif
            <a href="{{ adminUrl('Groups/edit/') . $gid }}" class="pf-btn pf-btn-primary">{!! Icon::svg('edit', '') !!}{{ t('Edit') }}</a>
        </div>
    </header>

    <div class="pf-card"><div class="pf-card-body pf-stats">
        <div><span class="pf-label">{{ t('Members in it') }}</span><span class="pf-stat-value" data-field="members">{{ number_format((int) ($this->count ?? 0)) }}</span></div>
        <div><span class="pf-label">{{ t('Order in the list') }}</span><span class="pf-stat-value">{!! isset($group['order']) && $group['order'] !== null ? (int) $group['order'] : '—' !!}</span></div>
        <div><span class="pf-label">{{ t('Used by') }}</span>
            @if ($uses === [])
                <span class="pf-muted">{{ t('Nothing uses it.') }}</span>
            @else
                <span class="pf-chips">@foreach ($uses as $use){!! $use['url'] !== null ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>' : '<span class="pf-chip">' . $e($use['name']) . '</span>' !!}@endforeach</span>
            @endif
        </div>
    </div></div>

    <div class="pf-card pf-mt"><div class="pf-card-body">
        <form method="post" action="{{ adminUrl('Groups/addmember/') . $gid }}" class="pf-rule-sentence">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <label class="pf-visually-hidden" for="group-member">{{ t('Add a member to the group') }}</label>
            <input type="text" id="group-member" name="user" required class="pf-input" placeholder="{{ t('Username, email or account id') }}">
            <button type="submit" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('Add to the group') }}</button>
        </form>
    </div></div>

    <div class="pf-card pf-card-flush pf-mt">
        {!! $this->members->render() !!}
    </div>

    @if (!empty($this->rolesEnabled))
    <div class="pf-card pf-mt"><div class="pf-card-body">
        <h2 class="pf-section-title">{{ t('Roles the group holds') }}</h2>
        <p class="pf-muted">{{ t('Every member holds these roles, as if each had been given them directly.') }}</p>
        @if (empty($this->roles))
            <p class="pf-muted">{{ t('This group holds no roles.') }}</p>
        @else
            <table class="pf-table">
                <thead><tr><th>{{ t('Role name') }}</th><th>{{ t('What it is for') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($this->roles as $r)
                    <tr>
                        <td>{{ $r['role_name'] ?? '' }}</td>
                        <td>{{ $r['description'] ?? '' }}</td>
                        <td class="pf-num">
                            <form method="post" action="{{ adminUrl('Groups/removerole/') . $gid }}" class="pf-inline-form" data-confirm="{{ t('Withdraw this role from every member of the group?') }}">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <input type="hidden" name="roleid" value="{!! (int) $r['roleid'] !!}">
                                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger">{{ t('Withdraw the role') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @if (!empty($this->availableRoles))
            <form method="post" action="{{ adminUrl('Groups/addrole/') . $gid }}" class="pf-rule-sentence pf-mt">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <label class="pf-visually-hidden" for="group-role">{{ t('Give the group a role') }}</label>
                <select id="group-role" name="roleid" required class="pf-input">
                    @foreach ($this->availableRoles as $r)
                        <option value="{!! (int) $r['roleid'] !!}">{{ $r['role_name'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="pf-btn">{{ t('Give the role') }}</button>
            </form>
        @endif
    </div></div>
    @endif
</div>
