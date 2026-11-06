{{-- Tokens for an application (Bootstrap theme).

Variables:
  $this->app    — application row array
  $this->tokens — iterable token rows --}}
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="{!! adminUrl('Applications') !!}" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Tokens — {{ $this->app['name'] ?? '' }}</h2>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Token ID</th><th>User ID</th><th>Scope</th><th>Last Used</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->tokens ?? []) as $tok)
                    <tr>
                        <td>{!! (int)$tok['tokenid'] !!}</td>
                        <td>{!! (int)($tok['userid'] ?? 0) !!}</td>
                        <td>{{ $tok['scope'] ?? '' }}</td>
                        <td class="text-muted small">{{ $tok['lastused'] ?? '' }}</td>
                        <td class="text-muted small">{!! !empty($tok['expires']) ? htmlspecialchars($tok['expires']) : '—' !!}</td>
                        <td class="text-end">
                            <form method="post" action="{!! adminUrl('Tokens' . '/revoke/' . ((int)$tok['tokenid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Revoke token?">Revoke</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->tokens))
                    <tr><td colspan="6" class="text-center text-muted py-4">No active tokens.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
