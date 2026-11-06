@php /**
 * User token management view (Bootstrap theme).
 *
 * Variables:
 *   $this->user      — ['userid', 'username']
 *   $this->tokenList — array of token rows from User::getAllTokens()
 */
$user   = $this->user ?? [];
$tokens = $this->tokenList ?? [];
$uid    = (int) ($user['userid'] ?? 0);

$statusLabel = function (int $s): string {
    $map = [0 => ['secondary', 'Inactive'], 1 => ['success', 'Active'], 2 => ['dark', 'Deleted'], 3 => ['danger', 'Revoked']];
    [$cls, $txt] = $map[$s] ?? ['secondary', 'Unknown'];
    return '<span class="badge bg-' . $cls . '">' . $txt . '</span>';
}; @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'users_tokens'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Tokens — {{ $user['username'] ?? '' }}</h2>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Created</th>
                        <th>Last Used</th>
                        <th>Expires</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($tokens as $tok)
                    @php $tokenId = (int) ($tok['tokenid'] ?? 0);
                    $status  = (int) ($tok['status']  ?? 0);
                    $exp     = (int) ($tok['expires']  ?? 0);
                    $isExpired = $exp > 0 && $exp < time();
                    $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . $tokenId . '&from=user&uid=' . $uid; @endphp
                    <tr class="{!! $isExpired ? 'table-warning' : '' !!}" style="cursor:pointer"
                        data-href="{!! $tokActionsUrl !!}" title="View token actions">
                        <td><code class="small">{!! $tokenId !!}</code></td>
                        <td><span class="badge bg-secondary">{{ $tok['tokentype'] ?? 'auth' }}</span></td>
                        <td>{!! $statusLabel($status) !!}</td>
                        <td class="small text-muted">{{ $tok['ipaddress'] ?? '—' }}</td>
                        <td class="small">{!! ($tok['created'] ?? 0) > 0 ? localDateTime((int)$tok['created']) : '—' !!}</td>
                        <td class="small">{!! ($tok['lastused'] ?? 0) > 0 ? localDateTime((int)$tok['lastused']) : '—' !!}</td>
                        <td class="small {!! $isExpired ? 'text-warning' : '' !!}">
                            {!! $exp > 0 ? localDateTime($exp) . ($isExpired ? ' <em>(expired)</em>' : '') : 'Never' !!}
                        </td>
                        <td class="text-end">
                            @if ($status === 1)
                                <form method="post" action="{!! adminUrl('Tokens/deactivate') !!}" style="display:inline">
                                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                    <input type="hidden" name="userid" value="{!! $uid !!}">
                                    <input type="hidden" name="tokenid" value="{!! $tokenId !!}">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Deactivate</button>
                                </form>
                            @endif
                            <form method="post" action="{!! adminUrl('Tokens/delete') !!}" style="display:inline">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <input type="hidden" name="userid" value="{!! $uid !!}">
                                <input type="hidden" name="tokenid" value="{!! $tokenId !!}">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Delete this token permanently?">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($tokens))
                    <tr><td colspan="8" class="text-center text-muted py-4">No tokens found for this user.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
