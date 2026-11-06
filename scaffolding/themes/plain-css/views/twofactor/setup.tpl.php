@php /**
 * Two-Factor Authentication setup page (plain-CSS theme).
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
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Set Up Two-Factor Authentication') }}</h2>

    @if (!empty($_GET['error']))
        <div role="alert" class="alert alert-error">
            @php $messages = [
                'invalid_code'  => t('The code was invalid or expired. Please try again.'),
                'code_required' => t('Please enter the 6-digit code from your authenticator app.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.'), ENT_QUOTES, 'UTF-8'); @endphp
        </div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <div class="card">
                <div class="card-header"><strong>{{ t('Step 1 — Scan the QR code') }}</strong></div>
                <div class="card-body" style="text-align:center">
                    @php $qrSrc = $this->setupData['qr_code_data_uri'] ?? null;
                    if ($qrSrc === null) {
                        // Fallback: data URI not available (library missing) — use external API.
                        $qrSrc = htmlspecialchars($this->setupData['qr_code_url'] ?? '');
                    } @endphp
                    <img src="{!! $qrSrc !!}"
                         alt="{{ t('QR Code') }}" width="200" height="200" style="border:1px solid #ddd;padding:8px;border-radius:4px">
                    <p style="font-size:.9em;color:#666;margin-top:8px">
                        {{ t('Open your authenticator app (Google Authenticator, Authy, etc.) and scan this QR code.') }}
                    </p>
                    <details style="text-align:left;margin-top:8px">
                        <summary style="cursor:pointer;font-size:.85em;color:#555">{{ t('Can\'t scan? Enter the key manually') }}</summary>
                        <div style="margin-top:8px;background:#f5f5f5;border-radius:4px;padding:10px">
                            <code style="word-break:break-all">{{ $this->setupData['manual_entry_key'] }}</code>
                            <p style="font-size:.8em;color:#888;margin:4px 0 0">{{ t('Type: Time-based (TOTP)') }} &nbsp;|&nbsp; {{ t('Digits: 6') }} &nbsp;|&nbsp; {{ t('Interval: 30s') }}</p>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Backup codes are shown after verification, not before: the set
                 listed here used to be a different set from the one enrolment
                 stored. -->
            <div class="card" style="border-color:#f0ad4e;margin-top:16px">
                <div class="card-header" style="background:#fcf8e3"><strong>{{ t('Step 2 — Your backup codes') }}</strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666">
                        {{ t('You will be given ten one-time codes as soon as the code below is verified.') }}
                        {!! sprintf(htmlspecialchars(t('%s — they are shown once.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('Save them then'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                    </p>
                </div>
            </div>

            <div class="card" style="margin-top:16px">
                <div class="card-header"><strong>{{ t('Step 3 — Verify') }}</strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666;margin-bottom:12px">
                        {{ t('Enter the 6-digit code shown in your authenticator app to confirm setup.') }}
                    </p>
                    <form method="post" action="{!! sURL !!}TwoFactorAuth/setup">
                        <div class="form-group">
                            <label for="verify_code">{{ t('Authenticator code') }}</label>
                            <input type="text" id="verify_code" name="verify_code"
                                   inputmode="numeric" pattern="\d{6}" maxlength="6"
                                   placeholder="000000" required autofocus autocomplete="one-time-code" enterkeyhint="go"
                                   class="form-control" style="max-width:160px;font-size:1.4em;letter-spacing:.15em;text-align:center">
                        </div>
                        <button type="submit" class="btn btn-primary">{{ t('Activate 2FA') }}</button>
                        <a href="{!! sURL !!}TwoFactorAuth" class="btn" style="margin-left:8px">{{ t('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
