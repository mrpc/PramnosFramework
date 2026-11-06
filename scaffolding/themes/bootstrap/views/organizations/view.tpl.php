@php /**
 * One organization, read-only (Bootstrap theme).
 *
 * Variables:
 *   $this->org         — organization row array
 *   $this->members     — the most recent active members (up to ten)
 *   $this->memberCount — how many active members there are in total
 *
 * The list used to link straight to `edit`, so looking at a record meant opening the
 * form that changes it. Every field here is escaped: an organization's name and
 * description are operator-written text.
 */
$org   = is_array($this->org ?? null) ? $this->org : [];
$orgId = (int) ($org['organization_id'] ?? 0);
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when  = static function ($value) use ($e): string {
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
        return '—';
    }
    $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

    return $time > 0 ? $e(localDateTime( $time)) : $e($value);
};
$members = [];
if (is_iterable($this->members ?? null)) {
    foreach ($this->members as $member) {
        $members[] = (array) $member;
    }
}
$skip = ['organization_id', 'name', 'org_type', 'description', 'parent_id',
         'is_active', 'created_at', 'updated_at']; @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'organizations_view'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="{!! adminUrl('Organizations') !!}" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">{{ $org['name'] ?? 'Organization' }}</h2>
        @if ((int) ($org['is_active'] ?? 0) === 1)
        <span class="badge bg-success">Active</span>
        @else
        <span class="badge bg-secondary">Inactive</span>
        @endif
        <div class="ms-auto d-flex gap-2">
            <a href="{!! adminUrl('Organizations/edit/') . $orgId !!}" class="btn btn-sm btn-primary">Edit</a>
            <a href="{!! adminUrl('Organizations/members/') . $orgId !!}" class="btn btn-sm btn-outline-secondary">
                Members{!! $this->memberCount > 0 ? ' (' . (int) $this->memberCount . ')' : '' !!}
            </a>
            <form method="post" action="{!! adminUrl('Organizations/delete/') . $orgId !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger"
               data-confirm="Delete this organization?">Delete</button></form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header fw-semibold">Details</div>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:180px">ID</th><td>{!! $orgId !!}</td></tr>
                        <tr><th>Name</th><td>{{ $org['name'] ?? '' }}</td></tr>
                        <tr><th>Type</th><td>{!! $e($org['org_type'] ?? '') !== '' ? $e($org['org_type']) : '—' !!}</td></tr>
                        @if (array_key_exists('description', $org))
                        <tr><th>Description</th><td>{!! $e($org['description'] ?? '') !== '' ? nl2br($e($org['description'])) : '—' !!}</td></tr>
                        @endif
                        @if (array_key_exists('parent_id', $org))
                        <tr><th>Parent</th><td>
                            @php $parent = (int) ($org['parent_id'] ?? 0); @endphp
                            @if ($parent > 0)
                            <a href="{!! adminUrl('Organizations/view/') . $parent !!}">#{!! $parent !!}</a>
                            @else—@endif
                        </td></tr>
                        @endif
                        <tr><th>Created</th><td>{!! $when($org['created_at'] ?? null) !!}</td></tr>
                        @if (array_key_exists('updated_at', $org))
                        <tr><th>Updated</th><td>{!! $when($org['updated_at']) !!}</td></tr>
                        @endif
                        @foreach ($org as $column => $value)
                            @php if (in_array($column, $skip, true) || is_array($value) || is_object($value)) { continue; } @endphp
                        <tr><th>{{ ucwords(str_replace('_', ' ', (string) $column)) }}</th><td>{{ $value }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold">
                    Members <span class="badge bg-secondary">{!! (int) $this->memberCount !!}</span>
                </div>
                @if ($members === [])
                <div class="card-body text-muted">Nobody has been added to this organization yet.</div>
                @else
                <ul class="list-group list-group-flush">
                    @foreach ($members as $member)
                    <li class="list-group-item">
                        <a href="{!! adminUrl('Users/view/') . (int) ($member['userid'] ?? 0) !!}">
                            {{ $member['username'] ?? '' }}
                        </a>
                        <small class="text-muted">{{ $member['email'] ?? '' }}</small>
                    </li>
                    @endforeach
                </ul>
                @if ($this->memberCount > count($members))
                <div class="card-footer">
                    <a href="{!! adminUrl('Organizations/members/') . $orgId !!}">
                        …and {!! (int) $this->memberCount - count($members) !!} more
                    </a>
                </div>
                @endif
                @endif
            </div>
        </div>
    </div>
</div>
