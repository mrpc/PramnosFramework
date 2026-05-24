<?php
/**
 * Two-Factor Authentication setup page (Tailwind theme).
 *
 * Variables:
 *   $this->setupData — array {
 *       secret: string,
 *       qr_code_url: string,
 *       manual_entry_key: string,
 *       backup_codes: string[],
 *   }
 *   $this->user — User object
 */
?>
<div class="max-w-2xl mx-auto">

    <h2 class="text-2xl font-bold text-gray-900 mb-1">Set Up Two-Factor Authentication</h2>
    <p class="text-gray-500 text-sm mb-6">Follow the steps below to secure your account.</p>

    <?php if (!empty($_GET['error'])): ?>
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-red-800 text-sm">
            <?php
            $messages = [
                'invalid_code'  => 'The code was invalid or expired. Please try again.',
                'code_required' => 'Please enter the 6-digit code from your authenticator app.',
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? 'An error occurred.');
            ?>
        </div>
    <?php endif; ?>

    <!-- Step 1 -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm mb-4">
        <div class="px-5 py-3 border-b border-gray-100 font-medium text-gray-700 text-sm">Step 1 — Scan the QR code</div>
        <div class="p-5 text-center">
            <?php
            $qrSrc = $this->setupData['qr_code_data_uri'] ?? null;
            if ($qrSrc === null) {
                $qrSrc = htmlspecialchars($this->setupData['qr_code_url'] ?? '');
            }
            ?>
            <img src="<?php echo $qrSrc; ?>"
                 alt="QR Code" width="200" height="200"
                 class="inline-block border border-gray-200 rounded-lg p-2 mb-3">
            <p class="text-xs text-gray-500 mb-3">
                Open your authenticator app (Google Authenticator, Authy, etc.) and scan this QR code.
            </p>
            <details class="text-left mt-2">
                <summary class="text-xs text-indigo-600 cursor-pointer hover:underline">Can't scan? Enter manually</summary>
                <div class="mt-2 bg-gray-50 rounded-lg p-3">
                    <code class="text-sm font-mono break-all select-all block">
                        <?php echo htmlspecialchars($this->setupData['manual_entry_key']); ?>
                    </code>
                    <p class="text-xs text-gray-400 mt-1">Type: Time-based (TOTP) &nbsp;·&nbsp; Digits: 6 &nbsp;·&nbsp; Interval: 30s</p>
                </div>
            </details>
        </div>
    </div>

    <!-- Step 2: Backup codes -->
    <?php if (!empty($this->setupData['backup_codes'])): ?>
    <div class="bg-white border border-amber-200 rounded-xl shadow-sm mb-4">
        <div class="px-5 py-3 border-b border-amber-100 bg-amber-50 font-medium text-amber-800 text-sm rounded-t-xl">
            Step 2 — Save your backup codes
        </div>
        <div class="p-5">
            <p class="text-xs text-gray-500 mb-3">
                Store these codes in a safe place. Each is one-time-use.
                <strong class="text-gray-700">They will not be shown again.</strong>
            </p>
            <div class="grid grid-cols-2 gap-2 mb-3">
                <?php foreach ($this->setupData['backup_codes'] as $code): ?>
                    <code class="block text-center text-sm font-mono bg-gray-50 border border-gray-200 rounded py-1.5">
                        <?php echo htmlspecialchars($code); ?>
                    </code>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Step 3: Verify -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm">
        <div class="px-5 py-3 border-b border-gray-100 font-medium text-gray-700 text-sm">Step 3 — Verify</div>
        <div class="p-5">
            <p class="text-xs text-gray-500 mb-4">Enter the 6-digit code shown in your authenticator app.</p>
            <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/setup">
                <label class="block text-sm font-medium text-gray-700 mb-1" for="verify_code">Authenticator code</label>
                <input type="text" id="verify_code" name="verify_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       placeholder="000000" required autofocus autocomplete="one-time-code"
                       class="block w-48 rounded-md border border-gray-300 px-3 py-2 text-lg tracking-widest text-center mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div class="flex gap-3">
                    <button type="submit"
                            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 transition-colors">
                        Activate 2FA
                    </button>
                    <a href="<?php echo sURL; ?>TwoFactorAuth"
                       class="px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

</div>
