@php /**
 * Two-Factor Authentication setup page (Bootstrap theme).
 *
 * Rendered by the TwoFactorAuth controller; account sidebar/breadcrumb point at
 * the Account controller via accountBase.
 *
 * Variables:
 *   $this->setupData — array { secret, qr_code_url, qr_code_data_uri,
 *                              manual_entry_key }
 *   $this->user — User object
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor_setup'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-1">{{ t('Set Up Two-Factor Authentication') }}</h2>
    <p class="text-muted mb-4">{{ t('Follow the steps below to secure your account.') }}</p>

    @if (!empty($_GET['error']))
        <div role="alert" class="alert alert-danger">
            @php $messages = [
                'invalid_code'  => t('The code was invalid or expired. Please try again.'),
                'code_required' => t('Please enter the 6-digit code from your authenticator app.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.')); @endphp
        </div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <!-- Step 1: Scan QR code -->
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Step 1 — Scan the QR code') }}</div>
                <div class="card-body text-center">
                    @php $qrSrc = $this->setupData['qr_code_data_uri'] ?? null;
                    if ($qrSrc === null) {
                        $qrSrc = htmlspecialchars($this->setupData['qr_code_url'] ?? '');
                    } @endphp
                    <img src="{!! $qrSrc !!}"
                         alt="{{ t('QR Code') }}" width="200" height="200" class="mb-3 border rounded p-2">
                    <p class="text-muted small mb-2">
                        {{ t('Open your authenticator app (Google Authenticator, Authy, etc.) and scan this QR code.') }}
                    </p>
                    <details class="mt-2">
                        <summary class="btn btn-link btn-sm p-0">{{ t('Can\'t scan? Enter the key manually') }}</summary>
                        <div class="mt-2">
                            <code class="user-select-all fs-6 d-block py-2 bg-light rounded border px-3">
                                {{ $this->setupData['manual_entry_key'] }}
                            </code>
                            <p class="text-muted small mt-1">{{ t('Type: Time-based (TOTP)') }} &nbsp;|&nbsp; {{ t('Digits: 6') }} &nbsp;|&nbsp; {{ t('Interval: 30s') }}</p>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Step 2: backup codes, shown after verification. Listing them
                 here meant listing a set enrolment then replaced. -->
            <div class="card mb-4 border-warning">
                <div class="card-header bg-warning-subtle fw-semibold">{{ t('Step 2 — Your backup codes') }}</div>
                <div class="card-body">
                    <p class="small text-muted mb-0">
                        {!! sprintf(htmlspecialchars(t('You will be given ten one-time codes as soon as the code below is verified. %s — they are shown once.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('Save them then'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                    </p>
                </div>
            </div>

            <!-- Step 3: Verify -->
            <div class="card">
                <div class="card-header fw-semibold">{{ t('Step 3 — Verify') }}</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        {{ t('Enter the 6-digit code shown in your authenticator app to confirm setup.') }}
                    </p>
                    <form method="post" action="{!! sURL !!}TwoFactorAuth/setup">
                        <div class="mb-3">
                            <label for="verify_code" class="form-label">{{ t('Authenticator code') }}</label>
                            <input type="text" class="form-control form-control-lg"
                                   id="verify_code" name="verify_code"
                                   inputmode="numeric" pattern="\d{6}" maxlength="6"
                                   placeholder="000000" required autofocus autocomplete="one-time-code" enterkeyhint="go">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">{{ t('Activate 2FA') }}</button>
                            <a href="{!! sURL !!}TwoFactorAuth" class="btn btn-outline-secondary">{{ t('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
