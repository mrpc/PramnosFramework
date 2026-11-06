{{-- Generic login-flow message page (Tailwind theme).

Variables:
  $this->title   — Optional page title override
  $this->message — Optional plain-text message body --}}
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm">
        @include('../partials/auth_brand')
        <h1 class="text-2xl font-semibold mb-4">{{ $this->title ?? t('Notice') }}</h1>

        @if ($this->hasErrors())
            <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
        @endif
        @if ($this->hasMessages())
            <div role="status" class="alert alert-info mb-4">{!! $this->_printMessages() !!}</div>
        @endif

        @if (!empty($this->message))
            <p class="text-base-content">{{ $this->message }}</p>
        @else
            <p class="text-base-content/70">{{ t('Your request has been submitted. If an account exists, you will receive instructions by email.') }}</p>
        @endif

        <div class="text-center mt-6">
            <a href="{!! sURL !!}login" class="btn btn-primary">{{ t('Back to Login') }}</a>
        </div>
    </div>
</div>
