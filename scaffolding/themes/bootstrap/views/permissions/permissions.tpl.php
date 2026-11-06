{{-- Permissions / RBAC grants list (Bootstrap theme).

Variables:
  $this->permissions — iterable rows
  $this->problems    — permissionid => why that grant no longer matches what its application declares
  $this->page        — current page
  $this->total       — total count --}}
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Permissions</h2>
        <a href="{!! adminUrl('Permissions/edit') !!}" class="btn btn-primary">+ New Permission</a>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
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
                        <td>{{ $p['object_type'] ?? '' }}@if (isset($this->problems[(int) $p['permissionid']]))<span class="d-block small text-danger">&#9888; {{ $this->problems[(int) $p['permissionid']] }}</span>@endif</td>
                        <td><code>{{ $p['action'] ?? '' }}</code></td>
                        <td>
                            {!! ($p['grant_type'] ?? 'allow') === 'allow' ? '<span class="badge bg-success">Allow</span>' : '<span class="badge bg-danger">Deny</span>' !!}
                        </td>
                        <td class="text-end">
                            <a href="{!! adminUrl('Permissions' . '/edit/' . ((int)$p['permissionid'])) !!}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="post" action="{!! adminUrl('Permissions' . '/delete/' . ((int)$p['permissionid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete permission?">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->permissions))
                    <tr><td colspan="6" class="text-center text-muted py-4">No permissions found.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
