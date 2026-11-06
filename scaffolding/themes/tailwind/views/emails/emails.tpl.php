{{-- Email log (Tailwind theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX) --}}
<div class="px-4 py-6">
    @php $this->activeNav = 'emails'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success mb-4">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-error mb-4">{{ $this->error }}</div>
    @endif
    <div class="flex justify-between items-center mb-4">
        <h2>{{ t('Email history') }}</h2>
        <a href="{!! adminUrl('MailTemplates') !!}" class="btn btn-ghost btn-sm"
           title="{{ t('The templates these are rendered from') }}">{{ t('Mail templates') }}</a>
    </div>
    @php /*
     * Scoped to one address, when the operator arrived from an account's own page.
     *
     * Said out loud, with a way out. A filtered list looks exactly like a short one, and a
     * short mail log reads as "nothing was ever sent" — which is the wrong conclusion to
     * hand somebody who came here to find out whether a code was delivered.
     */
    $scopedTo = (string) ($this->scopedTo ?? ''); @endphp
    @if ($scopedTo !== '')
        <div role="status" class="alert alert-info mb-4 flex flex-wrap items-center gap-3">
            <span>
                {{ t('Showing only mail sent to') }}
                <strong class="font-mono">{{ $scopedTo }}</strong>.
                {{ t('Mail to an address this account used before it was changed is not here.') }}
            </span>
            <a href="{{ (string) ($this->clearUrl ?? adminUrl('emails')) }}"
               class="btn btn-ghost btn-xs ms-auto">{{ t('Show all') }}</a>
        </div>
    @endif
    {!! $this->datatable->render() !!}
</div>
