{{-- Set new password form (Bootstrap theme).

Variables:
  $this->error      — Optional error string
  $this->user->userid — User ID for the reset token
  $this->user->reset  — Reset token --}}
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')
                    <h1 class="h4 mb-3">{{ t('Set New Password') }}</h1>

                    @if (!empty($this->error))
                        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
                    @endif
                    @if ($this->hasErrors())
                        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
                    @endif
                    @if ($this->hasMessages())
                        <div role="status" class="alert alert-info">{!! $this->_printMessages() !!}</div>
                    @endif
                    <div id="formError" role="alert" class="alert alert-danger d-none"></div>

                    <form method="POST" action="{!! sURL !!}Home/rpcsave" onsubmit="return validateNewPassword()">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="userid" value="{!! (int) $this->user->userid !!}">
                        <input type="hidden" name="reset" value="{{ $this->user->reset ?? '' }}">
                        <div class="mb-3">
                            <label for="password" class="form-label">{{ t('New Password') }}</label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="8"
                                   placeholder="{{ t('At least 8 characters, a digit, and a symbol') }}">
                            {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                        </div>
                        <div class="mb-3">
                            <label for="repassword" class="form-label">{{ t('Confirm Password') }}</label>
                            <input type="password" name="repassword" id="repassword" class="form-control" required
                                   placeholder="{{ t('Repeat your new password') }}">
                            {!! \Pramnos\Html\PasswordToggle::render('repassword', '', '') !!}
                        </div>
                        <button type="submit" class="btn btn-primary w-100">{{ t('Save New Password') }}</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="{!! sURL !!}login" class="small">&larr; {{ t('Back to login') }}</a>
                    </div>
                </div>
            </div>
            @include('../partials/auth_home_link')
        </div>
    </div>
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.classList.add('d-none'); err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = {!! json_encode(t('Password must be at least 8 characters and contain a digit and a symbol.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        err.classList.remove('d-none'); pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = {!! json_encode(t('Passwords do not match.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        err.classList.remove('d-none'); re.focus(); return false;
    }
    return true;
}
</script>
