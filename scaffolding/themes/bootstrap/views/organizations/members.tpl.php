{{-- Organization members (Bootstrap theme).

Variables:
  $this->org     — organization row array
  $this->members — iterable user rows --}}
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="{!! adminUrl('Organizations') !!}" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Members — {{ $this->org['name'] ?? '' }}</h2>
    </div>
    <div class="card mb-3">
        <div class="card-header fw-semibold">Add Member</div>
        <div class="card-body">
            <form method="post" action="{!! adminUrl('Organizations/addmember/') !!}{!! (int)($this->org['organization_id'] ?? 0) !!}" class="d-flex gap-2">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="number" name="userid" class="form-control" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success">Add</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->members ?? []) as $m)
                    <tr>
                        <td>{!! (int)$m['userid'] !!}</td>
                        <td>{{ $m['username'] ?? '' }}</td>
                        <td>{{ $m['email'] ?? '' }}</td>
                        <td class="text-end">
                            <form method="post" action="{!! adminUrl('Organizations/removemember/') !!}{!! (int)($this->org['organization_id'] ?? 0) !!}?userid={!! (int)$m['userid'] !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove member?">Remove</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->members))
                    <tr><td colspan="4" class="text-center text-muted py-4">No members.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
