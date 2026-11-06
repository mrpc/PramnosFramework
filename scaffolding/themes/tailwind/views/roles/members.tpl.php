@php /**
 * Who holds a role (Tailwind theme).
 *
 * Variables:
 *   $this->role         — \Pramnos\Auth\Role
 *   $this->organisation — "System-wide" or the organisation's name
 *   $this->holders      — [userid, username, email, granted_at]
 *
 * Adding somebody to an organisation's role is refused unless they are already a
 * member of it. The refusal arrives as a flash message saying so.
 */
$role  = $this->role;
$rid   = (int) $role->roleid;
$orgId = (int) ($role->organization_id ?? 0); @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'roles_members'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success mb-4">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-error mb-4">{{ $this->error }}</div>
    @endif
    <div class="flex gap-2" style="margin-bottom:16px;align-items:center">
        <a href="{!! adminUrl('Roles') !!}" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2>Holders — {{ (string) $role->role_name }}</h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Add a holder</div>
        <div class="p-5">
            @if ($orgId > 0)
                <p>This role belongs to
                <strong>{{ (string) $this->organisation }}</strong>,
                so only members of that organisation can hold it.</p>
            @endif
            <form method="post" action="{!! adminUrl('Roles/addmember/') . $rid !!}" class="flex gap-2">
                {!! \Pramnos\Http\Middleware\CsrfMiddleware::tokenField() !!}
                <input type="number" name="userid" class="input input-sm w-full" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success btn-sm">Add</button>
            </form>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="p-5">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th>Granted</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->holders ?? []) as $h)
                    <tr>
                        <td>{!! (int) $h['userid'] !!}</td>
                        <td>{{ (string) $h['username'] }}</td>
                        <td>{{ (string) $h['email'] }}</td>
                        <td>{{ (string) $h['granted_at'] }}</td>
                        <td style="text-align:right">
                            <form method="post" action="{!! adminUrl('Roles/removemember/') . $rid !!}?userid={!! (int) $h['userid'] !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                               class="btn btn-outline btn-error btn-xs" data-confirm="Remove this role from the user?">Remove</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->holders))
                    <tr><td colspan="5" class="text-center text-base-content/60 py-8">Nobody holds this role.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
