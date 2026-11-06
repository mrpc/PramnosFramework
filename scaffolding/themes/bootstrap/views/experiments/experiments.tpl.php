@php /**
 * The A/B tests: each with its status, how many took part, and the variant ahead. The same markup
 * in every theme.
 *
 * Variables:
 *   $this->tests — Experiments::all(), each with people and leading (the variant with the best rate, or null)
 */
use Pramnos\Html\Icon;

$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$tones = ['draft' => 'pf-tone-muted', 'running' => 'pf-tone-ok', 'stopped' => 'pf-tone-warn']; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'experiments'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ t('A/B tests') }}</h1>
        </div>
        <div class="pf-page-actions">
            <a href="{{ adminUrl('Experiments/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New test') }}</a>
        </div>
    </header>
    <div class="pf-card pf-card-flush">
        @if (empty($this->tests))
            <div class="pf-empty">
                <span class="pf-empty-icon">{!! Icon::svg('experiment', '') !!}</span>
                <p class="pf-empty-title">{{ t('No tests yet') }}</p>
                <p class="pf-empty-text">{{ t('A test shows people one of two or more variants and counts which reaches the goal more often.') }}</p>
                <a href="{{ adminUrl('Experiments/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New test') }}</a>
            </div>
        @else
            <table class="pf-table">
                <thead>
                    <tr><th>{{ t('Name') }}</th><th>{{ t('Test status') }}</th><th>{{ t('Test variants') }}</th><th class="pf-num">{{ t('People in the test') }}</th><th>{{ t('Ahead now') }}</th></tr>
                </thead>
                <tbody>
                @foreach ($this->tests as $test)
                    <tr>
                        <td>
                            <a class="pf-row-link" href="{{ adminUrl('Experiments/view/') . $test['id'] }}">{{ $test['name'] }}</a>
                            <span class="pf-row-desc"><code>{{ $test['slug'] }}</code></span>
                        </td>
                        <td><span class="pf-badge pf-status {!! $test['winner'] !== null ? 'pf-tone-accent' : ($tones[$test['status']] ?? 'pf-tone-muted') !!}">{{ $test['winner'] !== null ? t('decided: %s', $test['winner']) : t($test['status']) }}</span></td>
                        <td><span class="pf-chips">@foreach ($test['variants'] as $v)<span class="pf-chip">{{ $v['key'] . ' · ' . $v['share'] . '%' }}</span>@endforeach</span></td>
                        <td class="pf-num">{{ number_format($test['people']) }}</td>
                        <td>{!! $test['leading'] === null ? '<span class="pf-zero">—</span>' : '<span class="pf-badge pf-tone-ok">' . $e($test['leading']['key'] . ' · ' . number_format($test['leading']['rate'] * 100, 1) . '%') . '</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
