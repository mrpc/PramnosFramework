@php /**
 * An A/B test's results: per variant, the people, how many reached the goal, the rate, how sure
 * the difference from the first variant is, and how many more it needs; and the controls to start,
 * stop and make a winner. The same markup in every theme.
 *
 * Variables:
 *   $this->test    — Experiments::get()
 *   $this->results — Experiments::results()
 *   $this->segment — the name of the segment it is limited to, or null
 */
use Pramnos\Html\Icon;

$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$test    = $this->test;
$id      = (int) $test['id'];
$post    = static fn (string $action, string $label, string $class, array $fields = [], string $icon = '') => '<form method="post" class="pf-inline-form" action="' . $e(adminUrl('Experiments/' . $action . '/') . $id) . '">' . \Pramnos\Http\Session::getInstance()->getTokenField()
    . implode('', array_map(static fn ($k, $v): string => '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">', array_keys($fields), $fields))
    . '<button type="submit" class="pf-btn ' . $class . '">' . ($icon !== '' ? Icon::svg($icon, '') : '') . $e($label) . '</button></form>';
$percent = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1) . '%';
$tones   = ['draft' => 'pf-tone-muted', 'running' => 'pf-tone-ok', 'stopped' => 'pf-tone-warn'];
$best    = max(array_map(static fn (array $v): float => (float) ($v['rate'] ?? 0), $this->results['variants']) ?: [0.0]); @endphp
<div class="pf-ui">
    @php $this->activeNav = 'experiments_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $test['name'] }}</h1>
            <p class="pf-page-sub" data-field="about">
                <span class="pf-badge pf-status {!! $test['winner'] !== null ? 'pf-tone-accent' : ($tones[$test['status']] ?? 'pf-tone-muted') !!}">{{ $test['winner'] !== null ? t('Decided: everybody gets %s.', $test['winner']) : t($test['status']) }}</span>
                <span class="pf-muted">{{ $this->segment === null ? t('Everybody, visitors too') : t('People in %s', $this->segment) }} ·
                {{ $test['goalEvent'] === null ? t('No goal') : t('Goal: %s within %s days', \Pramnos\Audience\PersonEvents::label($test['goalEvent']), (string) $test['goalDays']) }}</span>
            </p>
        </div>
        <div class="pf-page-actions">
            @if ($test['status'] === 'draft')
                <a href="{{ adminUrl('Experiments/edit/') . $id }}" class="pf-btn">{!! Icon::svg('edit', '') !!}{{ t('Edit') }}</a>
                {!! $post('start', t('Start'), 'pf-btn-primary', [], 'play') !!}
            @elseif ($test['status'] === 'running')
                {!! $post('stop', t('Stop without a winner'), 'pf-btn-ghost', [], 'stop') !!}
            @endif
        </div>
    </header>
    @if (!$this->results['counted'] && $test['goalEvent'] !== null)
        <p class="pf-hint" data-field="uncounted">{{ t('The goal is not counted here: each occurrence is read from the change log — enable the changelog feature.') }}</p>
    @endif
    <div class="pf-card pf-card-flush">
        <table class="pf-table">
            <thead>
                <tr><th>{{ t('Test variant') }}</th><th class="pf-num">{{ t('People in the test') }}</th><th class="pf-num">{{ t('Reached the goal') }}</th><th>{{ t('Goal rate') }}</th><th class="pf-num">{{ t('How sure') }}</th><th class="pf-num">{{ t('Still needed') }}</th><th></th></tr>
            </thead>
            <tbody>
            @foreach ($this->results['variants'] as $i => $v)
                <tr data-variant="{{ $v['key'] }}">
                    <td><strong>{{ $v['key'] }}</strong> <span class="pf-muted">{{ $v['label'] }} · {!! (int) $v['share'] !!}%</span></td>
                    <td class="pf-num">{{ number_format($v['people']) }}</td>
                    <td class="pf-num">{{ number_format($v['converted']) }}</td>
                    <td class="pf-cell-bar">
                        <span class="pf-bars"><span>{{ $percent($v['rate']) }}</span><span class="pf-bar"><span style="width: {!! $best > 0 ? max(2, (int) round(100 * (float) ($v['rate'] ?? 0) / $best)) : 0 !!}%"></span></span></span>
                    </td>
                    <td class="pf-num">{!! $i === 0 ? '<span class="pf-muted">' . $e(t('the baseline')) . '</span>' : '<span class="pf-badge ' . (($v['confidence'] ?? 0) >= 0.95 ? 'pf-tone-ok' : 'pf-tone-muted') . '">' . $e($percent($v['confidence'])) . '</span>' !!}</td>
                    <td class="pf-num">{!! $v['needed'] === null ? '<span class="pf-zero">—</span>' : $e(number_format($v['needed'])) !!}</td>
                    <td class="pf-num">{!! $test['winner'] === null && $test['status'] !== 'draft' ? $post('decide', t('Make it the winner'), 'pf-btn-sm', ['variant' => $v['key']]) : ($test['winner'] === $v['key'] ? '<span class="pf-badge pf-tone-accent">✓ ' . $e(t('winner')) . '</span>' : '') !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="pf-hint pf-mt">{{ t('How sure is the chance the difference from the first variant is not luck; at 95%% or more it can be trusted. Still needed is how many more people each variant needs for the difference seen to be told. %s visitors have not signed up and are not counted.', number_format($this->results['visitors'])) }}</p>
    <div class="pf-card pf-mt"><div class="pf-card-body">
        <h2 class="pf-section-title">{{ t('In the code') }}</h2>
        <pre class="pf-code"><code>if (\Pramnos\Audience\Experiments::variant('{{ $test['slug'] }}') === '{{ $test['variants'][1]['key'] ?? 'B' }}') { … }</code></pre>
    </div></div>
</div>
