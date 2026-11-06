@php /**
 * Delete Account confirmation page (Bootstrap theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase    — Account controller route base
 *   $this->taskHandover — the scheduled AI tasks to hand over or delete (delete_account_tasks)
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Delete Account') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <div class="card border-danger" style="max-width:560px">
                <div class="card-header bg-danger text-white fw-semibold">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ t('Delete Account') }}
                </div>
                <div class="card-body">

                    <div role="status" class="alert alert-warning">
                        <strong>{{ t('Warning — this action is permanent.') }}</strong><br>
                        {{ t('All your personal data, authorized applications, activity history, and account information will be permanently deleted. This cannot be undone.') }}
                    </div>

                    <form method="post" action="{!! sURL . $routeBase !!}/deleteaccount">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        @include('delete_account_tasks')
                        <div class="mb-3">
                            <label for="del_password" class="form-label">{{ t('Current Password') }}</label>
                            <input type="password" id="del_password" name="password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            {!! \Pramnos\Html\PasswordToggle::render('del_password', '', '') !!}
                        </div>
                        <div class="mb-3">
                            <label for="del_confirm" class="form-label">
                                {!! sprintf(htmlspecialchars(t('Type %s to confirm'), ENT_QUOTES, 'UTF-8'), '<strong>DELETE</strong>') !!}
                            </label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="form-control" placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-danger w-100">
                            {{ t('Permanently Delete My Account') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
