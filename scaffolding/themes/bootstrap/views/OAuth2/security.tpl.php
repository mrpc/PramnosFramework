@php /**
 * Security Overview page (Bootstrap theme).
 *
 * Variables:
 *   $this->recentActivity   — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->emailFactorOffered — bool: the application allows a code by email
 *   $this->emailFactorEnabled — bool: this account has asked for one
 *   $this->activeSessions   — array[] {sid, host_addr, agent, time, url}
 *   $this->currentSid       — sid of the session viewing this page
 *   $this->routeBase        — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'security'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Security Overview') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <!-- 2FA status -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Two-Factor Authentication') }}</div>
                <div class="card-body d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    <div>
                        @if ($this->twoFactorEnabled)
                            <span class="badge bg-success me-2">{{ t('Enabled') }}</span>
                            {{ t('Your account is protected with two-factor authentication.') }}
                        @else
                            <span class="badge bg-warning text-dark me-2">{{ t('Disabled') }}</span>
                            {{ t('Protect your account by enabling two-factor authentication.') }}
                        @endif
                    </div>
                    <a href="{!! sURL !!}TwoFactorAuth" class="btn btn-sm btn-outline-secondary">
                        {{ $this->twoFactorEnabled ? t('Manage 2FA') : t('Enable 2FA') }}
                    </a>
                </div>
            </div>

            {{-- A code by email — shown only where the application allows the method.

Deliberately below the authenticator card and worded as the weaker option,
because it is one: mail is a channel somebody else may already be reading.
It is offered at all because it is the only second factor that can be turned
on by an account that has set nothing up in advance, which is most accounts.

The password is asked for in the same form. Attaching a second factor is a
change to how the account authenticates, so a borrowed session must not be
able to make it — in either direction. --}}
            @if (!empty($this->emailFactorOffered))
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Sign-in codes by email') }}</div>
                <div class="card-body">
                    <p class="mb-3">
                        @if (!empty($this->emailFactorEnabled))
                            <span class="badge bg-success me-2">{{ t('On') }}</span>
                            {{ t('We email you a code when you sign in.') }}
                        @else
                            <span class="badge bg-secondary me-2">{{ t('Off') }}</span>
                            {{ t('Weaker than an authenticator app, and better than a password alone.') }}
                        @endif
                    </p>
                    <form method="POST" action="{!! sURL . $routeBase !!}/emailfactor" class="row g-2">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="enable" value="{!! empty($this->emailFactorEnabled) ? '1' : '0' !!}">
                        <div class="col-sm-8">
                            <input id="password" type="password" name="password" required autocomplete="current-password" enterkeyhint="go"
                                   class="form-control form-control-sm" placeholder="{{ t('Your password') }}">
                            {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                        </div>
                        <div class="col-sm-4">
                            <button type="submit" class="btn btn-sm w-100 {!! empty($this->emailFactorEnabled) ? 'btn-primary' : 'btn-outline-secondary' !!}">
                                {{ empty($this->emailFactorEnabled) ? t('Turn on') : t('Turn off') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            @endif

            <!-- Passkeys -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Passkeys') }}</div>
                <div class="card-body d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    <span>{{ t('Sign in without a password using your device\'s fingerprint, face or screen lock.') }}</span>
                    <a href="{!! sURL !!}Passkey" class="btn btn-sm btn-outline-primary">{{ t('Manage Passkeys') }}</a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Password') }}</div>
                <div class="card-body d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    {{-- Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. --}}
                    <span>{{ t('Change it if you think somebody else knows it, or if you have used it anywhere else.') }}</span>
                    <a href="{!! sURL . $routeBase !!}/changepassword" class="btn btn-sm btn-outline-primary">
                        {{ t('Change Password') }}
                    </a>
                </div>
            </div>

            <!-- Trusted devices: "don't ask again on this device", each revocable -->
            {{-- Shown whenever the site offers it, so a page that says "your trusted devices" has
                     them — or says how a device becomes one. --}}
            @if (!empty($this->trustedDevicesOffered))
            <div class="card mb-4">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>{{ t('Trusted devices') }}</span>
@if (!empty($this->trustedDevices))
                    <form method="post" action="{!! sURL . $routeBase !!}/revokedevice" class="m-0">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0">{{ t('Forget all') }}</button>
                    </form>
                    @endif
                </div>
                <div class="card-body pb-0">
                    <p class="small text-muted mb-2">{{ t('These skip the second step when you sign in, and can approve your sign-ins elsewhere.') }}</p>
                </div>
                @if (empty($this->trustedDevices))
                <p class="card-body pt-0 small text-muted">{{ t('No device is trusted yet. When you sign in with your second step, leave “Don\'t ask again on this device” ticked, and that device will appear here.') }}</p>
                @endif
                <ul class="list-group list-group-flush">
                    @foreach ($this->trustedDevices as $d)
                        <li class="list-group-item d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                            <div>
                                <div>{{ (string) ($d['name'] ?? t('A browser')) }}</div>
                                <small class="text-muted">
                                    {{ t('Trusted %s · until %s', localDateTime((int) ($d['created_at'] ?? 0)), localDateTime((int) ($d['expires_at'] ?? 0))) }}
                                </small>
                            </div>
                            @if (!empty($d['is_current']))
                                <span class="badge bg-success">{{ t('This device') }}</span>
                            @endif
                            <form method="post" action="{!! sURL . $routeBase !!}/revokedevice" class="m-0">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <input type="hidden" name="device" value="{!! (int) ($d['device_id'] ?? 0) !!}">
                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ t('Forget') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif

            <!-- Active sessions -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Active Sessions') }}</div>
                @if (empty($this->activeSessions))
                    <div class="card-body text-muted">{{ t('No active sessions.') }}</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($this->activeSessions as $s)
                            @php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); @endphp
                            <li class="list-group-item d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                                <div>
                                    <div>{{ substr((string) ($s['agent'] ?? t('Unknown device')), 0, 80) }}</div>
                                    <small class="text-muted">
                                        {{ (string) ($s['host_addr'] ?? '') }}
                                        &middot; {{ localDateTime((int) ($s['time'] ?? 0)) }}
                                    </small>
                                </div>
                                @if ($isCurrent)
                                    <span class="badge bg-success">{{ t('This device') }}</span>
                                @else
                                    <form method="post" action="{!! sURL . $routeBase !!}/revokesession" class="m-0">
                                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                        <input type="hidden" name="sid" value="{{ (string) ($s['sid'] ?? '') }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ t('Sign out') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <!-- Recent activity -->
            <div class="card">
                <div class="card-header fw-semibold">{{ t('Recent Account Activity') }}</div>
                @if (empty($this->recentActivity))
                    <div class="card-body text-muted">{{ t('No activity recorded yet.') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ t('Event') }}</th>
                                    <th>{{ t('Date') }}</th>
                                    <th>{{ t('IP Address') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->recentActivity as $entry)
                                    <tr>
                                        <td>{{ $entry['action'] }}</td>
                                        <td class="text-muted small">{{ $entry['created_at'] }}</td>
                                        <td class="text-muted small">{{ $entry['ip_address'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>
