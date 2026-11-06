{{-- Generic login-flow message page (Bootstrap theme).

Variables:
  $this->title   — Optional page title override
  $this->message — Optional plain-text message body --}}
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')
                    <h1 class="h4 mb-3">{{ $this->title ?? t('Notice') }}</h1>

                    @if ($this->hasErrors())
                        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
                    @endif
                    @if ($this->hasMessages())
                        <div role="status" class="alert alert-info">{!! $this->_printMessages() !!}</div>
                    @endif

                    @if (!empty($this->message))
                        <p>{{ $this->message }}</p>
                    @else
                        <p class="text-muted">{{ t('Your request has been submitted. If an account exists, you will receive instructions by email.') }}</p>
                    @endif

                    <div class="text-center mt-4">
                        <a href="{!! sURL !!}login" class="btn btn-primary">{{ t('Back to Login') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
