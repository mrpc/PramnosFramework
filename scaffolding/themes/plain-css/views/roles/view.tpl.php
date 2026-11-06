@php /**
 * One role (plain CSS theme).
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
<div class="page-section">
    @php $this->activeNav = 'roles_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2>{{ (string) $role->role_name }}</h2>
        <span style="display:flex;gap:8px">
            <a href="{!! adminUrl('Roles/edit/') . (int) $role->roleid !!}" class="btn btn-outline-secondary">Edit</a>
            <a href="{!! adminUrl('Roles/members/') . (int) $role->roleid !!}" class="btn btn-primary">Holders</a>
        </span>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Details</div>
        <div class="card-body" style="padding:16px">
            <div style="display:grid;grid-template-columns:10rem 1fr;gap:6px;font-size:14px">
                <div style="font-weight:600">ID</div><div>{!! (int) $role->roleid !!}</div>
                <div style="font-weight:600">Organisation</div><div>{{ (string) $this->organisation }}</div>
                <div style="font-weight:600">Description</div><div>{{ (string) ($role->description ?? '') }}</div>
                <div style="font-weight:600">Active</div><div>{!! ((int) $role->is_active) === 1 ? 'Yes' : 'No' !!}</div>
            </div>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Permissions granted to this role</div>
        <div class="card-body" style="padding:16px">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5;text-align:left">
                    <tr><th>Object</th><th>Instance</th><th>Action</th><th>Grant</th></tr>
                </thead>
                <tbody>
                @foreach (($this->permissions ?? []) as $p)
                    <tr>
                        <td>{{ (string) $p['object_type'] }}@if (isset($this->problems[(int) $p['permissionid']]))<span style="display:block;font-size:12px;color:#b00020">&#9888; {{ $this->problems[(int) $p['permissionid']] }}</span>@endif</td>
                        <td>{!! $p['object_id'] === null || $p['object_id'] === '' ? '<em>all</em>' : htmlspecialchars((string) $p['object_id'], ENT_QUOTES, 'UTF-8') !!}</td>
                        <td>{{ (string) $p['action'] }}</td>
                        <td>{{ (string) $p['grant_type'] }}</td>
                    </tr>
                @endforeach
                @if (empty($this->permissions))
                    <tr><td colspan="4" style="text-align:center;color:#777;padding:24px">This role grants nothing yet.
                        Add entries from the Permissions screen with subject
                        <code>role</code> and id {!! (int) $role->roleid !!}.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
    @include('../partials/admin_screens')
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Held by {!! count($this->holders ?? []) !!} user(s)</div>
        <div class="card-body" style="padding:16px">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5;text-align:left"><tr><th>User ID</th><th>Username</th><th>Email</th></tr></thead>
                <tbody>
                @foreach (($this->holders ?? []) as $h)
                    <tr>
                        <td>{!! (int) $h['userid'] !!}</td>
                        <td>{{ (string) $h['username'] }}</td>
                        <td>{{ (string) $h['email'] }}</td>
                    </tr>
                @endforeach
                @if (empty($this->holders))
                    <tr><td colspan="3" style="text-align:center;color:#777;padding:24px">Nobody holds this role.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
