{{-- OAuth2 default/fallback page (plain-CSS theme).

Variables:
  $this->header — Page heading string --}}
<div class="page-section">
    <h2>{{ $this->header ?? t('OAuth2') }}</h2>
    <div role="status" class="alert alert-info">
        <strong>{{ t('OAuth2 Server') }}</strong> — {{ t('This is the default OAuth2 view. A specific template was not found for the requested action.') }}
    </div>
    <a href="{!! sURL . rawurlencode((string) ($this->routeBase ?? 'Account')) !!}" class="btn">{{ t('Your account') }}</a>
    <a href="{!! sURL !!}" class="btn" style="margin-left:8px">{{ t('Home') }}</a>
</div>
