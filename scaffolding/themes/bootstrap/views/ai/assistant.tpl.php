@php /**
 * One person, in the assistants' control centre — `/admin/Ai/all?person=<id>` (Bootstrap theme): what
 * the companion knows of them (Chat\People::person()), their conversations by channel to read,
 * their memories by topic, and the experience reset where the reader may.
 *
 * Variables:
 *   $this->card          — People::person() and name
 *   $this->conversations — stored space => list of conversation_id, title, updated_at
 *   $this->memories      — stored space => topic => list of memories
 *   $this->channels      — stored space => name; $this->resets — whether the reader may reset
 *   $this->urls          — all, reset, memories
 */
$c        = ['card' => 'card pf-l-card', 'muted' => 'text-muted small', 'btn' => 'btn btn-sm btn-primary', 'field' => 'form-control form-control-sm input-sm', 'select' => 'form-control input-sm form-select form-select-sm pf-l-select', 'h1' => 'h3', 'table' => 'table table-sm'];
$card     = is_array($this->card ?? null) ? $this->card : [];
$channels = is_array($this->channels ?? null) ? $this->channels : [];
$u        = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'reset' => '', 'memories' => ''];
$when     = static fn (?string $iso): string => $iso === null || $iso === '' || ($t = strtotime($iso)) === false ? '—' : date('Y-m-d H:i', $t);
$persona  = static fn (?string $key): string => $key === null ? '—' : (string) (\Pramnos\Llm\Chat\Personas::get($key)['name'] ?? ($key === 'chat' ? t('Companion') : $key));
$name     = static fn (string $space): string => (string) ($channels[$space] ?? $space);
$id       = (int) ($card['userid'] ?? 0); @endphp
{!! \Pramnos\Theme\NeutralLayout::style() !!}
<div class="container-fluid pf-l-page">
    <p><a href="{{ $u['all'] }}">‹ {{ t('The assistants') }}</a></p>
    <h1 class="{!! $c['h1'] !!}">{{ $card['name'] ?? '' }}</h1>
    <p class="{!! $c['muted'] !!}">{{ t('Persona') }}: {{ $persona($card['persona'] ?? null) }}@if (($card['reset_at'] ?? null) !== null) · {{ t('Experience reset') }}: {{ $when($card['reset_at']) }}@endif · {{ sprintf(t('%d push subscriptions'), count((array) ($card['push'] ?? []))) }}</p>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <section class="{!! $c['card'] !!}" style="flex:1 1 320px;padding:12px">
            <h2 style="font-size:1.1rem;margin:0 0 8px">{{ t('Conversations') }}</h2>
            @if ($this->conversations === [])<p class="{!! $c['muted'] !!}">{{ t('No conversations.') }}</p>@endif
            @foreach ((array) $this->conversations as $space => $list)
                <h3 style="font-size:1rem;margin:10px 0 4px">{{ $name((string) $space) }} <span class="{!! $c['muted'] !!}">({{ count($list) }})</span></h3>
                <ul style="margin:0;padding-left:18px">
                @foreach (array_slice($list, 0, 50) as $one)<li><a href="{{ $u['all'] . '?channel=' . rawurlencode((string) $space) . '&c=' . (int) $one['conversation_id'] }}">{{ $one['title'] }}</a> <span class="{!! $c['muted'] !!}">{{ substr((string) $one['updated_at'], 0, 16) }}</span></li>@endforeach
                </ul>
            @endforeach
        </section>
        <section class="{!! $c['card'] !!}" style="flex:1 1 320px;padding:12px">
            <h2 style="font-size:1.1rem;margin:0 0 8px">{{ t('What the companion knows of them') }}</h2>
            @foreach ((array) ($card['personas'] ?? []) as $key => $one)
                @php $set = (array) ($one['settings'] ?? []); @endphp
                <details open>
                    <summary><strong>{{ $persona((string) $key) }}</strong> · {{ sprintf(t('%d conversations'), count($one['conversations'])) }}</summary>
                    <dl style="margin:6px 0">
                        <dt class="{!! $c['muted'] !!}">{{ t('First meeting') }}</dt><dd>{{ $when($one['first_meeting']) }}</dd>
                        <dt class="{!! $c['muted'] !!}">{{ t('Mood') }}</dt><dd>{{ $one['mood'] === null ? '—' : (string) ($one['mood']['mood'] ?? '') }}</dd>
                        <dt class="{!! $c['muted'] !!}">{{ t('Voice and style') }}</dt><dd>{{ ($one['voice'] ?? t('Default')) . ' · ' . ($one['style'] ?? t('Default')) }}</dd>
                        <dt class="{!! $c['muted'] !!}">{{ t('Morning thought') }}</dt><dd>{{ ($set['thought_at'] ?? null) === 'off' ? t('Off') : ($set['thought_at'] ?? t('Default')) }}@if (is_array($set['quiet'] ?? null)) · {{ t('Quiet') }} {{ $set['quiet']['from'] . '–' . $set['quiet']['to'] }}@endif</dd>
                        <dt class="{!! $c['muted'] !!}">{{ t('Diary') }}</dt><dd>{{ sprintf(t('%d pages, %d unread'), $one['diary']['total'], $one['diary']['unread']) }}</dd>
                        <dt class="{!! $c['muted'] !!}">{{ t('Today\'s thought') }}</dt><dd>{{ $one['thought'] ?? '—' }}</dd>
                        <dt class="{!! $c['muted'] !!}">{{ t('Routines') }}</dt><dd>{{ $one['routines'] === [] ? '—' : implode(', ', array_column($one['routines'], 'title')) }}</dd>
                    </dl>
                </details>
            @endforeach
            @if (!empty($card['profile']))
                <h3 style="font-size:1rem;margin:10px 0 4px">{{ t('Profile') }}</h3>
                <ul style="margin:0;padding-left:18px">@foreach ($card['profile'] as $entry)<li>{{ ($entry['subject'] ?? $entry['area']) . ': ' . ($entry['content'] ?? $entry['pending'] . ' (' . t('waiting for their word') . ')') }}</li>@endforeach</ul>
            @endif
            @if (!empty($this->resets))
                <details style="margin-top:12px">
                    <summary>{{ t('Reset the experience') }}</summary>
                    <p class="{!! $c['muted'] !!}">{{ t('Their persona, voice and style go back to the defaults, and each persona meets them again for the first time. Their conversations, memories, profile, diary and routines are kept.') }}</p>
                    <form method="post" action="{{ $u['reset'] }}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="userid" value="{!! $id !!}">
                        <input type="hidden" name="back" value="{{ '?person=' . $id }}">
                        <button type="submit" class="{!! $c['btn'] !!}">{{ sprintf(t('Yes, reset %s\'s experience'), (string) ($card['name'] ?? '')) }}</button>
                    </form>
                </details>
            @endif
        </section>
        <section class="{!! $c['card'] !!}" style="flex:1 1 320px;padding:12px">
            <h2 style="font-size:1.1rem;margin:0 0 8px">{{ t('Memories') }}</h2>
            @if ($this->memories === [])<p class="{!! $c['muted'] !!}">{{ t('Nothing remembered.') }}</p>@endif
            @foreach ((array) $this->memories as $space => $topics)
                <h3 style="font-size:1rem;margin:10px 0 4px"><a href="{{ $u['memories'] . '?' . http_build_query(['person' => $id, 'org' => '', 'space' => $space]) }}">{{ $name((string) $space) }}</a></h3>
                @foreach ($topics as $topic => $list)
                    <div class="{!! $c['muted'] !!}">{{ t(ucfirst((string) $topic)) }}</div>
                    <ul style="margin:0 0 6px;padding-left:18px">@foreach ($list as $memory)<li>{{ $memory['content'] }}</li>@endforeach</ul>
                @endforeach
            @endforeach
        </section>
    </div>
</div>
