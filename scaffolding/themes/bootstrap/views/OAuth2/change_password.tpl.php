@php /**
 * Change Password page (Bootstrap theme).
 *
 * Server-side rejections arrive as flash errors (Base::addError); client-side
 * policy hints come from pf-auth.js via [data-pf-password-error].
 * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'changepassword'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Change Password') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <div class="card" style="max-width:520px">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ t('Choose a strong password: at least 8 characters, one digit, and one special character.') }}
                    </p>
                    <div data-pf-password-error role="alert" class="alert alert-danger d-none"></div>
                    <form method="post" action="{!! sURL . $routeBase !!}/changepassword" data-pf-password-policy>
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <div class="mb-3">
                            <label for="current_password" class="form-label">{{ t('Current Password') }}</label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go" autofocus>
                            {!! \Pramnos\Html\PasswordToggle::render('current_password', '', '') !!}
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">{{ t('New Password') }}</label>
                            <input type="password" id="new_password" name="new_password"
                                   class="form-control" required autocomplete="new-password"
                                   minlength="8" pattern="(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}">
                            {!! \Pramnos\Html\PasswordToggle::render('new_password', '', '') !!}
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">{{ t('Confirm New Password') }}</label>
                            <input type="password" id="confirm_password" name="confirm_password" enterkeyhint="go"
                                   class="form-control" required autocomplete="new-password">
                            {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                        </div>
                        <button type="submit" class="btn btn-primary">{{ t('Update Password') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
