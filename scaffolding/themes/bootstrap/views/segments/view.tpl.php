@php /**
 * One segment: how many are in it, who came and went, where it is used, its newest members, and
 * why one person is in or out. The same markup in every theme.
 *
 * Variables:
 *   $this->segment   — Segments::get()
 *   $this->movement  — ['entered', 'left'] over seven days
 *   $this->uses      — what uses it: name, url (null where it has no page)
 *   $this->usedBy    — the same, names only
 *   $this->members   — the newest members: userid, name, since, url
 *   $this->person    — the account asked about, or 0
 *   $this->why       — Filter::explain() for that account, or null
 *   $this->isMember  — whether that account is in the segment as last worked out
 */
use Pramnos\Html\Icon;

$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment;
$id      = (int) $segment['id'];
$uses    = $this->uses ?? array_map(static fn (string $name): array => ['name' => $name, 'url' => null], (array) ($this->usedBy ?? []));
$explain = static function (array $group) use (&$explain, $e): string {
    $html = '<ul class="pf-why">';
    foreach ($group['conditions'] as $c) {
        if (isset($c['conditions'])) {
            $html .= '<li class="pf-why-group"><span class="pf-label">' . $e($c['match'] === 'any' ? t('any of') : t('all of')) . ' '
                . ($c['ok'] ? '✓' : '✗') . '</span>' . $explain($c) . '</li>';
            continue;
        }
        $said  = \Pramnos\Audience\Explained::condition($c);
        $html .= '<li><span class="pf-why-mark ' . ($c['ok'] ? 'pf-up' : 'pf-down') . '" aria-label="' . $e($c['ok'] ? t('yes') : t('no')) . '">'
            . ($c['ok'] ? '✓' : '✗') . '</span><span>' . $e($said['what']) . ' ' . $e($said['how'])
            . ($said['value'] !== null ? ' <strong>' . $e($said['value']) . '</strong>' : '')
            . ' <span class="pf-muted">· ' . $e(t('has: %s', $said['actual'])) . '</span></span></li>';
    }

    return $html . '</ul>';
}; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'segments_view'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $segment['name'] }}</h1>
            @if ($segment['description'] !== '')<p class="pf-page-sub">{{ $segment['description'] }}</p>@endif
        </div>
        <div class="pf-page-actions">
            <form method="post" action="{{ adminUrl('Segments/delete/') . $id }}" class="pf-inline-form" data-confirm="{{ t('Delete this segment and its history?') }}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger" {!! $uses === [] ? '' : 'disabled title="' . $e(t('In use')) . '"' !!}>{{ t('Delete') }}</button>
            </form>
            <form method="post" action="{{ adminUrl('Segments/recompute/') . $id }}" class="pf-inline-form">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <button type="submit" class="pf-btn">{!! Icon::svg('retry', '') !!}{{ t('Work it out now') }}</button>
            </form>
            <a href="{{ adminUrl('Segments/edit/') . $id }}" class="pf-btn pf-btn-primary">{!! Icon::svg('edit', '') !!}{{ t('Edit') }}</a>
        </div>
    </header>

    <div class="pf-card"><div class="pf-card-body pf-stats">
        <div><span class="pf-label">{{ t('Members in it') }}</span><span class="pf-stat-value" data-field="members">{{ number_format($segment['members']) }}</span></div>
        <div><span class="pf-label">{{ t('Entered, 7 days') }}</span><span class="pf-stat-value {!! $this->movement['entered'] > 0 ? 'pf-up' : 'pf-zero' !!}">▲ {{ number_format($this->movement['entered']) }}</span>@php // First worked out this week: its first members are its start, not people entering.
            if (($segment['firstComputedAt'] ?? null) !== null && $segment['firstComputedAt'] > time() - 7 * 86400): @endphp<span class="pf-muted">{{ t('since %s', date('d/m', $segment['firstComputedAt'])) }}</span>@endif</div>
        <div><span class="pf-label">{{ t('Left, 7 days') }}</span><span class="pf-stat-value {!! $this->movement['left'] > 0 ? 'pf-down' : 'pf-zero' !!}">▼ {{ number_format($this->movement['left']) }}</span></div>
        <div><span class="pf-label">{{ t('Worked out') }}</span><span class="pf-stat-value pf-stat-small">{{ $segment['recomputedAt'] === null ? t('not yet') : date('Y-m-d H:i', $segment['recomputedAt']) }}</span></div>
    </div></div>

    <div class="pf-grid-2 pf-mt">
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title">{{ t('Why is this person in or out?') }}</h2>
            <form method="get" action="{{ adminUrl('Segments/view/') . $id }}" class="pf-rule-sentence">
                <label class="pf-visually-hidden" for="segment-person">{{ t('Account id') }}</label>
                <input id="segment-person" type="number" min="1" name="person" class="pf-input pf-input-id" value="{!! $this->person > 0 ? (int) $this->person : '' !!}" placeholder="{{ t('Account id') }}">
                <button type="submit" class="pf-btn">{{ t('Explain') }}</button>
            </form>
            @if ($this->why !== null)
                <p class="pf-why-verdict" data-field="verdict">
                    <span class="pf-badge {!! $this->why['ok'] ? 'pf-tone-ok' : 'pf-tone-bad' !!}">{{ $this->why['ok'] ? t('Matches the filter now.') : t('Does not match the filter now.') }}</span>
                    <span class="pf-muted">{{ !empty($this->isMember) ? t('In the segment as last worked out.') : t('Not in the segment as last worked out.') }}</span>
                </p>
                @if (!$this->why['inScope'])<p class="pf-hint">{{ t('This account is not counted as a person here at all (the application leaves it out).') }}</p>@endif
                {!! $explain($this->why) !!}
            @endif
        </div></div>
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title">{{ t('Newest members') }}</h2>
            @if ($this->members === [])
                <p class="pf-muted">{{ t('Nobody is in it.') }}</p>
            @else
                <ul class="pf-people">
                @foreach ($this->members as $m)
                    <li><a class="pf-person" href="{{ $m['url'] }}">
                        <span class="pf-avatar" aria-hidden="true" style="--pf-hue: hsl({!! ((int) $m['userid'] * 47) % 360 !!} 55% 42%)">{{ mb_strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($m['name'])) ?: ['?'], 0, 2)))) }}</span>
                        <span><span class="pf-person-name">{{ $m['name'] }}</span><span class="pf-person-line">{{ t('since %s', date('Y-m-d', $m['since'])) }}</span></span>
                    </a></li>
                @endforeach
                </ul>
            @endif
            <h2 class="pf-section-title pf-spaced">{{ t('Used by') }}</h2>
            @if ($uses === [])
                <p class="pf-muted">{{ t('Nothing uses it.') }}</p>
            @else
                <span class="pf-chips">@foreach ($uses as $use){!! $use['url'] !== null ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>' : '<span class="pf-chip">' . $e($use['name']) . '</span>' !!}@endforeach</span>
            @endif
        </div></div>
    </div>
</div>
