@php /**
 * Two-Factor Authentication backup codes page (Bootstrap theme).
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
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Backup Codes') }}</h2>

    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    @if (!empty($this->setupComplete))
        <div role="status" class="alert alert-info">
            {!! sprintf(htmlspecialchars(t('%s Save your backup codes before leaving this page.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('Setup complete!'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
        </div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            @if (!empty($this->newBackupCodes))
            <div class="card mb-4 border-warning">
                <div class="card-header bg-warning-subtle fw-semibold">{{ t('New Backup Codes') }}</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        {!! sprintf(htmlspecialchars(t('%s They replace your previous codes and will not be shown again.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('Save these codes now.'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                    </p>
                    <div class="row row-cols-2 g-2 mb-2">
                        @foreach ($this->newBackupCodes as $code)
                            <div class="col">
                                <code class="d-block text-center border rounded py-1 bg-light">
                                    {{ $code }}
                                </code>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">{{ t('Remaining codes') }}</h5>
                    <p class="card-text">
                        {!! sprintf(htmlspecialchars($this->remainingCodes === 1 ? t('You have %s backup code remaining.') : t('You have %s backup codes remaining.'), ENT_QUOTES, 'UTF-8'), '<strong class="' . ($this->remainingCodes <= 2 ? 'text-danger' : 'text-success') . '">' . (int) $this->remainingCodes . '</strong>') !!}
                    </p>
                    @if ($this->remainingCodes <= 2)
                        <p class="text-danger small">{{ t('Running low — consider regenerating your codes.') }}</p>
                    @endif
                    <p class="small text-muted">
                        {{ t('Use a backup code instead of your authenticator app when you don\'t have your device. Each code can only be used once.') }}
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold">{{ t('Regenerate Backup Codes') }}</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        {{ t('Generating new codes will invalidate all existing ones. Enter your account password to confirm.') }}
                    </p>
                    <form method="post" action="{!! sURL !!}TwoFactorAuth/backup">
                        <div class="mb-3">
                            <label for="regenerate_password" class="form-label">{{ t('Password') }}</label>
                            <input type="password" class="form-control" id="regenerate_password"
                                   name="regenerate_password" required autocomplete="current-password" enterkeyhint="go">
                            {!! \Pramnos\Html\PasswordToggle::render('regenerate_password', '', '') !!}
                        </div>
                        <button type="submit" class="btn btn-warning">{{ t('Regenerate Codes') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
