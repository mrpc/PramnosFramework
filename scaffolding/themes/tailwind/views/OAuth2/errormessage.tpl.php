{{-- OAuth2 error message page (Tailwind theme).

Variables:
  $this->error — Error description string --}}
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm text-center">
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold text-error mb-4">{{ t('Authorization Error') }}</h1>
        <p class="text-base-content mb-6">{{ $this->error ?? t('An unknown error occurred.') }}</p>
        <a href="javascript:history.back()" class="btn btn-outline inline-block">&larr; {{ t('Go Back') }}</a>
    </div>
</div>
