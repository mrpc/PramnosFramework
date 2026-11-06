@php /**
 * User profile page (Tailwind theme) — editable.
 *
 * Matches the fields Account::profile() saves (firstname / lastname / email /
 * phone). Username and account dates are read-only. Uses the shared account
 * sidebar for consistent navigation.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::profile):
 *   $this->routeBase — Account controller route base
 *   $this->user      — User object; `avatarurl` and `photo` drive the picture card,
 *                      whose form posts to Account::profilephoto(); the picture is
 *                      cropped and sized in the browser first (Html\ImageUpload)
 */
$routeBase = $this->routeBase ?? 'Account';
$u         = $this->user;
$this->activeNav = 'profile';
$inputCls = 'input w-full'; @endphp
<div class="container mx-auto py-8 px-4">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <h2 class="text-2xl font-semibold">{{ t('My Profile') }}</h2>
        <a href="{!! sURL . $routeBase !!}" class="text-sm text-primary hover:underline">&larr; {{ t('Back to Dashboard') }}</a>
    </div>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success mb-4">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3 space-y-6">
            <div class="card bg-base-100 shadow-sm">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">{{ t('Profile Picture') }}</div>
                <div class="p-6 flex items-center gap-6 flex-wrap">
                    <img id="profile-photo-preview"{!! empty($u->avatarurl) ? ' hidden' : ' src="' . htmlspecialchars((string) $u->avatarurl) . '"' !!} alt="{{ t('Your profile picture') }}" width="96" height="96" class="rounded-full">
                    <form method="post" action="{!! sURL . $routeBase !!}/profilephoto" enctype="multipart/form-data" class="flex gap-2 flex-wrap items-center">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <label for="photo" class="sr-only">{{ t('Choose a picture') }}</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"{!! \Pramnos\User\ProfilePhoto::uploadAttributes() !!} class="file-input file-input-bordered">
                        <button type="submit" class="btn btn-primary">{{ t('Upload') }}</button>
                        @if (\Pramnos\User\ProfilePhoto::gravatarAllowed())
                            <button type="submit" name="gravatar" value="1" class="btn btn-outline">{{ t('Use my Gravatar') }}</button>
                        @endif
                        @if ((int) ($u->photo ?? 0) > 0)
                            <button type="submit" name="remove" value="1" class="btn btn-outline btn-error">{{ t('Remove') }}</button>
                        @endif
                        @if (\Pramnos\User\ProfilePhoto::gravatarAllowed())
                            <p class="text-sm text-base-content/70 w-full">{{ t('Use my Gravatar asks Gravatar for the picture of your email address, once, and keeps a copy here.') }}</p>
                        @endif
                    </form>
                    {!! \Pramnos\Html\ImageUpload::assets() !!}
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">{{ t('Personal Information') }}</div>
                <div class="p-6">
                    <form method="post" action="{!! sURL . $routeBase !!}/profile" class="space-y-4">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}

                        <div>
                            <label class="block text-sm font-medium text-base-content mb-1">{{ t('Username') }}</label>
                            <input type="text" class="{!! $inputCls !!} bg-base-200 text-base-content/70" value="{{ (string) ($u->username ?? '') }}" disabled>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="firstname" class="block text-sm font-medium text-base-content mb-1">{{ t('First Name') }}</label>
                                <input type="text" id="firstname" name="firstname" class="{!! $inputCls !!}" value="{{ (string) ($u->firstname ?? '') }}">
                            </div>
                            <div>
                                <label for="lastname" class="block text-sm font-medium text-base-content mb-1">{{ t('Last Name') }}</label>
                                <input type="text" id="lastname" name="lastname" class="{!! $inputCls !!}" value="{{ (string) ($u->lastname ?? '') }}">
                            </div>
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-base-content mb-1">{{ t('Email') }}</label>
                            <input type="email" id="email" name="email" class="{!! $inputCls !!}" value="{{ (string) ($u->email ?? '') }}" required>
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-base-content mb-1">{{ t('Phone') }}</label>
                            <input type="text" id="phone" name="phone" class="{!! $inputCls !!}" value="{{ (string) ($u->phone ?? '') }}">
                        </div>

                        <button type="submit" class="btn btn-primary">{{ t('Save Changes') }}</button>
                    </form>
                </div>
            </div>

            @if (!empty($u->regdate) || !empty($u->lastlogin))
            <div class="card bg-base-100 shadow-sm">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">{{ t('Account') }}</div>
                <div class="divide-y divide-base-300">
                    @if (!empty($u->regdate))
                    <div class="flex justify-between px-6 py-3 text-sm">
                        <span class="text-base-content/70">{{ t('Member Since') }}</span>
                        <span class="text-base-content">{{ localDate(is_numeric($u->regdate) ? (int) $u->regdate : (int) strtotime((string) $u->regdate)) }}</span>
                    </div>
                    @endif
                    @if (!empty($u->lastlogin))
                    <div class="flex justify-between px-6 py-3 text-sm">
                        <span class="text-base-content/70">{{ t('Last Login') }}</span>
                        <span class="text-base-content">{{ localDateTime(is_numeric($u->lastlogin) ? (int) $u->lastlogin : (int) strtotime((string) $u->lastlogin)) }}</span>
                    </div>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>

</div>
