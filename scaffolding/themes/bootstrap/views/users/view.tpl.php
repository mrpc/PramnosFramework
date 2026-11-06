@php /**
 * User detail (read-only) view (Bootstrap theme).
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

// Name and tone both come from UserTypes — one definition, so the badge, the list column
// and the filter's options cannot disagree about what 85 is or how loudly to say it. This
// theme only maps a tone to a Bootstrap contextual class.
$typeTones = ['danger' => 'danger', 'warning' => 'warning', 'neutral' => 'secondary', 'primary' => 'info'];
$typeInfo = function (int $t) use ($typeTones): array {
    $tone = \Pramnos\User\UserTypes::tone($t);

    return [$typeTones[$tone] ?? 'info', \Pramnos\User\UserTypes::label($t)];
};

[$typeBadge, $typeLabel] = $typeInfo((int) ($user['usertype'] ?? 0));
$isActive    = (bool) ($user['active']    ?? 1);
$isValidated = (bool) ($user['validated'] ?? 1);

$initials = strtoupper(substr(
    trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: ($user['username'] ?? '?'),
    0, 1
));
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'users_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="d-flex align-items-center gap-2 mb-4">
        <h2 class="mb-0">{{ $user['username'] ?? '' }}</h2>
        @if (!$isActive)
            <span class="badge bg-danger">Inactive</span>
        @endif
        @if (!$isValidated)
            <span class="badge bg-warning text-dark">Unvalidated</span>
        @endif
    </div>

    <div class="row g-4">
        <!-- Left: profile card + stats + actions -->
        <div class="col-xl-3 col-lg-4">

            <div class="card mb-3">
                <div class="card-body text-center py-4">
                    <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center mb-3"
                         style="width:80px;height:80px;font-size:2rem;color:#fff">
                        {{ $initials }}
                    </div>
                    <h5 class="mb-1 fw-semibold">
                        {{ $fullName ?: ($user['username'] ?? '') }}
                    </h5>
                    <p class="text-muted small mb-2">@{{ $user['username'] ?? '' }}</p>
                    <span class="badge bg-{!! $typeBadge !!} me-1">{!! $typeLabel !!} ({!! (int) ($user['usertype'] ?? 0) !!})</span>
                    <span class="badge bg-{!! $isActive ? 'success' : 'danger' !!}">{!! $isActive ? 'Active' : 'Inactive' !!}</span>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold small text-uppercase text-muted">Statistics</div>
                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Tokens</span>
                        <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}" class="fw-semibold text-decoration-none">
                            {!! (int) ($usageStats['total_tokens'] ?? 0) !!}
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Unique Apps</span>
                        <strong>{!! (int) ($usageStats['unique_apps'] ?? 0) !!}</strong>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Sessions</span>
                        <a href="{!! adminUrl('users' . '/sessions/' . ($uid)) !!}" class="fw-semibold text-decoration-none">
                            {!! $sessionCount !!}
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Registered</span>
                        <span>{!! ($user['regdate'] ?? 0) > 0 ? localDate((int) $user['regdate']) : '—' !!}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Last Login</span>
                        <span>{!! ($user['lastlogin'] ?? 0) > 0 ? localDateTime((int) $user['lastlogin']) : '—' !!}</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold small text-uppercase text-muted">Actions</div>
                <div class="card-body d-grid gap-2">
                    <a href="{!! adminUrl('users' . '/edit/' . ($uid)) !!}" class="btn btn-primary btn-sm">Edit User</a>
                    {{-- `users/notify` existed with nothing linking to it — reachable only
                             by typing the URL, which is the same as not existing. --}}
                    <a href="{!! adminUrl('users' . '/notify/' . ($uid)) !!}" class="btn btn-outline-primary btn-sm">Send a Message</a>
                    @if (!$isValidated && (string) ($user['email'] ?? '') !== '')
                        <form method="post" action="{!! adminUrl('users' . '/resendverification/' . ($uid)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-secondary btn-sm">{{ t('Resend verification email') }}</button></form>
                    @endif
                    @if ($isActive)
                        <form method="post" action="{!! adminUrl('users' . '/lock/' . ($uid)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-warning btn-sm"
                           data-confirm="Lock this account?">Lock Account</button></form>
                    @else
                        <form method="post" action="{!! adminUrl('users' . '/unlock/' . ($uid)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-success btn-sm">Unlock Account</button></form>
                    @endif
                    <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}" class="btn btn-outline-secondary btn-sm">All Tokens</a>
                    <a href="{!! adminUrl('users' . '/sessions/' . ($uid)) !!}" class="btn btn-outline-secondary btn-sm">Sessions</a>
                </div>
            </div>

        </div>

        <!-- Right: account details + recent tokens -->
        <div class="col-xl-9 col-lg-8">

            <div class="card mb-4">
                <div class="card-header fw-semibold">Account Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">User ID</div>
                            <code>{!! $uid !!}</code>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Username</div>
                            <div>{{ $user['username'] ?? '' }}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">First Name</div>
                            <div>{!! ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Last Name</div>
                            <div>{!! ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Email</div>
                            <div>{!! ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Phone</div>
                            <div>{!! ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Mobile</div>
                            <div>{!! ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Language</div>
                            <div>{!! ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">default</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Timezone</div>
                            <div>{!! ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">User Type</div>
                            <span class="badge bg-{!! $typeBadge !!}">{!! $typeLabel !!}</span>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Status</div>
                            <span class="badge bg-{!! $isActive ? 'success' : 'danger' !!}">{!! $isActive ? 'Active' : 'Inactive' !!}</span>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-muted small mb-1">Validation</div>
                            <span class="badge bg-{!! $isValidated ? 'success' : 'warning' !!} {!! $isValidated ? '' : 'text-dark' !!}">
                                {!! $isValidated ? 'Validated' : 'Pending' !!}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Segments and groups: where this person belongs, and since when -->
            <div class="card mb-4"><div class="card-body">
                {!! \Pramnos\Html\AudiencePanel::person($uid) !!}
            </div></div>

            @if (!empty($recentTokens))
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Recent Tokens</span>
                    <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th><th>Type</th><th>Status</th>
                                <th>IP</th><th>Last Used</th><th>Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                        @php foreach ($recentTokens as $tok):
                            $s = (int) ($tok['status'] ?? 0);
                            $sMap = [0 => ['secondary','Inactive'], 1 => ['success','Active'], 2 => ['dark','Deleted'], 3 => ['danger','Revoked']];
                            [$sBadge, $sLabel] = $sMap[$s] ?? ['secondary','Unknown'];
                            $exp = (int) ($tok['expires'] ?? 0);
                            $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . (int) $tok['tokenid'] . '&from=user&uid=' . $uid; @endphp
                            <tr style="cursor:pointer" data-href="{!! $tokActionsUrl !!}" title="View token actions">
                                <td><code class="small">{!! (int) $tok['tokenid'] !!}</code></td>
                                <td><span class="badge bg-secondary">{{ $tok['tokentype'] ?? 'auth' }}</span></td>
                                <td><span class="badge bg-{!! $sBadge !!}">{!! $sLabel !!}</span></td>
                                <td class="small text-muted">{{ $tok['ipaddress'] ?? '—' }}</td>
                                <td class="small">{!! ($tok['lastused'] ?? 0) > 0 ? localDateTime((int) $tok['lastused']) : '—' !!}</td>
                                <td class="small">{!! $exp > 0 ? localDateTime($exp) : 'Never' !!}</td>
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
