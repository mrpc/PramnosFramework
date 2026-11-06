{{-- Device authorization error page (plain-CSS theme).

Variables:
  $this->error    — Error message string
  $this->userCode — User code for retry link (optional) --}}
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        @include('../partials/auth_brand')
        <div class="card-body" style="padding:28px">
            <div style="font-size:3rem;margin-bottom:12px">&#9888;&#65039;</div>
            <h2 style="margin:0 0 16px;font-size:1.3rem">{{ t('Authorization Error') }}</h2>
            <div role="alert" class="alert alert-danger">{{ $this->error ?? t('An error occurred.') }}</div>
            <a href="?user_code={!! urlencode($this->userCode ?? '') !!}" class="btn" style="margin-top:8px">&larr; {{ t('Try Again') }}</a>
        </div>
    </div>
</div>
