@php /**
 * One language-model call in full (plain-CSS theme).
 *
 * Variables:
 *   $this->call — the row: provider, model, purpose, system_prompt, messages (decoded), reply,
 *                 usage (decoded), tokens, cost, http_status, finish_reason, error, duration_ms
 *
 * The answer to "why did that cost that", and to "why did it fail": everything that was sent
 * and everything that came back.
 */
$c    = ['card' => 'pf-card', 'muted' => 'pf-muted', 'ok' => 'pf-ok', 'bad' => 'pf-bad', 'btn' => 'btn', 'btnOutline' => 'btn', 'input' => '', 'table' => 'pf-table'];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$row  = is_array($this->call ?? null) ? $this->call : [];
$json = static fn ($v): string => htmlspecialchars((string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'ai_call'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <h1>AI call #{!! (int) ($row['call_id'] ?? 0) !!}</h1>
    <p><a href="{{ adminUrl('Ai/settings') }}">← All calls</a></p>

    <table class="{!! $c['table'] !!}">
        <tbody>
            <tr><th>When</th><td>{{ $row['created_at'] ?? '' }}</td></tr>
            <tr><th>Purpose</th><td>{{ $row['purpose'] ?? '' }}</td></tr>
            <tr><th>Provider and model</th><td>{{ ($row['provider'] ?? '') . ' · ' . ($row['model'] ?? '') }}</td></tr>
            <tr><th>Status</th><td>{{ ($row['http_status'] ?? '—') . ' ' . ($row['finish_reason'] ?? '') }}</td></tr>
            @if (($row['error'] ?? null) !== null)
                <tr><th>Error</th><td class="{!! $c['bad'] !!}">{{ $row['error'] }}</td></tr>
            @endif
            <tr><th>{{ t('Tokens') }}</th><td>{{ ($row['tokens'] ?? null) === null ? '—' : \Pramnos\General\NumberFormat::number((float) $row['tokens']) }}</td></tr>
            <tr><th>{{ t('Cost') }}</th><td>{{ ($row['cost'] ?? null) === null ? t('not priced') : \Pramnos\General\NumberFormat::smallMoney((float) $row['cost'], 'USD') }}</td></tr>
            <tr><th>Took</th><td>{!! (int) ($row['duration_ms'] ?? 0) !!} ms</td></tr>
        </tbody>
    </table>

    <h2>System prompt</h2>
    <pre style="white-space:pre-wrap">{{ $row['system_prompt'] ?? '' }}</pre>
    <h2>Messages</h2>
    <pre style="white-space:pre-wrap">{!! $json($row['messages'] ?? []) !!}</pre>
    <h2>Reply</h2>
    <pre style="white-space:pre-wrap">{{ $row['reply'] ?? '' }}</pre>
    <h2>Usage</h2>
    <pre>{!! $json($row['usage'] ?? null) !!}</pre>
</div>
