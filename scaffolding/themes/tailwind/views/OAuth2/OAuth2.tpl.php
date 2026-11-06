{{-- OAuth2 default/fallback page (Tailwind theme).

Variables:
  $this->header — Page heading string --}}
<div class="container mx-auto py-10 px-4">
    <h2 class="text-2xl font-semibold mb-4">{{ $this->header ?? t('OAuth2') }}</h2>
    <div role="status" class="alert alert-info mb-6 block">
        <strong>{{ t('OAuth2 Server') }}</strong> — {{ t('This is the default OAuth2 view. A specific template was not found for the requested action.') }}
    </div>
    <a href="{!! sURL . rawurlencode((string) ($this->routeBase ?? 'Account')) !!}" class="btn btn-primary mr-2">{{ t('Your account') }}</a>
    <a href="{!! sURL !!}" class="btn btn-neutral">{{ t('Home') }}</a>
</div>
