{{-- Device authorization success page (Bootstrap theme).

Variables:
  $this->deviceAuth — array{user_code, scope} — approved device auth record --}}
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm text-center">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')
                    <div class="fs-1 text-success mb-2">&#10003;</div>
                    <h1 class="h4 mb-3">{{ t('Device Authorized!') }}</h1>
                    <p class="text-muted mb-3">{{ t('Your device has been successfully authorized.') }}</p>
                    <div class="bg-light rounded p-3 text-start small mb-3">
                        <div><strong>{{ t('Device Code:') }}</strong> {{ $this->deviceAuth['user_code'] ?? '' }}</div>
                        <div><strong>{{ t('Scopes:') }}</strong> {{ $this->deviceAuth['scope'] ?? '' }}</div>
                        <div><strong>{{ t('Authorized:') }}</strong> {!! localDateTime(time()) !!}</div>
                    </div>
                    <p class="text-muted small">{{ t('You may now close this window and return to your device.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
