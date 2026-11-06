{{-- Set new password form (Tailwind theme).

Variables:
  $this->error      — Optional error string
  $this->user->userid — User ID for the reset token
  $this->user->reset  — Reset token --}}
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm">
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold mb-6">{{ t('Set New Password') }}</h1>

        @if (!empty($this->error))
            <div role="alert" class="alert alert-error mb-4">{{ $this->error }}</div>
        @endif
        @if ($this->hasErrors())
            <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
        @endif
        @if ($this->hasMessages())
            <div role="status" class="alert alert-info mb-4">{!! $this->_printMessages() !!}</div>
        @endif
        <div id="formError" role="alert" class="alert alert-error mb-4 hidden"></div>

        <form method="POST" action="{!! sURL !!}Home/rpcsave" class="space-y-4" onsubmit="return validateNewPassword()">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="userid" value="{!! (int) $this->user->userid !!}">
            <input type="hidden" name="reset" value="{{ $this->user->reset ?? '' }}">
            <div>
                <label for="password" class="block text-sm font-medium text-base-content mb-1">{{ t('New Password') }}</label>
                <input type="password" name="password" id="password"
                       class="input w-full"
                       required minlength="8" placeholder="{{ t('At least 8 chars, digit and symbol') }}">
                {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
            </div>
            <div>
                <label for="repassword" class="block text-sm font-medium text-base-content mb-1">{{ t('Confirm Password') }}</label>
                <input type="password" name="repassword" id="repassword"
                       class="input w-full"
                       required>
                {!! \Pramnos\Html\PasswordToggle::render('repassword', '', '') !!}
            </div>
            <button type="submit" class="btn btn-primary w-full">{{ t('Save New Password') }}</button>
        </form>
        <p class="text-center text-sm mt-4">
            <a href="{!! sURL !!}login" class="text-primary hover:underline">&larr; {{ t('Back to login') }}</a>
        </p>
    </div>
    @include('../partials/auth_home_link')
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.classList.add('hidden'); err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = {!! json_encode(t('Password must be at least 8 characters and contain a digit and a symbol.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        err.classList.remove('hidden'); pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = {!! json_encode(t('Passwords do not match.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        err.classList.remove('hidden'); re.focus(); return false;
    }
    return true;
}
</script>
