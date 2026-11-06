@php /**
 * Two-Factor Authentication backup codes page (Tailwind theme).
 *
 * Rendered by the TwoFactorAuth controller; account sidebar/breadcrumb point at
 * the Account controller via accountBase.
 *
 * Variables:
 *   $this->user             — User object
 *   $this->remainingCodes   — int
 *   $this->newBackupCodes   — string[] (set after enrolment and after regeneration)
 *   $this->success          — string|null
 *   $this->error            — string|null
 *   $this->setupComplete    — bool
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor_backup'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')
    <h2 class="text-2xl font-bold text-base-content mb-6">{{ t('Backup Codes') }}</h2>

    @if (!empty($this->success))
        <div role="status" class="alert alert-success mb-4">
            {{ $this->success }}
        </div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-error mb-4">
            {{ $this->error }}
        </div>
    @endif
    @if (!empty($this->setupComplete))
        <div role="status" class="alert alert-info mb-4 block">
            <strong>{{ t('Setup complete!') }}</strong> {{ t('Save your backup codes before leaving this page.') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3 space-y-4">
            @if (!empty($this->newBackupCodes))
            <div class="card bg-base-100 shadow-xs">
                <div role="status" class="alert alert-warning border-b">
                    {{ t('New Backup Codes') }}
                </div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-3">
                        <strong class="text-base-content">{{ t('Save these codes now.') }}</strong>
                        {{ t('They replace your previous codes and will not be shown again.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($this->newBackupCodes as $code)
                            <code class="block text-center text-sm font-mono bg-base-200 border border-base-300 rounded-sm py-1.5">
                                {{ $code }}
                            </code>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Status card -->
            <div class="card bg-base-100 border border-base-300 shadow-xs p-5">
                <h3 class="font-medium text-base-content mb-2">{{ t('Remaining codes') }}</h3>
                <p class="text-sm text-base-content/80 mb-3">
                    {!! sprintf(htmlspecialchars($this->remainingCodes === 1 ? t('You have %s backup code remaining.') : t('You have %s backup codes remaining.'), ENT_QUOTES, 'UTF-8'), '<strong class="' . ($this->remainingCodes <= 2 ? 'text-error' : 'text-success') . ' text-lg">' . (int) $this->remainingCodes . '</strong>') !!}
                </p>
                @if ($this->remainingCodes <= 2)
                    <p class="text-xs text-error">{{ t('Running low — consider regenerating your codes.') }}</p>
                @endif
                <p class="text-xs text-base-content/60 mt-2">
                    {{ t("Use a backup code instead of your authenticator app when you don't have your device. Each code can only be used once.") }}
                </p>
            </div>

            <!-- Regenerate -->
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 border-b border-base-300 font-medium text-base-content text-sm">{{ t('Regenerate Backup Codes') }}</div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-4">
                        {{ t('Generating new codes will invalidate all existing ones. Enter your account password to confirm.') }}
                    </p>
                    <form method="post" action="{!! sURL !!}TwoFactorAuth/backup">
                        <label class="block text-sm font-medium text-base-content mb-1" for="regenerate_password">{{ t('Password') }}</label>
                        <input type="password" id="regenerate_password" name="regenerate_password"
                               required autocomplete="current-password" enterkeyhint="go"
                               class="input input-sm block w-full mb-4">
                        {!! \Pramnos\Html\PasswordToggle::render('regenerate_password', '', '') !!}
                        <button type="submit"
                                class="btn btn-soft btn-warning btn-sm">
                            {{ t('Regenerate Codes') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
