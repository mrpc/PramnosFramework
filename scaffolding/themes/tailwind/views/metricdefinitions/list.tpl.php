{{-- Every metric defined on the screen. Variables: rows (NoCodeMetrics::all()), csrfField, urls. --}}
<div class="pf-ui">
    <div class="pf-page-head">
        <h1 class="pf-page-title">{{ t('Metrics defined on the screen') }}</h1>
        <div class="pf-page-actions"><a class="pf-btn pf-btn-primary" href="{{ $this->urls['edit'] }}">{{ t('New metric') }}</a></div>
    </div>
    @if (empty($this->rows))
    <p class="pf-muted">{{ t('No metrics defined on the screen yet.') }}</p>
    @else
    <table class="pf-table">
        <thead><tr><th>{{ t('Name') }}</th><th>{{ t('Table') }}</th><th>{{ t('Counts') }}</th><th>{{ t('On') }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($this->rows as $row)
        <tr>
            <td><a href="{{ $this->urls['edit'] . '?id=' . $row['declaration_id'] }}">{{ $row['label'] }}</a> <code class="pf-muted">{{ $row['name'] }}</code></td>
            <td><code>{{ $row['source_table'] }}</code></td>
            <td>{{ $row['fn'] }}@if ($row['value_column'] !== null)({{ $row['value_column'] }})@endif</td>
            <td>
                <form method="post" action="{{ $this->urls['active'] }}" style="display:inline">{!! $this->csrfField !!}
                    <input type="hidden" name="id" value="{{ $row['declaration_id'] }}">
                    <input type="hidden" name="on" value="{{ $row['active'] ? '0' : '1' }}">
                    <button type="submit" class="pf-btn pf-btn-ghost pf-btn-sm">{{ $row['active'] ? t('Yes — turn off') : t('No — turn on') }}</button>
                </form>
            </td>
            <td class="pf-num">
                <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?id=' . $row['declaration_id'] }}">{{ t('Edit') }}</a>
                <form method="post" action="{{ $this->urls['delete'] }}" style="display:inline" data-confirm="{{ t('Delete this metric?') }}">{!! $this->csrfField !!}
                    <input type="hidden" name="id" value="{{ $row['declaration_id'] }}">
                    <button type="submit" class="pf-btn pf-btn-danger pf-btn-sm">{{ t('Delete') }}</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
