@php /**
 * Tokens list (plain-CSS theme).
 *
 * Variables:
 *   $this->tokens — iterable rows
 *   $this->page   — current page
 *   $this->total  — total count
 */
$this->activeNav = 'tokens'; @endphp
<div class="page-section">
    @include('../partials/admin_breadcrumb')
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >OAuth2 Tokens</h2>
        <form method="get" data-pf-tool="list_api_tokens" data-pf-tool-description="List the API tokens issued, optionally only those of one user or one application." style="display:flex;gap:8px">
            <input type="number" name="user_id" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="User ID" value="{!! (int)($_GET['user_id'] ?? 0) ?: '' !!}">
            <input type="number" name="app_id" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="App ID" value="{!! (int)($_GET['app_id'] ?? 0) ?: '' !!}">
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <style>
    .pf-table{width:100%;border-collapse:collapse}
    .pf-table th,.pf-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .pf-table thead th{background:#f5f5f5;border-bottom:1px solid #e5e5e5;font-weight:600}
    .pf-table td:last-child,.pf-table th:last-child{text-align:right}
    .pf-table tbody tr.pf-clickable{cursor:pointer}
    .pf-table tbody tr.pf-clickable:hover{background:#f8f9fa}
    </style>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:0">
            <table class="pf-table">
                <thead>
                    <tr><th>ID</th><th>User</th><th>Application</th><th>Scope</th><th>Last Used</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->tokens ?? []) as $tok)
                    @php // The row opens the token's own screen — everything about it, with its
                    // actions on the same page. The actions list stays one click away.
                    $tokenUrl = adminUrl('Tokens/view/') . (int) $tok['tokenid']; @endphp
                    <tr class="pf-clickable" data-href="{!! $tokenUrl !!}" title="Open this token">
                        <td><a class="link" href="{!! $tokenUrl !!}">{!! (int)$tok['tokenid'] !!}</a></td>
                        <td>{{ $tok['username'] ?? '' }}</td>
                        <td>{{ $tok['app_name'] ?? ('— ' . ($tok['tokentype'] ?? '')) }}</td>
                        <td>@php $sc = trim((string) ($tok['scope'] ?? ''));
                            echo ($sc === '' || $sc === '[]') ? '—' : htmlspecialchars($sc); @endphp</td>
                        <td style="color:#888;font-size:0.8em">@php $lu = (int) ($tok['lastused'] ?? 0);
                            echo $lu > 0 ? htmlspecialchars(localDateTime( $lu)) : '—'; @endphp</td>
                        <td>
                            {!! (int)($tok['status'] ?? 1) === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Revoked</span>' !!}
                        </td>
                        <td style="text-align:right">
                            <form method="post" action="{!! adminUrl('Tokens' . '/revoke/' . ((int)$tok['tokenid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Revoke token?">Revoke</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->tokens))
                    <tr><td colspan="7" style="text-align:center;color:#888;padding:24px">No tokens found.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
