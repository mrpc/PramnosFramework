@php /**
 * User profile page (Bootstrap theme) — editable.
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
$this->activeNav = 'profile'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('My Profile') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <div class="card mb-4">
                <div class="card-header fw-semibold">{{ t('Profile Picture') }}</div>
                <div class="card-body d-flex align-items-center gap-4 flex-wrap">
                    <img id="profile-photo-preview"{!! empty($u->avatarurl) ? ' hidden' : ' src="' . htmlspecialchars((string) $u->avatarurl) . '"' !!} alt="{{ t('Your profile picture') }}" width="96" height="96" class="rounded-circle">
                    <form method="post" action="{!! sURL . $routeBase !!}/profilephoto" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-center">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <label for="photo" class="visually-hidden">{{ t('Choose a picture') }}</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"{!! \Pramnos\User\ProfilePhoto::uploadAttributes() !!} class="form-control">
                        <button type="submit" class="btn btn-primary">{{ t('Upload') }}</button>
                        @if (\Pramnos\User\ProfilePhoto::gravatarAllowed())
                            <button type="submit" name="gravatar" value="1" class="btn btn-outline-secondary">{{ t('Use my Gravatar') }}</button>
                        @endif
                        @if ((int) ($u->photo ?? 0) > 0)
                            <button type="submit" name="remove" value="1" class="btn btn-outline-danger">{{ t('Remove') }}</button>
                        @endif
                        @if (\Pramnos\User\ProfilePhoto::gravatarAllowed())
                            <p class="form-text w-100 mt-0">{{ t('Use my Gravatar asks Gravatar for the picture of your email address, once, and keeps a copy here.') }}</p>
                        @endif
                    </form>
                    {!! \Pramnos\Html\ImageUpload::assets() !!}
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold">{{ t('Personal Information') }}</div>
                <div class="card-body">
                    <form method="post" action="{!! sURL . $routeBase !!}/profile">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}

                        <div class="mb-3">
                            <label class="form-label">{{ t('Username') }}</label>
                            <input type="text" class="form-control" value="{{ (string) ($u->username ?? '') }}" disabled>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="firstname" class="form-label">{{ t('First Name') }}</label>
                                <input type="text" id="firstname" name="firstname" class="form-control" value="{{ (string) ($u->firstname ?? '') }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="lastname" class="form-label">{{ t('Last Name') }}</label>
                                <input type="text" id="lastname" name="lastname" class="form-control" value="{{ (string) ($u->lastname ?? '') }}">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">{{ t('Email') }}</label>
                            <input type="email" id="email" name="email" class="form-control" value="{{ (string) ($u->email ?? '') }}" required>
                        </div>

                        <div class="mb-4">
                            <label for="phone" class="form-label">{{ t('Phone') }}</label>
                            <input type="text" id="phone" name="phone" class="form-control" value="{{ (string) ($u->phone ?? '') }}">
                        </div>

                        <button type="submit" class="btn btn-primary">{{ t('Save Changes') }}</button>
                    </form>
                </div>
            </div>

            @if (!empty($u->regdate) || !empty($u->lastlogin))
            <div class="card mt-3">
                <div class="card-header fw-semibold">{{ t('Account') }}</div>
                <ul class="list-group list-group-flush">
                    @if (!empty($u->regdate))
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">{{ t('Member Since') }}</span>
                        <span>{{ localDate(is_numeric($u->regdate) ? (int) $u->regdate : (int) strtotime((string) $u->regdate)) }}</span>
                    </li>
                    @endif
                    @if (!empty($u->lastlogin))
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">{{ t('Last Login') }}</span>
                        <span>{{ localDateTime(is_numeric($u->lastlogin) ? (int) $u->lastlogin : (int) strtotime((string) $u->lastlogin)) }}</span>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
        </div>
    </div>

</div>
