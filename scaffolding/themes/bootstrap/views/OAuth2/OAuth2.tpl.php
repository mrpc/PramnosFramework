{{-- OAuth2 default/fallback page (Bootstrap theme).

Variables:
  $this->header — Page heading string --}}
<div class="container py-5">
    <h2>{{ $this->header ?? t('OAuth2') }}</h2>
    <div role="status" class="alert alert-info">
        {!! sprintf(htmlspecialchars(t('%s — This is the default OAuth2 view. A specific template was not found for the requested action.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('OAuth2 Server'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
    </div>
    <a href="{!! sURL . rawurlencode((string) ($this->routeBase ?? 'Account')) !!}" class="btn btn-primary me-2">{{ t('Your account') }}</a>
    <a href="{!! sURL !!}" class="btn btn-secondary">{{ t('Home') }}</a>
</div>
