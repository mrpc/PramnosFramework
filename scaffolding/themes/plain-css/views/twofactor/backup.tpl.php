@php /**
 * Two-Factor Authentication backup codes page (plain-CSS theme).
 *
 * Rendered by the TwoFactorAuth controller; account sidebar/breadcrumb point at
 * the Account controller via accountBase.
 *
 * Variables:
 *   $this->user             — User object
 *   $this->remainingCodes   — int
 *   $this->newBackupCodes   — string[] (set after enrolment and after regeneration)
 *   $this->success          — string|null
 *   $this->error            — string|null
 *   $this->setupComplete    — bool
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor_backup'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Backup Codes') }}</h2>

    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-error">{{ $this->error }}</div>
    @endif
    @if (!empty($this->setupComplete))
        <div role="status" class="alert alert-info">
            <strong>{{ t('Setup complete!') }}</strong> {{ t('Save your backup codes before leaving this page.') }}
        </div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            @if (!empty($this->newBackupCodes))
            <div class="card" style="border-color:#f0ad4e;margin-bottom:16px">
                <div class="card-header" style="background:#fcf8e3"><strong>{{ t('New Backup Codes') }}</strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666">
                        <strong>{{ t('Save these codes now.') }}</strong> {{ t('They replace your previous codes and will not be shown again.') }}
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                        @foreach ($this->newBackupCodes as $code)
                            <code style="display:block;text-align:center;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:6px">
                                {{ $code }}
                            </code>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <div class="card" style="margin-bottom:16px">
                <div class="card-body">
                    <h4>{{ t('Remaining codes') }}</h4>
                    <p>
                        {!! sprintf(htmlspecialchars($this->remainingCodes === 1 ? t('You have %s backup code remaining.') : t('You have %s backup codes remaining.'), ENT_QUOTES, 'UTF-8'), '<strong>' . (int) $this->remainingCodes . '</strong>') !!}
                        @if ($this->remainingCodes <= 2)
                            <span style="color:#c00"> ({{ t('Running low — consider regenerating.') }})</span>
                        @endif
                    </p>
                    <p style="font-size:.85em;color:#666">
                        {{ t('Use a backup code instead of your authenticator app when you don\'t have your device. Each code can only be used once.') }}
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>{{ t('Regenerate Backup Codes') }}</strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666;margin-bottom:12px">
                        {{ t('Generating new codes will invalidate all existing ones. Enter your account password to confirm.') }}
                    </p>
                    <form method="post" action="{!! sURL !!}TwoFactorAuth/backup">
                        <div class="form-group">
                            <label for="regenerate_password">{{ t('Password') }}</label>
                            <input type="password" id="regenerate_password" name="regenerate_password"
                                   required autocomplete="current-password" enterkeyhint="go" class="form-control">
                            {!! \Pramnos\Html\PasswordToggle::render('regenerate_password', '', '') !!}
                        </div>
                        <button type="submit" class="btn">{{ t('Regenerate Codes') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
