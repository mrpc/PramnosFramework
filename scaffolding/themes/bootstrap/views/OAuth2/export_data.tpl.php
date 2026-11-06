@php /**
 * Export My Data — confirmation page (Bootstrap theme). GDPR Article 20.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 *   $this->exportSections  — string[] labels of what the export will contain
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'exportdata'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Export My Data') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <div class="card" style="max-width:600px">
                <div class="card-body">
                    <p class="text-muted mb-3">
                        {{ t('Download a copy of your personal data as a JSON file. The export includes:') }}
                    </p>
                    <ul class="mb-3">
                        @foreach (($this->exportSections ?? []) as $label)
                            <li>{{ $label }}</li>
                        @endforeach
                    </ul>
                    <p class="text-muted small mb-4">
                        {{ t('Secrets (password, two-factor secret, passkey keys, security tokens) are never included.') }}
                    </p>
                    <form method="post" action="{!! sURL . $routeBase !!}/exportdata">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <button type="submit" class="btn btn-primary">{{ t('Download my data') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
