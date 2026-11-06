@php /**
 * Groups and segments: a tab each — all of them, groups, segments, the account's own segments
 * (`audience.tabs` keeps some) — with its count, and the tab's own datatable, its rows from `Segments/data`: a group's number, members,
 * order and what uses it; a segment's members, who came and went this week, what uses it, whose it
 * is and when it was last worked out. The button makes what the tab lists.
 * The same markup in every theme: it is dressed by the `pf-*` rules in components.css.
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable, server-side, the tab sent with it
 *   $this->tab       — the tab shown: all, groups, segments or mine (`audience.default_tab` when none was asked for)
 *   $this->tabs      — the tabs shown, in order
 *   $this->counts    — how many each tab holds: all, groups, segments, mine
 */
use Pramnos\Html\Icon;

$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$current = (string) ($this->tab ?? 'groups');
$counts  = (array) ($this->counts ?? []);
$tab     = static fn (string $label, string $query, bool $on, string $key) => '<a href="' . $e(adminUrl('Segments') . $query) . '" class="pf-tab"'
    . ($on ? ' aria-current="page"' : '') . '>' . $e($label) . (isset($counts[$key]) ? ' <span class="pf-count">' . (int) $counts[$key] . '</span>' : '') . '</a>'; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'segments'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ t('Groups and segments') }}</h1>
        </div>
        <div class="pf-page-actions">
            @if ($current === 'groups' || $current === 'all')
                <a href="{{ adminUrl('Groups/edit') }}" class="pf-btn{!! $current === 'groups' ? ' pf-btn-primary' : '' !!}">{!! Icon::svg('plus', '') !!}{{ t('New group') }}</a>
            @endif
            @if ($current !== 'groups')
                <a href="{{ adminUrl('Segments/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New segment') }}</a>
            @endif
        </div>
    </header>
    <div class="pf-toolbar">
        <nav class="pf-tabs" aria-label="{{ t('Show') }}">
            @foreach ((array) ($this->tabs ?? ['all', 'groups', 'segments', 'mine']) as $shown)
                {!! match ($shown) { 'all' => $tab(t('All'), '?kind=all', $current === 'all', 'all'), 'groups' => $tab(t('Groups'), '?kind=groups', $current === 'groups', 'groups'), 'segments' => $tab(t('Segments'), '?kind=segments', $current === 'segments', 'segments'), default => $tab(t('Mine'), '?mine=1', $current === 'mine', 'mine'), } !!}
            @endforeach
        </nav>
    </div>
    <div class="pf-card pf-card-flush">
        @if (($current === 'segments' || $current === 'mine' || $current === 'all' && (int) ($counts['groups'] ?? 0) === 0) && (int) ($counts['segments'] ?? 0) === 0)
            <div class="pf-empty">
                <span class="pf-empty-icon">{!! Icon::svg('segment', '') !!}</span>
                <p class="pf-empty-title">{{ t('No segments yet') }}</p>
                <p class="pf-empty-text">{{ t('A segment is a filter over people, kept up to date: people enter and leave it on their own, and a rule can act when they do.') }}</p>
                <a href="{{ adminUrl('Segments/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New segment') }}</a>
            </div>
        @else
            {!! $this->datatable->render() !!}
        @endif
    </div>
</div>
