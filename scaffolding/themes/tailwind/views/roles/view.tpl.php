@php /**
 * One role (Tailwind theme).
 *
 * Variables:
 *   $this->role         — \Pramnos\Auth\Role
 *   $this->organisation — "System-wide" or the organisation's name
 *   $this->permissions  — permission rows granted to this role
 *   $this->problems     — permissionid => why that grant no longer matches what its application declares
 *   $this->holders      — [userid, username, email, granted_at]
 *
 * The permissions are here because a role is otherwise an opaque name: "operator"
 * tells nobody what an operator may do.
 */
$role = $this->role; @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'roles_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="flex justify-between items-center mb-4">
        <h2>{{ (string) $role->role_name }}</h2>
        <span class="flex gap-2">
            <a href="{!! adminUrl('Roles/edit/') . (int) $role->roleid !!}" class="btn btn-outline btn-sm">Edit</a>
            <a href="{!! adminUrl('Roles/members/') . (int) $role->roleid !!}" class="btn btn-primary btn-sm">Holders</a>
        </span>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Details</div>
        <div class="p-5">
            <div class="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                <div class="font-medium">ID</div><div>{!! (int) $role->roleid !!}</div>
                <div class="font-medium">Organisation</div><div>{{ (string) $this->organisation }}</div>
                <div class="font-medium">Description</div><div>{{ (string) ($role->description ?? '') }}</div>
                <div class="font-medium">Active</div><div>{!! ((int) $role->is_active) === 1 ? 'Yes' : 'No' !!}</div>
            </div>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Permissions granted to this role</div>
        <div class="p-5">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Object</th><th>Instance</th><th>Action</th><th>Grant</th></tr>
                </thead>
                <tbody>
                @foreach (($this->permissions ?? []) as $p)
                    <tr>
                        <td>{{ (string) $p['object_type'] }}@if (isset($this->problems[(int) $p['permissionid']]))<span class="block text-xs text-error">&#9888; {{ $this->problems[(int) $p['permissionid']] }}</span>@endif</td>
                        <td>{!! $p['object_id'] === null || $p['object_id'] === '' ? '<em>all</em>' : htmlspecialchars((string) $p['object_id'], ENT_QUOTES, 'UTF-8') !!}</td>
                        <td>{{ (string) $p['action'] }}</td>
                        <td>{{ (string) $p['grant_type'] }}</td>
                    </tr>
                @endforeach
                @if (empty($this->permissions))
                    <tr><td colspan="4" class="text-center text-base-content/60 py-8">This role grants nothing yet.
                        Add entries from the Permissions screen with subject
                        <code>role</code> and id {!! (int) $role->roleid !!}.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
    @include('../partials/admin_screens')
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Held by {!! count($this->holders ?? []) !!} user(s)</div>
        <div class="p-5">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase"><tr><th>User ID</th><th>Username</th><th>Email</th></tr></thead>
                <tbody>
                @foreach (($this->holders ?? []) as $h)
                    <tr>
                        <td>{!! (int) $h['userid'] !!}</td>
                        <td>{{ (string) $h['username'] }}</td>
                        <td>{{ (string) $h['email'] }}</td>
                    </tr>
                @endforeach
                @if (empty($this->holders))
                    <tr><td colspan="3" class="text-center text-base-content/60 py-8">Nobody holds this role.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
