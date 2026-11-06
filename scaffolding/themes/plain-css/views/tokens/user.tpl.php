@php /**
 * Per-user token management view (plain-CSS theme).
 *
 * Served by Pramnos\Auth\Controllers\TokensController::userid() as the unified
 * home for a single user's tokens (formerly users/tokens/:id). Lists every
 * token regardless of status and offers Deactivate/Delete per active token.
 *
 * Variables:
 *   $this->user      — ['userid', 'username']
 *   $this->tokenList — array of token rows from User::getAllTokens()
 */
$user   = $this->user ?? [];
$tokens = $this->tokenList ?? [];
$uid    = (int) ($user['userid'] ?? 0);

$statusLabel = [0 => 'Inactive', 1 => 'Active', 2 => 'Deleted', 3 => 'Revoked'];
$statusColor = [0 => '#6c757d', 1 => '#28a745', 2 => '#343a40', 3 => '#dc3545'];
$this->activeNav = 'users_tokens'; @endphp
<div class="page-section">
    @include('../partials/admin_breadcrumb')
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>Tokens — {{ $user['username'] ?? '' }}</h2>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:0;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead style="background:#f5f5f5">
                    <tr>
                        <th style="padding:8px 12px;text-align:left">ID</th>
                        <th style="padding:8px 12px;text-align:left">Type</th>
                        <th style="padding:8px 12px;text-align:left">Status</th>
                        <th style="padding:8px 12px;text-align:left">IP</th>
                        <th style="padding:8px 12px;text-align:left">Created</th>
                        <th style="padding:8px 12px;text-align:left">Last Used</th>
                        <th style="padding:8px 12px;text-align:left">Expires</th>
                        <th style="padding:8px 12px;width:160px"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($tokens as $tok)
                    @php $tokenId   = (int) ($tok['tokenid'] ?? 0);
                    $status    = (int) ($tok['status']  ?? 0);
                    $exp       = (int) ($tok['expires']  ?? 0);
                    $isExpired = $exp > 0 && $exp < time();
                    $rowBg     = $isExpired ? 'background:#fffbea' : '';
                    $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . $tokenId . '&from=user&uid=' . $uid; @endphp
                    <tr style="border-top:1px solid #f0f0f0;cursor:pointer;{!! $rowBg !!}"
                        data-href="{!! $tokActionsUrl !!}" title="View token actions">
                        <td style="padding:6px 12px;font-family:monospace;color:#666">{!! $tokenId !!}</td>
                        <td style="padding:6px 12px"><span style="background:#e9ecef;padding:2px 6px;border-radius:3px;font-size:11px">{{ $tok['tokentype'] ?? 'auth' }}</span></td>
                        <td style="padding:6px 12px"><span style="color:{!! $statusColor[$status] ?? '#666' !!};font-weight:600;font-size:12px">{!! $statusLabel[$status] ?? 'Unknown' !!}</span></td>
                        <td style="padding:6px 12px;color:#888;font-size:12px">{{ $tok['ipaddress'] ?? '—' }}</td>
                        <td style="padding:6px 12px;font-size:12px">{!! ($tok['created'] ?? 0) > 0 ? localDateTime((int)$tok['created']) : '—' !!}</td>
                        <td style="padding:6px 12px;font-size:12px">{!! ($tok['lastused'] ?? 0) > 0 ? localDateTime((int)$tok['lastused']) : '—' !!}</td>
                        <td style="padding:6px 12px;font-size:12px;{!! $isExpired ? 'color:#856404' : '' !!}">
                            {!! $exp > 0 ? localDateTime($exp) . ($isExpired ? ' (exp)' : '') : 'Never' !!}
                        </td>
                        <td style="padding:6px 12px;text-align:right">
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
                                    data-confirm="Delete this token?">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($tokens))
                    <tr><td colspan="8" style="text-align:center;color:#888;padding:24px">No tokens found for this user.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
