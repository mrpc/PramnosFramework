{{-- Permissions / RBAC grants list (plain-CSS theme).

Variables:
  $this->permissions — iterable rows
  $this->problems    — permissionid => why that grant no longer matches what its application declares
  $this->page        — current page
  $this->total       — total count --}}
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Permissions</h2>
        <a href="{!! adminUrl('Permissions/edit') !!}" class="btn btn-primary">+ New Permission</a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>Subject</th><th>Object Type</th><th>Action</th><th>Grant</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->permissions ?? []) as $p)
                    <tr>
                        <td>{!! (int)$p['permissionid'] !!}</td>
                        <td>
                            <span class="badge bg-secondary">{{ $p['subject_type'] ?? '' }}</span>
                            #{{ (string)($p['subject_id'] ?? '') }}
                        </td>
                        <td>{{ $p['object_type'] ?? '' }}@if (isset($this->problems[(int) $p['permissionid']]))<span style="display:block;font-size:12px;color:#b00020">&#9888; {{ $this->problems[(int) $p['permissionid']] }}</span>@endif</td>
                        <td><code>{{ $p['action'] ?? '' }}</code></td>
                        <td>
                            {!! ($p['grant_type'] ?? 'allow') === 'allow' ? '<span class="badge bg-success">Allow</span>' : '<span class="badge bg-danger">Deny</span>' !!}
                        </td>
                        <td style="text-align:right">
                            <a href="{!! adminUrl('Permissions' . '/edit/' . ((int)$p['permissionid'])) !!}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="post" action="{!! adminUrl('Permissions' . '/delete/' . ((int)$p['permissionid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete permission?">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->permissions))
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No permissions found.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
