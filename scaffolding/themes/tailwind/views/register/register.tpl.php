@php /**
 * User registration form (Tailwind theme).
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
$brand    = $this->brand ?? [];
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
    'human_check'            => t('The security check did not complete. Reload the page and try again — it needs a modern browser with JavaScript enabled.'),
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
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4 py-8">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-md">
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold mb-6">{{ $this->header ?? t('Create Account') }}</h1>

        @if ($errorText !== '')
            <div role="alert" id="form-error" class="alert alert-error mb-4">{{ $errorText }}</div>
        @endif
        @if ($flashed !== '')
            <div role="alert" class="alert alert-error mb-4">{!! $flashed !!}</div>
        @endif
        @if ($closedNotice !== '')
            <div role="status" class="alert alert-info mb-4">{{ $closedNotice }}</div>
        @endif

        @if (!$closed)
        <form data-pf-progress method="POST" action="{!! sURL !!}register" class="space-y-4">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            {{-- The human check's fields, when the application asks for one. Renders
                     nothing otherwise, so the insert is unconditional. --}}
            {!! humanCheckField($this->humanCheck ?? null) !!}
            <div>
                <label for="username" class="block text-sm font-medium text-base-content mb-1">{{ t('Username') }}</label>
                <input type="text" name="username" id="username" class="input w-full"
                       required autocomplete="username"{!! $errorFieldAttributes !!} autocapitalize="none" autocorrect="off" spellcheck="false" value="{{ $this->formData['username'] ?? '' }}">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-base-content mb-1">{{ t('Email Address') }}</label>
                <input type="email" name="email" id="email" class="input w-full"
                       required autocomplete="email" value="{{ ($this->invitedEmail ?? '') !== '' ? $this->invitedEmail : ($this->formData['email'] ?? '') }}"{!! ($this->invitedEmail ?? '') !== '' ? ' readonly' : '' !!}>
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-base-content mb-1">{{ t('Password') }}</label>
                <input type="password" name="password" id="password" class="input w-full"
                       required minlength="8" autocomplete="new-password">
                {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                <p class="text-xs text-base-content/70 mt-1">{{ t('At least 8 characters, with a digit and a symbol.') }}</p>
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-base-content mb-1">{{ t('Confirm Password') }}</label>
                <input type="password" name="confirm_password" enterkeyhint="go" id="confirm_password" class="input w-full"
                       required autocomplete="new-password">
                {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
            </div>
            <button type="submit" class="btn btn-success w-full">{{ t('Create Account') }}</button>
        </form>
        @endif
        <p class="text-center text-sm mt-4">
            <a href="{!! sURL !!}login" class="text-primary hover:underline">{{ t('Already have an account? Sign in') }}</a>
        </p>
    </div>
    @include('../partials/auth_home_link')
</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
