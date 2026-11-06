@php /**
 * User detail (read-only) view (Tailwind theme).
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

/**
 * Both halves of the badge come from the registry: the name and the tone.
 *
 * This view carried its own copy of the bands — five of them, named differently from the
 * list's column and the filter's options, so "what is 85?" had three answers on three
 * screens, each with its own idea of which number was alarming. `UserTypes` answers both
 * now, and all this theme decides is which classes a tone wears — which is the part a
 * theme legitimately owns.
 */
$typeTones = [
    'danger'  => 'bg-error/10 text-error',
    'warning' => 'bg-warning/10 text-warning',
    'neutral' => 'bg-base-200 text-base-content/80',
    'primary' => 'bg-primary/10 text-primary',
];
$typeInfo = function (int $t) use ($typeTones): array {
    $tone = \Pramnos\User\UserTypes::tone($t);

    return [$typeTones[$tone] ?? $typeTones['primary'], \Pramnos\User\UserTypes::label($t)];
};

[$typeCls, $typeLabel] = $typeInfo((int) ($user['usertype'] ?? 0));
$isActive    = (bool) ($user['active']    ?? 1);
$isValidated = (bool) ($user['validated'] ?? 1);

$initials = strtoupper(substr(
    trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: ($user['username'] ?? '?'),
    0, 1
));
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'users_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="flex items-center gap-3 mb-6">
        <h2 class="text-2xl font-semibold">{{ $user['username'] ?? '' }}</h2>
        @if (!$isActive)
            <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-error/10 text-error">Inactive</span>
        @endif
        @if (!$isValidated)
            <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-warning/10 text-warning">Unvalidated</span>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Left: profile card + stats + actions -->
        <div class="lg:col-span-1 space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-center">
                <div class="w-20 h-20 bg-neutral text-neutral-content rounded-full flex items-center justify-center mx-auto mb-3 text-3xl font-bold">
                    {{ $initials }}
                </div>
                <h3 class="font-semibold text-base-content">
                    {{ $fullName ?: ($user['username'] ?? '') }}
                </h3>
                <p class="text-sm text-base-content/60 mt-0.5">@{{ $user['username'] ?? '' }}</p>
                <div class="mt-2 flex justify-center gap-1 flex-wrap">
                    <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $typeCls !!}">
                        {!! $typeLabel !!} ({!! (int) ($user['usertype'] ?? 0) !!})
                    </span>
                    <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $isActive ? 'bg-success/10 text-success' : 'bg-error/10 text-error' !!}">
                        {!! $isActive ? 'Active' : 'Inactive' !!}
                    </span>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">Statistics</div>
                <div class="divide-y divide-base-300 text-sm">
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Tokens</span>
                        <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}" class="font-semibold text-primary hover:underline">
                            {!! (int) ($usageStats['total_tokens'] ?? 0) !!}
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Unique Apps</span>
                        <strong>{!! (int) ($usageStats['unique_apps'] ?? 0) !!}</strong>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Sessions</span>
                        <a href="{!! adminUrl('users' . '/sessions/' . ($uid)) !!}" class="font-semibold text-primary hover:underline">
                            {!! $sessionCount !!}
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Registered</span>
                        <span class="text-xs">{!! ($user['regdate'] ?? 0) > 0 ? localDate((int) $user['regdate']) : '—' !!}</span>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Last Login</span>
                        <span class="text-xs">{!! ($user['lastlogin'] ?? 0) > 0 ? localDateTime((int) $user['lastlogin']) : '—' !!}</span>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">Actions</div>
                @php /**
                 * `btn-block`, not `block`.
                 *
                 * A daisyUI `btn` is an `inline-flex` box that centres its own content;
                 * `block` overrode that display, so the label stopped being centred by
                 * the button and the height came from the text instead of the component.
                 * `btn-block` is the width modifier the component ships for this.
                 *
                 * Each one carries the same icon the row actions use, from
                 * `Html\Icon` — one visual language for "edit", whether it is a 28px
                 * cell in a table or a full-width button here.
                 */
                // `$post` for an action that changes something: a one-button POST form with the
                // session's token, because the controller refuses it any other way.
                $action = static function (
                    string $url,
                    string $icon,
                    string $label,
                    string $classes = 'btn-outline',
                    string $confirm = '',
                    bool $post = false
                ): void {
                    $content = \Pramnos\Html\Icon::svg($icon)
                        . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    $class   = 'btn btn-sm btn-block justify-start gap-2 ' . $classes;

                    if ($post) {
                        echo \Pramnos\Html\Icon::postControl($url, $content, ['class' => $class]
                            + ($confirm !== '' ? ['data-confirm' => $confirm] : []));

                        return;
                    }

                    echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                        . ' class="' . $class . '"'
                        . ($confirm !== ''
                            ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"'
                            : '')
                        . '>' . $content . '</a>';
                }; @endphp
                <div class="p-4 grid gap-2">
                    @php $action(adminUrl('users/edit/' . $uid), 'edit', 'Edit user', 'btn-primary');
                    /*
                     * Send a message. Second, because after "edit this account" it is the
                     * thing an operator most often came here to do.
                     *
                     * `users/notify` existed with nothing linking to it: the screen was
                     * reachable only by typing the URL, which is the same as not existing.
                     */
                    $action(adminUrl('users/notify/' . $uid), 'send', 'Send a message');
                    $action(adminUrl('users/resetpassword/' . $uid), 'password', 'Send password reset', 'btn-outline',
                        'Send a password reset link to this user?', true);
                    if ($isActive) {
                        $action(adminUrl('users/lock/' . $uid), 'lock', 'Lock account', 'btn-outline btn-warning',
                            'Lock this account?', true);
                    } else {
                        $action(adminUrl('users/unlock/' . $uid), 'unlock', 'Unlock account', 'btn-outline btn-success', '', true);
                    }
                    $action(adminUrl('Tokens/userid/' . $uid), 'tokens', 'All tokens');
                    $action(adminUrl('users/sessions/' . $uid), 'sessions', 'Sessions');
                    $action(adminUrl('Logs/search') . '?query=' . urlencode((string) ($user['username'] ?? '')), 'log', 'Find in logs'); @endphp
                </div>
            </div>

        </div>

        <!-- Right: details + recent tokens -->
        <div class="lg:col-span-3 space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Account Details</div>
                <div class="p-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 text-sm">
                    @php $field = function(string $label, string $value) {; @endphp
                        <div>
                            <div class="text-xs text-base-content/60 mb-0.5">{!! $label !!}</div>
                            <div class="text-base-content">{!! $value !!}</div>
                        </div>
                    @php }; @endphp
                    @php $field('User ID', '<code class="text-xs">' . (int)($user['userid'] ?? 0) . '</code>'); @endphp
                    @php $field('Username', htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8')); @endphp
                    @php $field('First Name', ($user['firstname'] ?? '') !== '' ? htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    @php $field('Last Name', ($user['lastname'] ?? '') !== '' ? htmlspecialchars($user['lastname'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    @php $field('Email', ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    @php $field('Phone', ($user['phone'] ?? '') !== '' ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    @php $field('Mobile', ($user['mobile'] ?? '') !== '' ? htmlspecialchars($user['mobile'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    @php $field('Language', ($user['language'] ?? '') !== '' ? htmlspecialchars($user['language'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">default</span>'); @endphp
                    @php $field('Timezone', ($user['timezone'] ?? '') !== '' ? htmlspecialchars($user['timezone'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">User Type</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $typeCls !!}">{!! $typeLabel !!}</span>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">Status</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $isActive ? 'bg-success/10 text-success' : 'bg-error/10 text-error' !!}">
                            {!! $isActive ? 'Active' : 'Inactive' !!}
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">Validation</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $isValidated ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning' !!}">
                            {!! $isValidated ? 'Validated' : 'Pending' !!}
                        </span>
                    </div>
                </div>
            </div>

            @if (!empty($recentTokens))
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-6 py-3 border-b border-base-300 flex justify-between items-center">
                    <span class="font-semibold text-base-content">Recent Tokens</span>
                    <a href="{!! adminUrl('Tokens' . '/userid/' . ($uid)) !!}"
                       class="text-sm text-primary hover:underline">All Tokens</a>
                </div>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">IP</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                            <th class="px-4 py-2 text-left">Expires</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-300">
                    @php foreach ($recentTokens as $tok):
                        $s    = (int) ($tok['status'] ?? 0);
                        $sMap = [0 => ['bg-base-200 text-base-content/80','Inactive'], 1 => ['bg-success/10 text-success','Active'], 2 => ['bg-neutral text-white','Deleted'], 3 => ['bg-error/10 text-error','Revoked']];
                        [$sCls, $sLabel] = $sMap[$s] ?? ['bg-base-200 text-base-content/80','Unknown'];
                        $exp = (int) ($tok['expires'] ?? 0);
                        $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . (int) $tok['tokenid'] . '&from=user&uid=' . $uid; @endphp
                        <tr class="hover:bg-base-200 cursor-pointer" data-href="{!! $tokActionsUrl !!}" title="View token actions">
                            <td class="px-4 py-2 font-mono text-xs text-base-content/70">{!! (int) $tok['tokenid'] !!}</td>
                            <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 bg-base-200 text-base-content/80 text-xs rounded-sm">{{ $tok['tokentype'] ?? 'auth' }}</span></td>
                            <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $sCls !!}">{!! $sLabel !!}</span></td>
                            <td class="px-4 py-2 text-xs text-base-content/60">{{ $tok['ipaddress'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs">{!! ($tok['lastused'] ?? 0) > 0 ? localDateTime((int) $tok['lastused']) : '—' !!}</td>
                            <td class="px-4 py-2 text-xs">{!! $exp > 0 ? localDateTime($exp) : 'Never' !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            @php /**
             * Everything else the framework records about this account.
             *
             * Nine stores, collected by `UsersController::userRecords()` and each one
             * guarded on its own: an application without the `authserver` feature has
             * none of the `authserver.*` tables, so a panel with nothing behind it says
             * so instead of taking the page down.
             *
             * A panel is rendered even when it is empty, on purpose. "No GDPR requests"
             * is an answer; a page that silently omits the section leaves the reader
             * unable to tell it from a screen that never had one.
             */
            $records = is_array($this->records ?? null) ? $this->records : [];
            $r = static fn (string $key, $default = []) => $records[$key] ?? $default;
            $when = static function ($value): string {
                if ($value === null || $value === '' || $value === 0 || $value === '0') {
                    return '—';
                }
                $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

                return $time > 0 ? localDateTime( $time) : (string) $value;
            };
            $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

            /** A panel header with an optional count and an optional "see all" link. */
            $panel = static function (string $title, int $count = -1, string $moreUrl = '', string $moreLabel = 'See all') use ($esc): void {
                echo '<div class="px-4 py-2 bg-base-200 border-b border-base-300 flex items-center gap-2">'
                    . '<span class="text-xs font-semibold uppercase tracking-wide text-base-content/70">'
                    . $esc($title) . '</span>';
                if ($count >= 0) {
                    echo '<span class="badge badge-neutral badge-sm">' . $count . '</span>';
                }
                if ($moreUrl !== '') {
                    echo '<a class="ms-auto link text-xs" href="' . $esc($moreUrl) . '">' . $esc($moreLabel) . '</a>';
                }
                echo '</div>';
            }; @endphp

            <!-- Segments and groups: where this person belongs, and since when -->
            <div class="card bg-base-100 border border-base-300 shadow-xs p-4">
                {!! \Pramnos\Html\AudiencePanel::person($uid) !!}
            </div>

            <!-- Login security: the lockout that answers "I cannot sign in" -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                @php $panel('Login security'); @endphp
                <div class="p-4 text-sm">
                    @php $lockouts = $r('lockouts'); @endphp
                    @php $activeLock = null;
                    foreach ($lockouts as $lock) {
                        $until = strtotime((string) ($lock['lockoutuntil'] ?? ''));
                        if ($until && $until > time()) {
                            $activeLock = $lock;
                            break;
                        }
                    } @endphp
                    @if ($activeLock !== null)
                    <div class="flex items-center gap-2 mb-3">
                        <span class="badge badge-error badge-sm">Locked out</span>
                        <span class="text-xs">until {{ $when($activeLock['lockoutuntil'] ?? null) }}</span>
                    </div>
                    @else
                    <div class="mb-3"><span class="badge badge-success badge-sm">No active lockout</span></div>
                    @endif

                    @if ($lockouts !== [])
                    <table class="table table-xs">
                        <thead><tr><th>Scope</th><th>Value</th><th>Failures</th><th>Last failure</th><th>Locked until</th></tr></thead>
                        <tbody>
                        @foreach ($lockouts as $lock)
                            <tr>
                                <td>{{ $lock['locktype'] ?? $lock['scope'] ?? '' }}</td>
                                <td class="font-mono text-xs">{{ $lock['displayvalue'] ?? '' }}</td>
                                <td>{!! (int) ($lock['failedattempts'] ?? 0) !!}</td>
                                <td class="text-xs">{{ $when($lock['lastfailedat'] ?? null) }}</td>
                                <td class="text-xs">{{ $when($lock['lockoutuntil'] ?? null) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    {!! \Pramnos\Html\Icon::postControl(adminUrl('users/unlocklogin/' . $uid), 'Clear lockout', [ 'class' => 'btn btn-sm btn-outline btn-error mt-3', 'data-confirm' => 'Clear the login lockout for this user?', ]) !!}
                    @else
                    <p class="text-base-content/60 text-sm mb-0">No failed sign-in attempts recorded.</p>
                    @endif
                </div>
            </div>

            <!-- Second factor and passkeys: credentials an operator may have to remove -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                @php $panel('Second factor & passkeys'); @endphp
                <div class="p-4 text-sm space-y-3">
                    @php $twofactor = $r('twofactor', null); @endphp
                    <div class="flex items-center gap-2">
                        @if (is_array($twofactor) && (int) ($twofactor['is_enabled'] ?? $twofactor['enabled'] ?? 0) === 1)
                        <span class="badge badge-success badge-sm">2FA on</span>
                        <span class="text-xs text-base-content/60">
                            since {{ $when($twofactor['enabled_at'] ?? $twofactor['created_at'] ?? null) }}
                        </span>
                        <span class="ms-auto">{!! \Pramnos\Html\Icon::postControl(adminUrl('users/disabletwofactor/' . $uid), 'Disable', [ 'class' => 'btn btn-xs btn-outline btn-warning', 'data-confirm' => 'Disable two-factor authentication for this user?', ]) !!}</span>
                        @else
                        <span class="badge badge-ghost badge-sm">2FA off</span>
                        @endif
                    </div>

                    {{-- The email factor, read from the same row.

Shown only where the application offers the method — elsewhere the row
may still say the account once asked for it, and reporting a factor
that cannot be used would send an operator looking for a setting that
is not there. Read-only here on purpose: attaching or removing a
factor is the account's own decision, behind its own password, and an
operator turning one *on* would be adding a credential to somebody
else's mailbox. --}}
                    @if (\Pramnos\Auth\EmailSecondFactor::isAvailable())
                    <div class="flex items-center gap-2">
                        @if (is_array($twofactor) && (int) ($twofactor['email_enabled'] ?? 0) === 1)
                        <span class="badge badge-success badge-sm">Email codes on</span>
                        <span class="text-xs text-base-content/60">a code is mailed on sign-in</span>
                        @else
                        <span class="badge badge-ghost badge-sm">Email codes off</span>
                        @endif
                    </div>
                    @endif

                    @php /**
                     * New-sign-in alerts, and whose decision they are.
                     *
                     * The switch is only offered when the site leaves it to the user: with
                     * a policy of `always` or `off` a per-account toggle decides nothing,
                     * and a control that decides nothing is worse than a sentence saying
                     * so.
                     */
                    $alerts = $r('signInAlerts', ['policy' => 'optin', 'enabled' => false]); @endphp
                    <div class="flex items-center gap-2">
                        @if (($alerts['policy'] ?? 'optin') === 'optin')
                            @if (!empty($alerts['enabled']))
                            <span class="badge badge-success badge-sm">Sign-in alerts on</span>
                            <span class="ms-auto">{!! \Pramnos\Html\Icon::postControl(adminUrl('users/signinalerts/' . $uid) . '?enabled=0', 'Turn off', ['class' => 'btn btn-xs btn-outline']) !!}</span>
                            @else
                            <span class="badge badge-ghost badge-sm">Sign-in alerts off</span>
                            <span class="ms-auto">{!! \Pramnos\Html\Icon::postControl(adminUrl('users/signinalerts/' . $uid) . '?enabled=1', 'Turn on', ['class' => 'btn btn-xs btn-outline']) !!}</span>
                            @endif
                        @elseif (($alerts['policy'] ?? '') === 'always')
                        <span class="badge badge-success badge-sm">Sign-in alerts on</span>
                        <span class="text-xs text-base-content/60">site policy: always</span>
                        @else
                        <span class="badge badge-ghost badge-sm">Sign-in alerts off</span>
                        <span class="text-xs text-base-content/60">site policy: never</span>
                        @endif
                    </div>

                    @php $passkeys = $r('passkeys'); @endphp
                    @if ($passkeys !== [])
                    <table class="table table-xs">
                        <thead><tr><th>Passkey</th><th>Added</th><th>Last used</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($passkeys as $key)
                            <tr>
                                <td>{{ $key['name'] ?? $key['label'] ?? ('#' . (int) ($key['id'] ?? 0)) }}</td>
                                <td class="text-xs">{{ $when($key['created_at'] ?? null) }}</td>
                                <td class="text-xs">{{ $when($key['last_used_at'] ?? null) }}</td>
                                <td class="text-end">
                                    {!! \Pramnos\Html\Icon::postButton(adminUrl('users/revokepasskey/' . $uid) . '?credential=' . (int) ($key['id'] ?? 0), 'delete', 'Revoke this passkey', ['data-confirm' => 'Revoke this passkey?', 'class' => 'pf-action-danger']) !!}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @else
                    <p class="text-base-content/60 text-sm mb-0">No passkeys registered.</p>
                    @endif
                </div>
            </div>

            <!-- Activity: what this account has done -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                @php $panel('Activity', (int) $r('activityCount', 0), adminUrl('users/activity/' . $uid)); @endphp
                @php $activity = $r('activity'); @endphp
                @if ($activity === [])
                <div class="p-4 text-sm text-base-content/60">Nothing recorded for this account.</div>
                @else
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr><th>When</th><th>Action</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($activity as $entry)
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $when($entry['created_at'] ?? null) }}</td>
                            <td>{{ $entry['action'] ?? '' }}</td>
                            <td class="font-mono text-xs">{{ $entry['ip_address'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            <!-- Token actions: what was done with this account's tokens -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                @php $panel('Token actions', (int) $r('tokenActionCount', 0), adminUrl('TokenActions?uid=' . $uid . '&from=user')); @endphp
                @php $tokenActions = $r('tokenActions'); @endphp
                @if ($tokenActions === [])
                <div class="p-4 text-sm text-base-content/60">No token activity.</div>
                @else
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        {{-- The columns the table actually has.

This read `actiondate` and `action`, and `tokenactions` has neither —
it has `servertime`, `method` and a `urlid` into the URL registry. The
panel rendered an em dash and a blank cell for every row, which reads
as "no data" rather than as "wrong column", and stayed that way
because the query above was also broken and the panel was always
empty. --}}
                        <tr><th>When</th><th>Request</th><th>Token</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($tokenActions as $entry)
                        @php $status = (int) ($entry['return_status'] ?? 0);
                        $badge  = $status === 0 ? '' : ($status < 400 ? 'badge-success' : 'badge-error'); @endphp
                        <tr>
                            <td class="text-xs whitespace-nowrap">@php $at = (int) ($entry['servertime'] ?? 0);
                                echo $at > 0 ? $esc(date('Y-m-d H:i', $at)) : '—'; @endphp</td>
                            <td class="text-xs">
                                <span class="font-mono">{{ (string) ($entry['method'] ?? '') }}</span>
                                <span class="break-all">{{ (string) ($entry['url'] ?? '') }}</span>
                                @if ($badge !== '')
                                    <span class="badge badge-xs {!! $badge !!}">{!! $status !!}</span>
                                @endif
                            </td>
                            <td class="text-xs">
                                <a class="link" href="{!! adminUrl('TokenActions?token_id=' . (int) ($entry['tokenid'] ?? 0) . '&from=user&uid=' . $uid) !!}">
                                    #{!! (int) ($entry['tokenid'] ?? 0) !!}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            <!-- Mail: what this address was actually sent -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                @php /*
                 * "See all" carries the address, so the mail screen opens filtered to this
                 * account rather than to everybody. An unfiltered link on a per-account panel
                 * is a link that answers a different question than the one being asked.
                 */
                $panel(
                    'Emails received',
                    (int) $r('emailCount', 0),
                    adminUrl('emails') . '?tomail=' . urlencode((string) $r('emailAddress', ''))
                        // The account's own mail too, whatever address it went to, where the log records accounts.
                        . ($r('emailsByUser', false) ? '&user=' . $uid : '')
                        . '&from=user&uid=' . $uid
                ); @endphp
                @php $emails = $r('emails'); @endphp
                @if ($emails === [])
                <div class="p-4 text-sm text-base-content/60">
                    {{-- The address, not "this address". The mail log is keyed by
                             address and this panel matches on the account's current one —
                             so an operator reading a zero needs to see *which* address was
                             looked for, or the zero is indistinguishable from a broken
                             screen. It is also how the limitation becomes visible: mail sent
                             to an address the account has since changed is not here. --}}
                    Nothing was sent to
                    <span class="font-mono">{{ $r('emailAddress', '') }}</span>.
                </div>
                @else
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr><th>When</th><th>Subject</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($emails as $mail)
                        @php $mailUrl = adminUrl('emails/show/') . (int) ($mail['id'] ?? 0); @endphp
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $when($mail['date'] ?? null) }}</td>
                            <td>
                                <a class="link" href="{!! $mailUrl !!}">
                                    {{ (string) ($mail['subject'] ?? '(no subject)') }}
                                </a>
                            </td>
                            <td class="text-xs">
                                @php $status = (int) ($mail['status'] ?? 0); @endphp
                                @if ($status === 1)
                                    <span class="pf-state pf-state-on">Sent</span>
                                @elseif ($status === 2)
                                    <span class="pf-state">Queued</span>
                                @else
                                    <span class="pf-state pf-state-off">Pending</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            <!-- GDPR: requests an operator has to answer -->
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                @php $panel('Data requests (GDPR)', (int) $r('gdprCount', 0)); @endphp
                @php $gdpr = $r('gdpr'); @endphp
                @if ($gdpr === [])
                <div class="p-4 text-sm text-base-content/60">This user has made no export or erasure requests.</div>
                @else
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr><th>Requested</th><th>Type</th><th>Status</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($gdpr as $request)
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $when($request['requested_at'] ?? null) }}</td>
                            <td>{{ $request['request_type'] ?? '' }}</td>
                            <td>
                                <span class="pf-state {!! ($request['status'] ?? '') === 'completed' ? 'pf-state-on' : 'pf-state-off' !!}">
                                    {{ $request['status'] ?? '' }}
                                </span>
                            </td>
                            <td class="font-mono text-xs">{{ $request['ip_address'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            <!-- Privacy choices and memberships: small, and nowhere else on the site -->
            <div class="grid gap-4 md:grid-cols-2">
                <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                    @php $panel('Privacy choices'); @endphp
                    @php $privacy = $r('privacy', null); @endphp
                    @if (!is_array($privacy) || $privacy === [])
                    <div class="p-4 text-sm text-base-content/60">Nothing recorded — the defaults apply.</div>
                    @else
                    <table class="table table-xs">
                        <tbody>
                        @foreach ($privacy as $setting => $value)
                            @php if ($setting === 'userid' || is_array($value) || is_object($value)) { continue; } @endphp
                            <tr>
                                <th class="font-normal text-xs text-base-content/60">{{ ucwords(str_replace('_', ' ', (string) $setting)) }}</th>
                                <td class="text-xs">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>

                {{-- The browsers this account can be pushed to.

Two questions an operator has, answered by one list: "why did they not get
the notification" — almost always because nothing here is subscribed — and
"which devices is this account on".

The endpoint is not shown and not read: whoever holds it can push to that
browser, so it is a credential, and a credential does not go on a screen. --}}
                <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                    @php $panel('Push devices', (int) $r('pushDeviceCount', 0)); @endphp
                    @php $pushDevices = $r('pushDevices'); @endphp
                    @if ($pushDevices === [])
                    <div class="p-4 text-sm text-base-content/60">
                        No browser has subscribed. A push to this account reaches nobody.
                    </div>
                    @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($pushDevices as $device)
                            @php $failures = (int) ($device['failure_count'] ?? 0);
                            $lastOk   = (int) ($device['last_success_at'] ?? 0); @endphp
                        <li class="px-4 py-2 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="truncate" title="{{ $device['user_agent'] ?? '' }}">
                                    {{ ($device['user_agent'] ?? '') !== '' ? $device['user_agent'] : 'Unknown browser' }}
                                </span>
                                @if ($failures > 0)
                                    {{-- Not an error yet: a busy push service is retried. It
                                             becomes one at ten, when the row is retired. --}}
                                    <span class="ms-auto badge badge-warning badge-xs">
                                        {!! $failures !!} failed
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-base-content/50">
                                subscribed {{ $when($device['created_at'] ?? null) }}
                                @if ($lastOk > 0)
                                    · last reached {{ $when($lastOk) }}
                                @else
                                    · never reached
                                @endif
                            </div>
                        </li>
                        @endforeach
                    </ul>
                    @endif
                </div>

                {{-- And what was actually pushed to this account.

The panel above says a browser subscribed and when it was last reached — a
fact about the browser. This says what was *sent*, which is the question
somebody is holding when they open this screen: «they say they did not get
the notification». The rows where nothing was sent are here too, and they are
usually the answer. --}}
                <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                    @php $panel('Recent pushes', count($r('pushes'))); @endphp
                    @php $pushes = $r('pushes'); @endphp
                    @if ($pushes === [])
                    <div class="p-4 text-sm text-base-content/60">
                        Nothing has been pushed to this account.
                    </div>
                    @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($pushes as $push)
                            @php $status   = (int) ($push['status'] ?? 0);
                            $notSent  = (string) ($push['endpoint_hash'] ?? '') === '';
                            $ok       = $status >= 200 && $status < 300;
                            $name     = (string) ($push['notification'] ?? '');
                            $short    = $name === '' ? '' : substr((string) strrchr('\\' . $name, '\\'), 1); @endphp
                        <li class="px-4 py-2 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="truncate">
                                    {{ ($push['title'] ?? '') !== '' ? $push['title'] : $short }}
                                </span>
                                <span class="ms-auto badge badge-xs {!! $ok ? 'badge-success' : ($notSent ? 'badge-ghost' : 'badge-error') !!}">
                                    {!! $ok ? 'delivered' : ($notSent ? 'not sent' : (string) $status) !!}
                                </span>
                            </div>
                            <div class="text-xs text-base-content/50">
                                {{ $when($push['sent'] ?? null) }}
                                @if (($push['error'] ?? '') !== '')
                                    · {{ $push['error'] }}
                                @endif
                            </div>
                        </li>
                        @endforeach
                    </ul>
                    <div class="px-4 py-2 border-t border-base-200">
                        <a class="link link-hover text-xs"
                           href="{!! adminUrl('PushLog') !!}?userid={!! $uid !!}">
                            Everything pushed to this account →
                        </a>
                    </div>
                    @endif
                </div>

                <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                    @php $panel('Organizations', count($r('organizations'))); @endphp
                    @php $organizations = $r('organizations'); @endphp
                    @if ($organizations === [])
                    <div class="p-4 text-sm text-base-content/60">Not a member of any organization.</div>
                    @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($organizations as $organization)
                        <li class="px-4 py-2 text-sm flex items-center gap-2">
                            <a class="link truncate" href="{!! adminUrl('Organizations/view/' . (int) ($organization['organization_id'] ?? 0)) !!}">
                                {{ $organization['name'] ?? '' }}
                            </a>
                            <span class="ms-auto text-xs text-base-content/50">{{ $when($organization['granted_at'] ?? null) }}</span>
                        </li>
                        @endforeach
                    </ul>
                    @endif
                </div>
            </div>


            @include('../partials/user_roles')
            @include('../partials/admin_screens')
            @include('../partials/email_preferences')
        </div>
    </div>
</div>
