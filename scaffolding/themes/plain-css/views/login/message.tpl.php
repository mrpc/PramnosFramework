{{-- Generic login-flow message page (plain-CSS theme).

Variables:
  $this->title   — Optional page title override
  $this->message — Optional plain-text message body --}}
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        @include('../partials/auth_brand')
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem">{{ $this->title ?? t('Notice') }}</h2></div>
        <div class="card-body" style="padding:24px">

            @if ($this->hasErrors())
                <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
            @endif
            @if ($this->hasMessages())
                <div role="status" class="alert alert-info">{!! $this->_printMessages() !!}</div>
            @endif

            @if (!empty($this->message))
                <p>{{ $this->message }}</p>
            @else
                <p style="color:#555">{{ t('Your request has been submitted. If an account exists, you will receive instructions by email.') }}</p>
            @endif

            <div style="text-align:center;margin-top:24px">
                <a href="{!! sURL !!}login" class="btn">{{ t('Back to Login') }}</a>
            </div>
        </div>
    </div>
</div>
