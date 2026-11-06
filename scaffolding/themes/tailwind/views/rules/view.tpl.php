@php /**
 * A rule: its status as a coloured pill and the states it can be put in as one control, its steps
 * with how many went through each, the tools to try it, its versions and its log. The same markup
 * in every theme.
 *
 * Variables:
 *   $this->rule        — Rules::get()
 *   $this->canvas      — RuleCanvas::render() with the counts
 *   $this->versions    — Rules::versions()
 *   $this->log         — the newest rule_log rows
 *   $this->person      — the account asked about, or 0
 *   $this->why         — Tester::why() for it, or null
 *   $this->simulated   — Tester::simulate() with its days, or null
 *   $this->diff        — Tester::diff() with from and to, or null
 *   $this->actionSteps — the ids of the action steps, for a test send
 */
use Pramnos\Html\Icon;

$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule   = $this->rule;
$id     = (int) $rule['id'];
$labels = \Pramnos\Html\RuleCanvas::stepLabels($rule['definition']['steps'] ?? []);
$tones  = ['draft' => 'pf-tone-muted', 'shadow' => 'pf-tone-info', 'active' => 'pf-tone-ok', 'paused' => 'pf-tone-warn'];
$state  = static fn (string $to, string $label, string $icon) => '<form method="post" action="' . $e(adminUrl('Rules/status/') . $id) . '">' . \Pramnos\Http\Session::getInstance()->getTokenField()
    . '<input type="hidden" name="status" value="' . $e($to) . '"><button type="submit" aria-pressed="' . ($rule['status'] === $to ? 'true' : 'false') . '"'
    . ($rule['status'] === $to ? ' disabled' : '') . '>' . Icon::svg($icon, '') . $e($label) . '</button></form>';
$outcomeTones = ['entered' => 'pf-tone-accent', 'sent' => 'pf-tone-ok', 'done' => 'pf-tone-ok', 'goal' => 'pf-tone-ok', 'error' => 'pf-tone-bad',
    'skipped' => 'pf-tone-warn', 'waiting' => 'pf-tone-warn', 'shadow' => 'pf-tone-info', 'branch' => 'pf-tone-info']; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'rules_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $rule['name'] }}</h1>
            <p class="pf-page-sub">
                <span class="pf-badge pf-status {!! $tones[$rule['status']] ?? 'pf-tone-muted' !!}" data-field="status">{{ t($rule['status']) }}</span>
                <span class="pf-muted">{{ t('version %s', (string) $rule['version']) }}</span>
            </p>
        </div>
        <div class="pf-page-actions" data-field="actions">
            @if ($rule['status'] === 'draft' || $rule['status'] === 'paused')
                <form method="post" action="{{ adminUrl('Rules/delete/') . $id }}" class="pf-inline-form" data-confirm="{{ t('Delete this rule, its versions and its log?') }}">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger">{{ t('Delete') }}</button></form>
            @endif
            <span class="pf-segmented" role="group" aria-label="{{ t('Rule status') }}">
                {!! $state('shadow', t('Run in shadow'), 'shadow') !!}
                {!! $state('active', t('Switch on'), 'play') !!}
                {!! $state('paused', t('Pause'), 'pause') !!}
            </span>
            <a href="{{ adminUrl('Rules/edit/') . $id }}" class="pf-btn pf-btn-primary">{!! Icon::svg('edit', '') !!}{{ t('Edit') }}</a>
        </div>
    </header>

    <div class="pf-split">
        <div class="pf-card"><div class="pf-card-body pf-card-flush">{!! $this->canvas !!}</div></div>
        <div class="pf-stack">
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title">{{ t('Why would this person come in?') }}</h2>
                <form method="get" action="{{ adminUrl('Rules/view/') . $id }}" class="pf-rule-sentence">
                    <input type="number" min="1" name="person" class="pf-input pf-input-id" placeholder="{{ t('Account id') }}" value="{!! $this->person > 0 ? (int) $this->person : '' !!}" aria-label="{{ t('Account id') }}">
                    <button type="submit" class="pf-btn">{{ t('Explain') }}</button>
                </form>
                @if ($this->why !== null)
                    <p class="pf-why-verdict" data-field="enters"><span class="pf-badge {!! $this->why['enters'] ? 'pf-tone-ok' : 'pf-tone-bad' !!}">{{ $this->why['enters'] ? t('Would come in.') : t('Would not come in: %s.', implode(', ', array_map('t', $this->why['reasons']))) }}</span></p>
                    <ul class="pf-why">
                    @php foreach ($this->why['who']['conditions'] as $c): if (isset($c['conditions'])) { continue; } $said = \Pramnos\Audience\Explained::condition($c); @endphp
                        <li><span class="pf-why-mark {!! $c['ok'] ? 'pf-up' : 'pf-down' !!}">{!! $c['ok'] ? '✓' : '✗' !!}</span><span>{{ $said['what'] . ' ' . $said['how'] . ($said['value'] !== null ? ' ' . $said['value'] : '') }} <span class="pf-muted">· {{ t('has: %s', $said['actual']) }}</span></span></li>
                    @endforeach
                    @foreach ($this->why['steps'] as $step => $outlook) @if (isset($outlook['consent']))
                        <li><span class="pf-why-mark {!! $outlook['consent'] ? 'pf-up' : 'pf-down' !!}">{!! $outlook['consent'] ? '✓' : '✗' !!}</span><span>{{ t('%s: in the consent list', $step) }}</span></li>
                    @endif @endforeach
                    </ul>
                @endif
            </div></div>
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title">{{ t('Had it been on') }}</h2>
                <span class="pf-segmented">
                    <a href="{{ adminUrl('Rules/view/') . $id . '?simulate=7' }}"{!! ($this->simulated['days'] ?? 0) === 7 ? ' aria-current="true"' : '' !!}>{{ t('Last 7 days') }}</a>
                    <a href="{{ adminUrl('Rules/view/') . $id . '?simulate=30' }}"{!! ($this->simulated['days'] ?? 0) === 30 ? ' aria-current="true"' : '' !!}>{{ t('Last 30 days') }}</a>
                </span>
                @if ($this->simulated !== null)
                    <p class="pf-mt" data-field="simulated"><span class="pf-stat-value">{{ number_format($this->simulated['entered']) }}</span> <span class="pf-muted">{{ t('would have come in, in %s days', (string) $this->simulated['days']) }}</span></p>
                    @if ($this->simulated['messages'] !== [])<p>{{ implode(' · ', array_map(static fn ($c, $n): string => $c . ' ' . number_format($n), array_keys($this->simulated['messages']), $this->simulated['messages'])) }}</p>@endif
                    <p class="pf-hint">{{ t('Counted from %s, with who as it is now.', t($this->simulated['basis'])) }}</p>
                @endif
            </div></div>
            @if ($this->actionSteps !== [])
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title">{{ t('Send a test to me') }}</h2>
                <form method="post" action="{{ adminUrl('Rules/testsend/') . $id }}" class="pf-rule-sentence">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <select name="step" class="pf-select" aria-label="{{ t('Step') }}">@foreach ($this->actionSteps as $step)<option value="{{ $step }}">{{ $labels[$step] ?? $step }}</option>@endforeach</select>
                    <input type="number" min="1" name="person" required class="pf-input pf-input-id" placeholder="{{ t('with the details of') }}" aria-label="{{ t('Account id') }}">
                    <button type="submit" class="pf-btn">{!! Icon::svg('send', '') !!}{{ t('Send') }}</button>
                </form>
            </div></div>
            @endif
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title">{{ t('Versions') }}</h2>
                <ul class="pf-why">
                @foreach ($this->versions as $i => $v) @php $older = $this->versions[$i + 1]['version'] ?? null; @endphp
                    <li><span class="pf-badge {!! (int) $v['version'] === (int) $rule['version'] ? 'pf-tone-accent' : 'pf-tone-muted' !!}">v{!! (int) $v['version'] !!}</span>
                        <span class="pf-muted">{{ date('Y-m-d H:i', $v['at']) }}</span>
                        @if ($older !== null)<a href="{{ adminUrl('Rules/view/') . $id . '?diff=' . $older . ',' . $v['version'] }}">{{ t('compare with v%s', (string) $older) }}</a>@endif</li>
                @endforeach
                </ul>
                @if ($this->diff !== null)
                    <p class="pf-mt" data-field="diff">{{ t('v%s → v%s: %s now instead of %s; +%s in, −%s out.', (string) $this->diff['from'], (string) $this->diff['to'], number_format($this->diff['after']), number_format($this->diff['before']), number_format($this->diff['in']), number_format($this->diff['out'])) }}</p>
                @endif
            </div></div>
        </div>
    </div>

    <div class="pf-card pf-mt">
        <div class="pf-card-head"><h2 class="pf-section-title">{{ t('What it did') }}</h2></div>
        <div class="pf-card-flush">
            @if (empty($this->log))
                <div class="pf-empty"><p class="pf-empty-text">{{ t('Nothing yet.') }}</p></div>
            @else
            <table class="pf-table">
                <thead><tr><th>{{ t('When') }}</th><th>{{ t('Person') }}</th><th>{{ t('Step') }}</th><th>{{ t('Outcome') }}</th><th>{{ t('Detail') }}</th></tr></thead>
                <tbody>
                @foreach ($this->log as $row)
                    <tr>
                        <td class="pf-muted">{{ date('Y-m-d H:i', (int) $row['at']) }}</td>
                        <td><a href="{{ \Pramnos\Html\AudienceBuilder::personUrl((int) $row['personid']) }}">#{!! (int) $row['personid'] !!}</a></td>
                        <td>{{ $labels[(string) ($row['step'] ?? '')] ?? (string) ($row['step'] ?? '') }}</td>
                        <td><span class="pf-badge {!! $outcomeTones[(string) $row['outcome']] ?? 'pf-tone-muted' !!}">{{ t((string) $row['outcome']) }}</span></td>
                        <td class="pf-muted">{{ mb_strimwidth((string) ($row['detail'] ?? ''), 0, 140, '…') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</div>
