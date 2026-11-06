{{-- Device authorization denied page (Tailwind theme). --}}
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm text-center">
        @include('../partials/auth_brand')
        <div class="text-5xl text-error mb-4">&#10060;</div>
        <h1 class="text-xl font-semibold mb-3">{{ t('Authorization Denied') }}</h1>
        <p class="text-base-content/80">{{ t('The device authorization request was denied. You may now close this window.') }}</p>
    </div>
</div>
