{{-- The reports this person may open. Variables: reports (Reports::visibleTo()), me, csrfField, urls. --}}
<div class="pf-ui">
    <div class="pf-page-head">
        <h1 class="pf-page-title">{{ t('Reports') }}</h1>
        <div class="pf-page-actions"><a class="pf-btn pf-btn-primary" href="{{ $this->urls['edit'] }}">{{ t('New report') }}</a></div>
    </div>
    @if (empty($this->reports))
    <p class="pf-muted">{{ t('No reports yet.') }}</p>
    @else
    <table class="pf-table">
        <thead><tr><th>{{ t('Name') }}</th><th>{{ t('Kept by') }}</th><th>{{ t('Mailed') }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($this->reports as $entry)
        <tr>
            <td><a href="{{ $this->urls['show'] . '?report=' . rawurlencode($entry['id']) }}">{{ $entry['name'] }}</a></td>
            <td>{{ $entry['app'] ? t('The application') : ((int) $entry['row']['ownerid'] === $this->me ? t('You') : t('Shared with you')) }}</td>
            <td>{{ !$entry['app'] && $entry['row']['schedule'] !== '' ? t(['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'][$entry['row']['schedule']] ?? '') : '' }}</td>
            <td class="pf-num">
                <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?copy=' . rawurlencode($entry['id']) }}">{{ t('Copy') }}</a>
                @if (!$entry['app'] && (int) $entry['row']['ownerid'] === $this->me)
                <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?report=' . rawurlencode($entry['id']) }}">{{ t('Edit') }}</a>
                <form method="post" action="{{ $this->urls['delete'] }}" style="display:inline" data-confirm="{{ t('Delete this report?') }}">{!! $this->csrfField !!}
                    <input type="hidden" name="reportid" value="{{ $entry['id'] }}">
                    <button type="submit" class="pf-btn pf-btn-danger pf-btn-sm">{{ t('Delete') }}</button>
                </form>
                @endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
