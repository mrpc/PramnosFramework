@php /**
 * Who holds a role (plain CSS theme).
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
<div class="page-section">
    @php $this->activeNav = 'roles_members'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div style="display:flex;gap:8px" style="margin-bottom:16px;align-items:center">
        <a href="{!! adminUrl('Roles') !!}" class="btn btn-outline-secondary">&larr; Back</a>
        <h2>Holders — {{ (string) $role->role_name }}</h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Add a holder</div>
        <div class="card-body" style="padding:16px">
            @if ($orgId > 0)
                <p>This role belongs to
                <strong>{{ (string) $this->organisation }}</strong>,
                so only members of that organisation can hold it.</p>
            @endif
            <form method="post" action="{!! adminUrl('Roles/addmember/') . $rid !!}" style="display:flex;gap:8px">
                {!! \Pramnos\Http\Middleware\CsrfMiddleware::tokenField() !!}
                <input type="number" name="userid" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success">Add</button>
            </form>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5;text-align:left">
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
                               class="btn btn-outline-danger" data-confirm="Remove this role from the user?">Remove</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->holders))
                    <tr><td colspan="5" style="text-align:center;color:#777;padding:24px">Nobody holds this role.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
