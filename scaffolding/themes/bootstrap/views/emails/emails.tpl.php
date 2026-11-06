@php /**
 * Email history (Bootstrap theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX, fed by Emails/data)
 *   $this->scopedTo  — the one address the list is narrowed to, when arrived from an account
 *   $this->clearUrl  — the list without that narrowing
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$scopedTo = (string) ($this->scopedTo ?? ''); @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'emails'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>{{ t('Email history') }}</h2>
        <a href="{!! adminUrl('MailTemplates') !!}" class="btn btn-sm btn-outline-secondary"
           title="{{ t('The templates these are rendered from') }}">{{ t('Mail templates') }}</a>
    </div>
    @php // A filtered list looks like a short one: say it is filtered, and give the way out.; @endphp
    @if ($scopedTo !== '')
        <div role="status" class="alert alert-info">
            {{ t('Showing only mail sent to') }}
            <strong>{{ $scopedTo }}</strong>.
            {{ t('Mail to an address this account used before it was changed is not here.') }}
            <a href="{{ $this->clearUrl ?? adminUrl('emails') }}" class="btn btn-sm btn-outline-secondary ms-2">{{ t('Show all') }}</a>
        </div>
    @endif
    @php // In a card and in this theme's own table styling. The controller turns Bootstrap's off for
    // every theme; this view is the one that knows it is Bootstrap's.
    $this->datatable->jui = false;
    $this->datatable->bootstrap = true; @endphp
    <div class="card">
        <div class="card-body">
            {!! $this->datatable->render() !!}
        </div>
    </div>
</div>
