@php /**
 * Change Password page (Tailwind theme).
 *
 * Server-side rejections arrive as flash errors (Base::addError); client-side
 * policy hints come from pf-auth.js via [data-pf-password-error].
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'changepassword';
$inputCls = 'input w-full'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')
    <h2 class="text-2xl font-bold text-base-content mb-6">{{ t('Change Password') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">
            {!! $this->_printErrors() !!}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3">
            <div class="card bg-base-100 shadow-sm p-6 max-w-xl">
                <p class="text-sm text-base-content/70 mb-5">
                    {{ t('Choose a strong password: at least 8 characters, one digit, and one special character.') }}
                </p>
                <div data-pf-password-error role="alert" class="alert alert-error hidden mb-4"></div>
                <form method="post" action="{!! sURL . $routeBase !!}/changepassword" class="space-y-4" data-pf-password-policy>
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-base-content mb-1">{{ t('Current Password') }}</label>
                        <input type="password" id="current_password" name="current_password"
                               class="{!! $inputCls !!}" required autocomplete="current-password" enterkeyhint="go" autofocus>
                        {!! \Pramnos\Html\PasswordToggle::render('current_password', '', '') !!}
                    </div>
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-base-content mb-1">{{ t('New Password') }}</label>
                        <input type="password" id="new_password" name="new_password"
                               class="{!! $inputCls !!}" required autocomplete="new-password" minlength="8">
                        {!! \Pramnos\Html\PasswordToggle::render('new_password', '', '') !!}
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-base-content mb-1">{{ t('Confirm New Password') }}</label>
                        <input type="password" id="confirm_password" name="confirm_password" enterkeyhint="go"
                               class="{!! $inputCls !!}" required autocomplete="new-password">
                        {!! \Pramnos\Html\PasswordToggle::render('confirm_password', '', '') !!}
                    </div>
                    <button type="submit"
                            class="btn btn-primary">
                        {{ t('Update Password') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
<script src="{!! assetUrl('assets/js/pf-auth.js') !!}"></script>
