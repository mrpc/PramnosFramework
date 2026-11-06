@php /**
 * Export My Data — confirmation page (Tailwind theme). GDPR Article 20.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 *   $this->exportSections  — string[] labels of what the export will contain
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'exportdata'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')
    <h2 class="text-2xl font-bold text-base-content mb-6">{{ t('Export My Data') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">
            {!! $this->_printErrors() !!}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3">
            <div class="card bg-base-100 shadow-sm p-6 max-w-xl">
                <p class="text-sm text-base-content/70 mb-3">
                    {{ t('Download a copy of your personal data as a JSON file. The export includes:') }}
                </p>
                <ul class="list-disc list-inside text-sm text-base-content space-y-1 mb-3">
                    @foreach (($this->exportSections ?? []) as $label)
                        <li>{{ $label }}</li>
                    @endforeach
                </ul>
                <p class="text-xs text-base-content/60 mb-5">
                    {{ t('Secrets (password, two-factor secret, passkey keys, security tokens) are never included.') }}
                </p>
                <form method="post" action="{!! sURL . $routeBase !!}/exportdata">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <button type="submit"
                            class="btn btn-primary">
                        {{ t('Download my data') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
