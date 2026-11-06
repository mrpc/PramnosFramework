{{-- Set new password form (plain-CSS theme).

Variables:
  $this->error      — Optional error string
  $this->user->userid — User ID for the reset token
  $this->user->reset  — Reset token --}}
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        @include('../partials/auth_brand')
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem">{{ t('Set New Password') }}</h2></div>
        <div class="card-body" style="padding:24px">

            @if (!empty($this->error))
                <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
            @endif
            @if ($this->hasErrors())
                <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
            @endif
            @if ($this->hasMessages())
                <div role="status" class="alert alert-info">{!! $this->_printMessages() !!}</div>
            @endif
            <div id="formError" role="alert" class="alert alert-danger" style="display:none"></div>

            <form method="POST" action="{!! sURL !!}Home/rpcsave" onsubmit="return validateNewPassword()">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="userid" value="{!! (int) $this->user->userid !!}">
                <input type="hidden" name="reset" value="{{ $this->user->reset ?? '' }}">
                <div style="margin-bottom:16px">
                    <label for="password" style="display:block;margin-bottom:4px;font-weight:500">{{ t('New Password') }}</label>
                    <input type="password" name="password" id="password"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required minlength="8" placeholder="{{ t('At least 8 chars, digit and symbol') }}">
                    {!! \Pramnos\Html\PasswordToggle::render('password', '', '') !!}
                </div>
                <div style="margin-bottom:20px">
                    <label for="repassword" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Confirm Password') }}</label>
                    <input type="password" name="repassword" id="repassword"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required>
                    {!! \Pramnos\Html\PasswordToggle::render('repassword', '', '') !!}
                </div>
                <button type="submit" class="btn" style="width:100%">{{ t('Save New Password') }}</button>
            </form>
            <div style="text-align:center;margin-top:12px">
                <a href="{!! sURL !!}login" style="font-size:13px">&larr; {{ t('Back to login') }}</a>
            </div>
        </div>
    </div>
    @include('../partials/auth_home_link')
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.style.display = 'none'; err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = {!! json_encode(t('Password must be at least 8 characters and contain a digit and a symbol.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        err.style.display = 'block'; pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = {!! json_encode(t('Passwords do not match.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        err.style.display = 'block'; re.focus(); return false;
    }
    return true;
}
</script>
