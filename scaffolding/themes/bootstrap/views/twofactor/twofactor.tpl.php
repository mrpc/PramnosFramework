@php /**
 * Two-Factor Authentication overview page (Bootstrap theme).
 *
 * Rendered by the TwoFactorAuth controller; the account sidebar/breadcrumb
 * point at the Account controller via accountBase.
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Two-Factor Authentication') }}</h2>

    @if (!empty($_GET['error']))
        <div role="alert" class="alert alert-danger">
            @php $messages = [
                'already_enabled'  => t('Two-factor authentication is already enabled.'),
                'password_required' => t('Please enter your password to disable 2FA.'),
                'invalid_password'  => t('Incorrect password. 2FA was not disabled.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.')); @endphp
        </div>
    @endif
    @if (!empty($_GET['success']))
        <div role="status" class="alert alert-success">
            @php $messages = ['disabled' => t('Two-factor authentication has been disabled.')];
            echo htmlspecialchars($messages[$_GET['success']] ?? t('Done.')); @endphp
        </div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        @if ($this->status['enabled'])
                            <span class="badge bg-success fs-6 me-3">{{ t('Enabled') }}</span>
                        @else
                            <span class="badge bg-secondary fs-6 me-3">{{ t('Disabled') }}</span>
                        @endif
                        <h5 class="card-title mb-0">
                            {{ $this->status['enabled'] ? t('Your account is protected') : t('Add extra security') }}
                        </h5>
                    </div>

                    <p class="card-text text-muted">
                        {{ t('Two-factor authentication adds a second layer of security to your account. After entering your password you will be asked for a code from your authenticator app.') }}
                    </p>

                    @if ($this->status['enabled'])
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <div class="border rounded p-3 text-center">
                                    <div class="fs-3 fw-bold text-primary">{!! (int) $this->status['backup_codes_remaining'] !!}</div>
                                    <div class="small text-muted">{{ t('Backup codes remaining') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <a href="{!! sURL !!}TwoFactorAuth/backup" class="btn btn-outline-primary">
                                {{ t('Manage Backup Codes') }}
                            </a>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disableModal">
                                {{ t('Disable 2FA') }}
                            </button>
                        </div>
                    @else
                        <a href="{!! sURL !!}TwoFactorAuth/setup" class="btn btn-primary">
                            {{ t('Enable Two-Factor Authentication') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>

@if ($this->status['enabled'])
<!-- Disable 2FA confirmation modal -->
<div class="modal fade" id="disableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ t('Disable Two-Factor Authentication') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{!! sURL !!}TwoFactorAuth/disable">
                <div class="modal-body">
                    <p>{{ t('Enter your account password to confirm disabling 2FA.') }}</p>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">{{ t('Password') }}</label>
                        <input type="password" class="form-control" id="confirm_password"
                               name="confirm_password" required autocomplete="current-password" enterkeyhint="go">
                        {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ t('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger">{{ t('Disable 2FA') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
