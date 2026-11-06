{{-- Permissions / RBAC grants list (Tailwind theme).

Variables:
  $this->permissions — iterable rows
  $this->problems    — permissionid => why that grant no longer matches what its application declares
  $this->page        — current page
  $this->total       — total count --}}
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >Permissions</h2>
        <a href="{!! adminUrl('Permissions/edit') !!}" class="btn btn-primary btn-sm">+ New Permission</a>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>ID</th><th>Subject</th><th>Object Type</th><th>Action</th><th>Grant</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->permissions ?? []) as $p)
                    <tr>
                        <td>{!! (int)$p['permissionid'] !!}</td>
                        <td>
                            <span class="badge badge-neutral">{{ $p['subject_type'] ?? '' }}</span>
                            #{{ (string)($p['subject_id'] ?? '') }}
                        </td>
                        <td>{{ $p['object_type'] ?? '' }}@if (isset($this->problems[(int) $p['permissionid']]))<span class="block text-xs text-error">&#9888; {{ $this->problems[(int) $p['permissionid']] }}</span>@endif</td>
                        <td><code>{{ $p['action'] ?? '' }}</code></td>
                        <td>
                            {!! ($p['grant_type'] ?? 'allow') === 'allow' ? '<span class="badge badge-success">Allow</span>' : '<span class="badge badge-error">Deny</span>' !!}
                        </td>
                        <td class="text-right">
                            <a href="{!! adminUrl('Permissions' . '/edit/' . ((int)$p['permissionid'])) !!}" class="btn btn-outline btn-xs">Edit</a>
                            <form method="post" action="{!! adminUrl('Permissions' . '/delete/' . ((int)$p['permissionid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Delete permission?">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->permissions))
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">No permissions found.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
