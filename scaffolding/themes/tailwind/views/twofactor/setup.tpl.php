@php /**
 * Two-Factor Authentication setup page (Tailwind theme).
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
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')
    <h2 class="text-2xl font-bold text-base-content mb-1">{{ t('Set Up Two-Factor Authentication') }}</h2>
    <p class="text-base-content/70 text-sm mb-6">{{ t('Follow the steps below to secure your account.') }}</p>

    @if (!empty($_GET['error']))
        <div role="alert" class="alert alert-error mb-4">
            @php $messages = [
                'invalid_code'  => t('The code was invalid or expired. Please try again.'),
                'code_required' => t('Please enter the 6-digit code from your authenticator app.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.')); @endphp
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3 space-y-4">
            <!-- Step 1 -->
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 border-b border-base-300 font-medium text-base-content text-sm">{{ t('Step 1 — Scan the QR code') }}</div>
                <div class="p-5 text-center">
                    @php $qrSrc = $this->setupData['qr_code_data_uri'] ?? null;
                    if ($qrSrc === null) {
                        $qrSrc = htmlspecialchars($this->setupData['qr_code_url'] ?? '');
                    } @endphp
                    <img src="{!! $qrSrc !!}"
                         alt="{{ t('QR Code') }}" width="200" height="200"
                         class="inline-block border border-base-300 rounded-lg p-2 mb-3">
                    <p class="text-xs text-base-content/70 mb-3">
                        {{ t('Open your authenticator app (Google Authenticator, Authy, etc.) and scan this QR code.') }}
                    </p>
                    <details class="text-left mt-2">
                        <summary class="text-xs text-primary cursor-pointer hover:underline">{{ t("Can't scan? Enter manually") }}</summary>
                        <div class="mt-2 bg-base-200 rounded-lg p-3">
                            <code class="text-sm font-mono break-all select-all block">
                                {{ $this->setupData['manual_entry_key'] }}
                            </code>
                            <p class="text-xs text-base-content/60 mt-1">{{ t('Type: Time-based (TOTP)') }} &nbsp;·&nbsp; {{ t('Digits: 6') }} &nbsp;·&nbsp; {{ t('Interval: 30s') }}</p>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Step 2: Backup codes — shown after the code below is verified.
                 They used to be listed here, before enrolment, and they were a
                 different set from the one enrolment went on to store: whoever
                 wrote these down had ten codes that could never work. -->
            <div class="card bg-base-100 shadow-xs">
                <div role="status" class="alert alert-warning border-b">
                    {{ t('Step 2 — Your backup codes') }}
                </div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70">
                        {!! sprintf(htmlspecialchars(t('You will be given ten one-time codes as soon as the code below is verified. %s — they are shown once.'), ENT_QUOTES, 'UTF-8'), '<strong class="text-base-content">' . htmlspecialchars(t('Save them then'), ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                    </p>
                </div>
            </div>

            <!-- Step 3: Verify -->
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 border-b border-base-300 font-medium text-base-content text-sm">{{ t('Step 3 — Verify') }}</div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-4">{{ t('Enter the 6-digit code shown in your authenticator app.') }}</p>
                    <form method="post" action="{!! sURL !!}TwoFactorAuth/setup">
                        <label class="block text-sm font-medium text-base-content mb-1" for="verify_code">{{ t('Authenticator code') }}</label>
                        <input type="text" id="verify_code" name="verify_code"
                               inputmode="numeric" pattern="\d{6}" maxlength="6"
                               placeholder="000000" required autofocus autocomplete="one-time-code" enterkeyhint="go"
                               class="input input-lg block w-48 tracking-widest text-center mb-4">
                        <div class="flex gap-3">
                            <button type="submit"
                                    class="btn btn-primary btn-sm">
                                {{ t('Activate 2FA') }}
                            </button>
                            <a href="{!! sURL !!}TwoFactorAuth"
                               class="btn btn-outline btn-sm">
                                {{ t('Cancel') }}
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
