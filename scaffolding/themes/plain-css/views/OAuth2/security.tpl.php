@php /**
 * Security Overview page (plain-CSS theme).
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
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Security Overview') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <!-- 2FA status -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong>{{ t('Two-Factor Authentication') }}</strong></div>
                <div class="card-body pf-row-split">
                    <p style="margin:0">
                        @if ($this->twoFactorEnabled)
                            <span style="color:#28a745;font-weight:bold">&#10003; {{ t('Enabled') }}</span>
                            — {{ t('Your account is protected with two-factor authentication.') }}
                        @else
                            <span style="color:#dc3545;font-weight:bold">&#10007; {{ t('Disabled') }}</span>
                            — {{ t('Enable 2FA to protect your account.') }}
                        @endif
                    </p>
                    <a href="{!! sURL !!}TwoFactorAuth" class="btn">
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
            <div class="card" style="margin-bottom:20px">
                <div class="card-header"><strong>{{ t('Sign-in codes by email') }}</strong></div>
                <div class="card-body" style="padding:16px">
                    <p style="margin:0 0 12px">
                        @if (!empty($this->emailFactorEnabled))
                            <span class="badge badge-success">{{ t('On') }}</span>
                            {{ t('We email you a code when you sign in.') }}
                        @else
                            <span class="badge">{{ t('Off') }}</span>
                            {{ t('Weaker than an authenticator app, and better than a password alone.') }}
                        @endif
                    </p>
                    <form method="POST" action="{!! sURL . $routeBase !!}/emailfactor"
                          style="display:flex;gap:8px;flex-wrap:wrap">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="enable" value="{!! empty($this->emailFactorEnabled) ? '1' : '0' !!}">
                        <input id="password" type="password" name="password" required autocomplete="current-password" enterkeyhint="go"
                               style="flex:1;min-width:180px;padding:8px;border:1px solid #ccc;border-radius:4px"
                               placeholder="{{ t('Your password') }}">
                        {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                        <button type="submit" class="btn btn-sm">
                            {{ empty($this->emailFactorEnabled) ? t('Turn on') : t('Turn off') }}
                        </button>
                    </form>
                </div>
            </div>
            @endif

            <!-- Passkeys -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong>{{ t('Passkeys') }}</strong></div>
                <div class="card-body pf-row-split">
                    <p style="margin:0;font-size:.9em;color:#666">
                        {{ t('Sign in without a password using your device\'s fingerprint, face or screen lock.') }}
                    </p>
                    <a href="{!! sURL !!}Passkey" class="btn">{{ t('Manage Passkeys') }}</a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-body pf-row-split">
                    <p style="margin:0;font-size:.9em;color:#666">
                        {{-- Not "change it regularly": routine rotation is advice that has been
                     withdrawn by the people who used to give it (NIST SP 800-63B), because
                     forced changes produce predictable variations of one password and get
                     written down. Change it when there is a reason to. --}}
                        {{ t('Change it if you think somebody else knows it, or if you have used it anywhere else.') }}
                    </p>
                    <a href="{!! sURL . $routeBase !!}/changepassword" class="btn">{{ t('Change Password') }}</a>
                </div>
            </div>

            <!-- Trusted devices: "don't ask again on this device", each revocable -->
            {{-- Shown whenever the site offers it, so a page that says "your trusted devices" has
                     them — or says how a device becomes one. --}}
            @if (!empty($this->trustedDevicesOffered))
            <div class="card" style="margin-bottom:16px">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                    <strong>{{ t('Trusted devices') }}</strong>
@if (!empty($this->trustedDevices))
                    <form method="post" action="{!! sURL . $routeBase !!}/revokedevice" style="margin:0">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="btn btn-sm" style="background:transparent;color:#b91c1c;border-color:transparent">{{ t('Forget all') }}</button>
                    </form>
                    @endif
                </div>
                <div class="card-body" style="padding:0">
                    <p style="padding:12px 16px 0;margin:0;font-size:.85em;color:#666">{{ t('These skip the second step when you sign in, and can approve your sign-ins elsewhere.') }}</p>
                @if (empty($this->trustedDevices))
                    <p style="padding:12px 16px;margin:0;font-size:.9em;color:#444">{{ t('No device is trusted yet. When you sign in with your second step, leave “Don\'t ask again on this device” ticked, and that device will appear here.') }}</p>
                @endif
                    <table style="width:100%;border-collapse:collapse;font-size:.9em">
                        <tbody>
                        @foreach ($this->trustedDevices as $d)
                            <tr style="border-bottom:1px solid #f5f5f5">
                                <td style="padding:10px 16px">
                                    <div>{{ (string) ($d['name'] ?? t('A browser')) }}</div>
                                    <small style="color:#888">
                                        {{ t('Trusted %s · until %s', localDateTime((int) ($d['created_at'] ?? 0)), localDateTime((int) ($d['expires_at'] ?? 0))) }}
                                    </small>
                                </td>
                                <td style="padding:10px 16px;text-align:right;white-space:nowrap">
                                    @if (!empty($d['is_current']))
                                        <span style="color:#28a745;font-size:.85em;margin-right:8px">{{ t('This device') }}</span>
                                    @endif
                                    <form method="post" action="{!! sURL . $routeBase !!}/revokedevice" style="margin:0;display:inline">
                                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                        <input type="hidden" name="device" value="{!! (int) ($d['device_id'] ?? 0) !!}">
                                        <button type="submit" class="btn btn-sm">{{ t('Forget') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <!-- Active sessions -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><strong>{{ t('Active Sessions') }}</strong></div>
                <div class="card-body" style="padding:0">
                    @if (empty($this->activeSessions))
                        <p style="padding:12px 16px;color:#666;margin:0">{{ t('No active sessions.') }}</p>
                    @else
                        <table style="width:100%;border-collapse:collapse;font-size:.9em">
                            <tbody>
                            @foreach ($this->activeSessions as $s)
                                @php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); @endphp
                                <tr style="border-bottom:1px solid #f5f5f5">
                                    <td style="padding:10px 16px">
                                        <div>{{ substr((string) ($s['agent'] ?? t('Unknown device')), 0, 80) }}</div>
                                        <small style="color:#888">
                                            {{ (string) ($s['host_addr'] ?? '') }}
                                            · {{ localDateTime((int) ($s['time'] ?? 0)) }}
                                        </small>
                                    </td>
                                    <td style="padding:10px 16px;text-align:right">
                                        @if ($isCurrent)
                                            <span style="color:#28a745;font-size:.85em">{{ t('This device') }}</span>
                                        @else
                                            <form method="post" action="{!! sURL . $routeBase !!}/revokesession" style="margin:0">
                                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                                <input type="hidden" name="sid" value="{{ (string) ($s['sid'] ?? '') }}">
                                                <button type="submit" class="btn btn-sm">{{ t('Sign out') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <!-- Activity log -->
            <div class="card">
                <div class="card-header"><strong>{{ t('Recent Account Activity') }}</strong></div>
                <div class="card-body" style="padding:0">
                    @if (empty($this->recentActivity))
                        <p style="padding:12px 16px;color:#666;margin:0">{{ t('No activity recorded yet.') }}</p>
                    @else
                        <table style="width:100%;border-collapse:collapse;font-size:.9em">
                            <thead style="background:#f8f8f8">
                                <tr>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">{{ t('Event') }}</th>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">{{ t('Date') }}</th>
                                    <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">{{ t('IP Address') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->recentActivity as $entry)
                                    <tr style="border-bottom:1px solid #f5f5f5">
                                        <td style="padding:10px 16px">{{ $entry['action'] }}</td>
                                        <td style="padding:10px 16px;color:#888;font-size:.85em">{{ $entry['created_at'] }}</td>
                                        <td style="padding:10px 16px;color:#888;font-size:.85em">{{ $entry['ip_address'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>
