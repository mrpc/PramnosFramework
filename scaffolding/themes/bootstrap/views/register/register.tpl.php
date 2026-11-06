@php /**
 * User registration form (Bootstrap theme).
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderRegister):
 *   $this->header             — Page heading
 *   $this->brand              — name / logo / footer from the application settings
 *   $this->error              — Error key, mapped to a message below
 *   $this->formData           — Previously submitted username / email
 *   $this->registrationOpen   — false when the auth_allow_registration setting is off
 *
 * The error arrives as a key rather than a sentence so the controller does not
 * decide wording and this file does not decide policy.
 */
$brand    = $this->brand ?? \Pramnos\Auth\Brand::current();
$messages = [
    'registration_closed'    => t('Registration is not open on this server.'),
    'invalid_token'          => t('Your session expired. Please try again.'),
    'username_required'      => t('Please choose a username.'),
    'username_length'        => t('A username must be between 3 and 60 characters.'),
    'username_invalid'       => t('A username may contain letters, digits, dots, dashes and underscores only.'),
    'username_taken'         => t('That username is already taken.'),
    'invalid_email'          => t('Please enter a valid email address.'),
    'email_unavailable'      => t('That email address cannot be used to create an account.'),
    'invite_email_mismatch'  => t('This invitation is for another email address.'),
    'email_domain_not_allowed' => t('Accounts can only be created with an address at an allowed domain.'),
    'password_required'      => t('Please choose a password.'),
    'password_too_short'     => t('Your password must be at least 8 characters long.'),
    'password_needs_digit'   => t('Your password must contain at least one digit.'),
    'password_needs_symbol'  => t('Your password must contain at least one symbol.'),
    'passwords_do_not_match' => t('The two passwords do not match.'),
    'registration_failed'    => t('The account could not be created. Please try again later.'),
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $messages[$errorKey] ?? $errorKey;
/*
 * The error box's id, and the attributes that point the first field at it.
 *
 * `role="alert"` on its own is unreliable for an error that is already in the document when the
 * page loads: a screen reader announces a live region when it *changes*, and this one never
 * changed. What works with no JavaScript at all is the description — the field is marked invalid
 * and described by the box, so the message is read out as part of the field the moment focus
 * lands on it, and focus lands there on load because the first field carries `autofocus`.
 *
 * The *first* field only. These errors are form-level — «wrong username or password» is about the
 * pair — and marking four fields invalid to report one failure tells a screen reader four things
 * that are not true.
 */
$errorFieldAttributes = $errorText !== ''
    ? ' aria-invalid="true" aria-describedby="form-error"'
    : '';

$closed    = ($this->registrationOpen ?? true) === false;
/*
 * A closed registration is said once, as a notice: it is the state of the server, not a mistake on
 * the form. The refusal can arrive both as the error key and as a flashed error, so the flashed
 * errors are read once here and the notice is left out when they already say it.
 */
$flashed      = $this->hasErrors() ? (string) $this->_printErrors() : '';
$closedText   = $messages['registration_closed'];
$closedNotice = ($closed || $errorKey === 'registration_closed') && !str_contains($flashed, htmlspecialchars($closedText))
    ? $closedText
    : '';
if ($errorKey === 'registration_closed') {
    $errorText = '';
    $errorFieldAttributes = '';
} @endphp
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')
                    <h1 class="h4 mb-3">{{ $this->header ?? t('Create Account') }}</h1>

                    @if ($errorText !== '')
                        <div role="alert" id="form-error" class="alert alert-danger">{{ $errorText }}</div>
                    @endif
                    @if ($flashed !== '')
                        <div role="alert" class="alert alert-danger">{!! $flashed !!}</div>
                    @endif
                    @if ($closedNotice !== '')
                        <div role="status" class="alert alert-info">{{ $closedNotice }}</div>
                    @endif

                    @if (!$closed)
                    <form data-pf-progress method="POST" action="{!! sURL !!}register">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <div class="mb-3">
                            <label for="username" class="form-label">{{ t('Username') }}</label>
                            <input type="text" name="username" id="username" class="form-control" required autocomplete="username"{!! $errorFieldAttributes !!} autocapitalize="none" autocorrect="off" spellcheck="false"
                                   value="{{ $this->formData['username'] ?? '' }}">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">{{ t('Email Address') }}</label>
                            <input type="email" name="email" id="email" class="form-control" required autocomplete="email"
                                   value="{{ ($this->invitedEmail ?? '') !== '' ? $this->invitedEmail : ($this->formData['email'] ?? '') }}"{!! ($this->invitedEmail ?? '') !== '' ? ' readonly' : '' !!}>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">{{ t('Password') }}</label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="8" autocomplete="new-password">
                            {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                            <div class="form-text">{{ t('At least 8 characters, with a digit and a symbol.') }}</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">{{ t('Confirm Password') }}</label>
                            <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="form-control" required autocomplete="new-password">
                            {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                        </div>
                        <button type="submit" class="btn btn-success w-100">{{ t('Create Account') }}</button>
                    </form>
                    @endif
                    <div class="text-center mt-3">
                        <a href="{!! sURL !!}login" class="small">{{ t('Already have an account? Sign in') }}</a>
                    </div>
                </div>
            </div>
            @include('../partials/auth_home_link')
        </div>
    </div>
</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
