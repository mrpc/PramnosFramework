@php /**
 * Two-Factor Authentication overview page (plain-CSS theme).
 *
 * Rendered by the TwoFactorAuth controller (routeBase 'TwoFactorAuth'); the
 * account sidebar/breadcrumb point at the Account controller via accountBase.
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Two-Factor Authentication') }}</h2>

    @if (!empty($_GET['error']))
        <div role="alert" class="alert alert-error">
            @php $messages = [
                'already_enabled'   => t('Two-factor authentication is already enabled.'),
                'password_required' => t('Please enter your password to disable 2FA.'),
                'invalid_password'  => t('Incorrect password. 2FA was not disabled.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.'), ENT_QUOTES, 'UTF-8'); @endphp
        </div>
    @endif
    @if (!empty($_GET['success']))
        <div role="status" class="alert alert-success">
            @php $messages = ['disabled' => t('Two-factor authentication has been disabled.')];
            echo htmlspecialchars($messages[$_GET['success']] ?? t('Done.'), ENT_QUOTES, 'UTF-8'); @endphp
        </div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <div class="card" style="max-width:540px">
                <div class="card-body">
                    <p>
                        <strong>{{ t('Status:') }}</strong>
                        @if ($this->status['enabled'])
                            <span class="badge badge-success">{{ t('Enabled') }}</span>
                        @else
                            <span class="badge badge-secondary">{{ t('Disabled') }}</span>
                        @endif
                    </p>

                    <p>
                        {{ t('Two-factor authentication adds a second layer of security to your account. After entering your password you will be asked for a code from your authenticator app.') }}
                    </p>

                    @if ($this->status['enabled'])
                        <p>{!! sprintf(htmlspecialchars(t('%s backup codes remaining.'), ENT_QUOTES, 'UTF-8'), '<strong>' . (int) $this->status['backup_codes_remaining'] . '</strong>') !!}</p>

                        <p>
                            <a href="{!! sURL !!}TwoFactorAuth/backup" class="btn">{{ t('Manage Backup Codes') }}</a>
                        </p>

                        <hr>
                        <h4>{{ t('Disable Two-Factor Authentication') }}</h4>
                        <form method="post" action="{!! sURL !!}TwoFactorAuth/disable">
                            <div class="form-group">
                                <label for="confirm_password">{{ t('Confirm your password to disable 2FA:') }}</label>
                                <input type="password" id="confirm_password" name="confirm_password"
                                       required autocomplete="current-password" enterkeyhint="go" class="form-control">
                                {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                            </div>
                            <button type="submit" class="btn btn-danger">{{ t('Disable 2FA') }}</button>
                        </form>

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
