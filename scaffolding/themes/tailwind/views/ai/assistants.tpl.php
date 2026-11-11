@php /**
 * The assistants' control centre, its front — `/admin/Ai/all` (Tailwind theme): a channel's figures,
 * or every channel's, a chart of its days, and the people who talked to it (Analyst\Overview).
 *
 * Variables:
 *   $this->channels — stored space => name; $this->filters — channel ('' every one), q, sort
 *   $this->stats    — people, new, conversations, messages, cost (today, 7, 30), companion, daily
 *   $this->people   — userid, name, persona, last, conversations, messages, cost, memories, channels
 *   $this->urls     — all, ask, reviews, memories, settings, scheduled
 */
$c        = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'btn' => 'btn btn-sm btn-primary', 'field' => 'input input-bordered input-sm', 'select' => 'select select-bordered select-sm', 'h1' => 'text-2xl font-semibold', 'table' => 'table table-sm'];
$filters  = (is_array($this->filters ?? null) ? $this->filters : []) + ['channel' => '', 'q' => '', 'sort' => 'last'];
$channels = is_array($this->channels ?? null) ? $this->channels : [];
$stats    = is_array($this->stats ?? null) ? $this->stats : [];
$people   = is_array($this->people ?? null) ? $this->people : [];
$u        = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'ask' => '', 'reviews' => '', 'memories' => '', 'settings' => '', 'scheduled' => ''];
$money    = static fn ($v): string => \Pramnos\General\NumberFormat::smallMoney((float) $v, 'USD');
$count    = static fn ($v): string => \Pramnos\General\NumberFormat::number((float) $v);
$keep     = static fn (array $extra): string => http_build_query(array_filter(['channel' => $filters['channel'], 'q' => $filters['q'], 'sort' => $filters['sort']] + $extra));
$persona  = static fn (?string $key): string => $key === null ? '—' : (string) (\Pramnos\Llm\Chat\Personas::get($key)['name'] ?? $key);
$tile     = 'padding:10px 14px;min-width:150px;flex:1 1 150px';
$c3 = $stats['companion'] ?? null; @endphp
<div class="px-4 py-6" data-pf-chat>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px">
        <h1 class="{!! $c['h1'] !!}" style="margin:0">{{ t('The assistants') }}</h1>
        <p style="display:flex;flex-wrap:wrap;gap:12px;margin:0">
            @if ($u['ask'] !== '')<a href="{{ $u['ask'] }}">‹ {{ $channels['admin'] ?? t('Ask') }}</a>@endif
            <a href="{{ $u['reviews'] }}">{{ t('Answers to review') }}</a>
            <a href="{{ $u['memories'] . '?space=' . rawurlencode($filters['channel'] !== '' ? (string) $filters['channel'] : 'admin') }}">{{ t('Memories') }}</a>
            <a href="{{ $u['scheduled'] }}">{{ t('Scheduled') }}</a>
            <a href="{{ $u['settings'] }}">{{ t('Costs and settings') }}</a>
        </p>
    </div>
    <form method="get" action="{{ $u['all'] }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0">
        <label class="{!! $c['muted'] !!}" for="assistants-channel">{{ t('Channel') }}</label>
        <select id="assistants-channel" name="channel" class="{!! $c['select'] !!}" data-pf-autosubmit>
            <option value="">{{ t('Every channel') }}</option>
            @foreach ($channels as $space => $name)<option value="{{ $space }}"{!! (string) $space === (string) $filters['channel'] ? ' selected' : '' !!}>{{ $name }}</option>@endforeach
        </select>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ t('Name or email') }}" class="{!! $c['field'] !!}" aria-label="{{ t('Name or email') }}">
        <select name="sort" class="{!! $c['select'] !!}" aria-label="{{ t('Sort by') }}">
            @foreach (['last' => t('Latest'), 'name' => t('Name'), 'messages' => t('Messages'), 'cost' => t('Cost')] as $key => $label)<option value="{{ $key }}"{!! $key === $filters['sort'] ? ' selected' : '' !!}>{{ $label }}</option>@endforeach
        </select>
        <button type="submit" class="{!! $c['btn'] !!}">{{ t('Show') }}</button>
    </form>

    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px">
        <div class="{!! $c['card'] !!}" style="{!! $tile !!}"><div class="{!! $c['muted'] !!}">{{ t('People') }}</div>
            <div style="font-size:1.5em">{{ $count($stats['people']['30'] ?? 0) }}</div>
            <div class="{!! $c['muted'] !!}">{{ t('30 days') }} · {{ $count($stats['people']['7'] ?? 0) }} {{ t('in 7') }} · {{ $count($stats['people']['today'] ?? 0) }} {{ t('today') }} · {{ $count($stats['people']['all'] ?? 0) }} {{ t('ever') }}</div></div>
        <div class="{!! $c['card'] !!}" style="{!! $tile !!}"><div class="{!! $c['muted'] !!}">{{ t('New people') }}</div>
            <div style="font-size:1.5em">{{ $count($stats['new'] ?? 0) }}</div><div class="{!! $c['muted'] !!}">{{ t('first conversation in 30 days') }}</div></div>
        <div class="{!! $c['card'] !!}" style="{!! $tile !!}"><div class="{!! $c['muted'] !!}">{{ t('Conversations') }}</div>
            <div style="font-size:1.5em">{{ $count($stats['conversations']['30'] ?? 0) }}</div>
            <div class="{!! $c['muted'] !!}">{{ t('30 days') }} · {{ $count($stats['conversations']['7'] ?? 0) }} {{ t('in 7') }} · {{ $count($stats['conversations']['today'] ?? 0) }} {{ t('today') }}</div></div>
        <div class="{!! $c['card'] !!}" style="{!! $tile !!}"><div class="{!! $c['muted'] !!}">{{ t('Messages') }}</div>
            <div style="font-size:1.5em">{{ $count($stats['messages']['30'] ?? 0) }}</div>
            <div class="{!! $c['muted'] !!}">{{ t('30 days') }} · {{ $count($stats['messages']['7'] ?? 0) }} {{ t('in 7') }} · {{ $count($stats['messages']['today'] ?? 0) }} {{ t('today') }}</div></div>
        <div class="{!! $c['card'] !!}" style="{!! $tile !!}"><div class="{!! $c['muted'] !!}">{{ t('Cost') }}</div>
            <div style="font-size:1.5em">{{ $money($stats['cost']['30'] ?? 0) }}</div>
            <div class="{!! $c['muted'] !!}">{{ t('30 days') }} · {{ $money($stats['cost']['today'] ?? 0) }} {{ t('today') }}</div></div>
        @if (is_array($c3))
        <div class="{!! $c['card'] !!}" style="{!! $tile !!}"><div class="{!! $c['muted'] !!}">{{ t('Companion') }}</div>
            <div class="{!! $c['muted'] !!}">{{ $count($c3['subscribers']) }} {{ t('with the app\'s notifications') }}<br>{{ $count($c3['thoughts']) }} {{ t('morning thoughts') }} · {{ $count($c3['diaries']) }} {{ t('diary pages') }} ({{ t('30 days') }})
                @if ($c3['moods'] !== [])<br>{{ t('Moods') }}: {{ implode(', ', array_map(static fn ($m, $n): string => $m . ' ' . $n, array_keys(array_slice($c3['moods'], 0, 4, true)), array_slice($c3['moods'], 0, 4, true))) }}@endif</div></div>
        @endif
    </div>
    @if (!empty($stats['daily']['labels']))
    <div class="{!! $c['card'] !!}" style="padding:10px 14px;margin-bottom:12px">
        {!! \Pramnos\Html\Chart::render('bar', $stats['daily']['labels'], [['label' => t('Messages'), 'data' => $stats['daily']['values']]], ['title' => t('Messages, the last 30 days'), 'height' => 160]) !!}
    </div>
    @endif

    <div class="{!! $c['card'] !!}" style="padding:10px 14px;overflow-x:auto">
        <h2 style="font-size:1.1rem;margin:0 0 8px">{{ t('People') }}</h2>
        @if ($people === [])
            <p class="{!! $c['muted'] !!}">{{ t('Nobody yet.') }}</p>
        @else
        <table class="{!! $c['table'] !!}">
            <thead><tr><th>{{ t('Name') }}</th><th>{{ t('Persona') }}</th><th>{{ t('Latest') }}</th><th>{{ t('By channel') }}</th><th style="text-align:right">{{ t('Messages') }}</th><th style="text-align:right">{{ t('Memories') }}</th><th style="text-align:right">{{ t('Cost') }}</th></tr></thead>
            <tbody>
            @foreach ($people as $one)
                <tr>
                    <td><a href="{{ $u['all'] . '?' . $keep(['person' => (int) $one['userid']]) }}">{{ $one['name'] }}</a></td>
                    <td>{{ $persona($one['persona']) }}</td>
                    <td>{{ substr((string) $one['last'], 0, 16) }}</td>
                    <td class="{!! $c['muted'] !!}">{{ implode(' · ', array_map(static fn ($space, $n): string => ($channels[$space] ?? $space) . ' ' . $n['conversations'] . '/' . $n['messages'], array_keys($one['channels']), $one['channels'])) }}</td>
                    <td style="text-align:right">{{ $count($one['messages']) }}</td>
                    <td style="text-align:right">{{ $count($one['memories']) }}</td>
                    <td style="text-align:right">{{ $money($one['cost']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="{!! $c['muted'] !!}">{{ t('By channel: conversations/messages.') }}</p>
        @endif
    </div>
</div>
<script src="{{ assetUrl('assets/js/pf-chat.js') }}" defer></script>
