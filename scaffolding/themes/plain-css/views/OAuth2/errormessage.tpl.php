{{-- OAuth2 error message page (plain-CSS theme).

Variables:
  $this->error — Error description string --}}
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        @include('../partials/auth_brand')
        <div class="card-body" style="padding:28px">
            <h2 style="color:#c0392b;margin-bottom:16px">{{ t('Authorization Error') }}</h2>
            <p>{{ $this->error ?? t('An unknown error occurred.') }}</p>
            <a href="javascript:history.back()" class="btn" style="margin-top:12px">&larr; {{ t('Go Back') }}</a>
        </div>
    </div>
</div>
