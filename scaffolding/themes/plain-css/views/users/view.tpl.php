@php /**
 * User detail (read-only) view (plain-CSS theme).
 *
 * Variables:
 *   $this->user         — array: userid, username, email, firstname, lastname,
 *                          usertype, active, validated, regdate, lastlogin,
 *                          phone, mobile, language, timezone
 *   $this->usageStats   — array: total_tokens, unique_apps, active_days, account_created
 *   $this->sessionCount — int, active session count from sessions table
 *   $this->recentTokens — array, up to 5 most recent token rows
 */
$user         = $this->user ?? [];
$usageStats   = $this->usageStats ?? [];
$sessionCount = (int) ($this->sessionCount ?? 0);
$recentTokens = $this->recentTokens ?? [];
$uid          = (int) ($user['userid'] ?? 0);

// Name and tone both come from UserTypes — one definition for every screen. This theme
// only decides which colour a tone is.
$typeTones = ['danger' => '#b02a37', 'warning' => '#856404', 'neutral' => '#666', 'primary' => '#0d6efd'];
$typeInfo = function (int $t) use ($typeTones): array {
    $tone = \Pramnos\User\UserTypes::tone($t);

    return [$typeTones[$tone] ?? '#0d6efd', \Pramnos\User\UserTypes::label($t)];
};

[$typeColor, $typeLabel] = $typeInfo((int) ($user['usertype'] ?? 0));
$isActive    = (bool) ($user['active']    ?? 1);
$isValidated = (bool) ($user['validated'] ?? 1);

$initials = strtoupper(substr(
    trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: ($user['username'] ?? '?'),
    0, 1
));
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
$this->activeNav = 'users_view'; @endphp
<div class="page-section">
    @include('../partials/admin_breadcrumb')
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <h2 style="margin:0">{{ $user['username'] ?? '' }}</h2>
        @if (!$isActive)
            <span style="background:#f8d7da;color:#842029;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">Inactive</span>
        @endif
        @if (!$isValidated)
            <span style="background:#fff3cd;color:#664d03;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">Unvalidated</span>
        @endif
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start">
        <!-- Left: profile card + stats + actions -->
        <div>

            <div class="card" style="text-align:center;padding:24px;margin-bottom:16px">
                <div style="width:72px;height:72px;border-radius:50%;background:#6c757d;color:#fff;font-size:28px;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                    {{ $initials }}
                </div>
                <h3 style="margin:0 0 4px;font-size:16px">
                    {{ $fullName ?: ($user['username'] ?? '') }}
                </h3>
                <div style="color:#888;font-size:13px;margin-bottom:8px">@{{ $user['username'] ?? '' }}</div>
                <span style="background:{!! $typeColor !!};color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-right:4px">
                    {!! $typeLabel !!} ({!! (int) ($user['usertype'] ?? 0) !!})
                </span>
                <span style="background:{!! $isActive ? '#198754' : '#dc3545' !!};color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">
                    {!! $isActive ? 'Active' : 'Inactive' !!}
                </span>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Statistics</div>
                @php $row = function(string $label, string $val) {; @endphp
                    <div style="display:flex;justify-content:space-between;padding:8px 14px;border-top:1px solid #f0f0f0;font-size:13px">
                        <span style="color:#666">{!! $label !!}</span>
                        <span>{!! $val !!}</span>
                    </div>
                @php }; @endphp
                @php $row('Tokens', '<a href="' . adminUrl('Tokens/userid/') . $uid . '" style="font-weight:600">' . (int)($usageStats['total_tokens'] ?? 0) . '</a>'); @endphp
                @php $row('Unique Apps', '<strong>' . (int)($usageStats['unique_apps'] ?? 0) . '</strong>'); @endphp
                @php $row('Sessions', '<a href="' . adminUrl('users/sessions/') . $uid . '" style="font-weight:600">' . $sessionCount . '</a>'); @endphp
                @php $row('Registered', ($user['regdate'] ?? 0) > 0 ? localDate( (int)$user['regdate']) : '—'); @endphp
                @php $row('Last Login', ($user['lastlogin'] ?? 0) > 0 ? localDateTime( (int)$user['lastlogin']) : '—'); @endphp
            </div>

            <div class="card">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Actions</div>
                <div style="padding:12px;display:flex;flex-direction:column;gap:8px">
                    <a href="{!! adminUrl('users' . '/edit/' . ($uid)) !!}" class="btn btn-primary" style="text-align:center">Edit User</a>
                    {{-- `users/notify` existed with nothing linking to it — reachable only
                             by typing the URL, which is the same as not existing. --}}
                    <a href="{!! adminUrl('users' . '/notify/' . ($uid)) !!}" class="btn btn-outline-primary" style="text-align:center">Send a Message</a>
                    @if (!$isValidated && (string) ($user['email'] ?? '') !== '')
                        <form method="post" action="{!! adminUrl('users' . '/resendverification/' . ($uid)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-secondary" style="text-align:center">{{ t('Resend verification email') }}</button></form>
                    @endif
                    @if ($isActive)
                        <form method="post" action="{!! adminUrl('users' . '/lock/' . ($uid)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-warning" style="text-align:center"
                           data-confirm="Lock this account?">Lock Account</button></form>
                    @else
                        <form method="post" action="{!! adminUrl('users' . '/unlock/' . ($uid)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-success" style="text-align:center">Unlock Account</button></form>
                    @endif
                    <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}" class="btn btn-outline-secondary" style="text-align:center">All Tokens</a>
                    <a href="{!! adminUrl('users' . '/sessions/' . ($uid)) !!}" class="btn btn-outline-secondary" style="text-align:center">Sessions</a>
                </div>
            </div>

        </div>

        <!-- Right: details + recent tokens -->
        <div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;font-weight:600">Account Details</div>
                <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:13px">
                    @php $field = function(string $label, string $val) {; @endphp
                        <div>
                            <div style="font-size:11px;color:#888;margin-bottom:2px">{!! $label !!}</div>
                            <div>{!! $val !!}</div>
                        </div>
                    @php }; @endphp
                    @php $field('User ID', '<code style="font-size:12px">' . $uid . '</code>'); @endphp
                    @php $field('Username', htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8')); @endphp
                    @php $field('First Name', ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    @php $field('Last Name', ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    @php $field('Email', ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    @php $field('Phone', ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    @php $field('Mobile', ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    @php $field('Language', ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">default</span>'); @endphp
                    @php $field('Timezone', ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">User Type</div>
                        <span style="background:{!! $typeColor !!};color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">{!! $typeLabel !!}</span>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Status</div>
                        <span style="background:{!! $isActive ? '#198754' : '#dc3545' !!};color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">
                            {!! $isActive ? 'Active' : 'Inactive' !!}
                        </span>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Validation</div>
                        <span style="background:{!! $isValidated ? '#198754' : '#856404' !!};color:{!! $isValidated ? '#fff' : '#fff' !!};padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">
                            {!! $isValidated ? 'Validated' : 'Pending' !!}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Segments and groups: where this person belongs, and since when -->
            <div class="card" style="padding:16px;margin-bottom:16px">
                {!! \Pramnos\Html\AudiencePanel::person($uid) !!}
            </div>

            @if (!empty($recentTokens))
            <div class="card">
                <style>tr.pf-token-row{cursor:pointer}tr.pf-token-row:hover{background:#f8f9fa}</style>
                <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                    <span style="font-weight:600">Recent Tokens</span>
                    <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <thead style="background:#f5f5f5">
                            <tr>
                                <th style="padding:7px 12px;text-align:left">ID</th>
                                <th style="padding:7px 12px;text-align:left">Type</th>
                                <th style="padding:7px 12px;text-align:left">Status</th>
                                <th style="padding:7px 12px;text-align:left">IP</th>
                                <th style="padding:7px 12px;text-align:left">Last Used</th>
                                <th style="padding:7px 12px;text-align:left">Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                        @php foreach ($recentTokens as $tok):
                            $s = (int) ($tok['status'] ?? 0);
                            $sColors = [0 => '#6c757d', 1 => '#198754', 2 => '#343a40', 3 => '#dc3545'];
                            $sLabels = [0 => 'Inactive', 1 => 'Active', 2 => 'Deleted', 3 => 'Revoked'];
                            $exp = (int) ($tok['expires'] ?? 0);
                            $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . (int) $tok['tokenid'] . '&from=user&uid=' . $uid; @endphp
                            <tr class="pf-token-row" style="border-top:1px solid #f0f0f0"
                                data-href="{!! $tokActionsUrl !!}"
                                title="View token actions">
                                <td style="padding:6px 12px;font-family:monospace;color:#666">{!! (int) $tok['tokenid'] !!}</td>
                                <td style="padding:6px 12px"><span style="background:#e9ecef;padding:2px 6px;border-radius:3px;font-size:11px">{{ $tok['tokentype'] ?? 'auth' }}</span></td>
                                <td style="padding:6px 12px"><span style="color:{!! $sColors[$s] ?? '#666' !!};font-weight:600;font-size:12px">{!! $sLabels[$s] ?? '?' !!}</span></td>
                                <td style="padding:6px 12px;color:#888;font-size:12px">{{ $tok['ipaddress'] ?? '—' }}</td>
                                <td style="padding:6px 12px;font-size:12px">{!! ($tok['lastused'] ?? 0) > 0 ? localDateTime((int) $tok['lastused']) : '—' !!}</td>
                                <td style="padding:6px 12px;font-size:12px">{!! $exp > 0 ? localDateTime($exp) : 'Never' !!}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif


            @include('../partials/user_roles')
            @include('../partials/admin_screens')
            @include('../partials/email_preferences')
        </div>
    </div>
</div>
