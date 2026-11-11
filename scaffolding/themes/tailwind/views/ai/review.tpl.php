@php /**
 * The assistants' control centre: everybody's conversations, channel by channel, read only — `/admin/Ai/all` (Tailwind theme).
 *
 * Variables:
 *   $this->list    — conversation_id, owner, owner_name, title, updated_at, messages, cost
 *   $this->owners  — who has conversations: id => name, for the filter
 *   $this->channels — the channels: stored space => name (the Ask, each companion persona, others)
 *   $this->filters — channel, person, q
 *   $this->person  — beside a companion conversation, what it knows of its owner
 *                    (Chat\People::person()), else null
 *   $this->resets  — whether the reader may reset that person's experience
 *   $this->open    — the one opened, or null: owner, owner_name, conversation (title, messages —
 *                    each answer with its tools and `calls`, the call log's ids)
 *   $this->me      — who is reading
 *   $this->urls    — all, call (`call/<id>`), ask, reset, reviews, memories, settings, scheduled
 *
 * Nothing here writes: no question box, no delete, no memory. Answers are drawn by
 * Markdown::toHtml(), which escapes everything first.
 */
$c       = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'field' => 'input input-bordered input-sm', 'select' => 'select select-bordered select-sm', 'question' => 'rounded-2xl bg-base-200', 'banner' => 'alert alert-warning'];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$list    = is_array($this->list ?? null) ? $this->list : [];
$owners  = is_array($this->owners ?? null) ? $this->owners : [];
$filters = (is_array($this->filters ?? null) ? $this->filters : []) + ['channel' => 'admin', 'person' => 0, 'q' => ''];
$channels = is_array($this->channels ?? null) ? $this->channels : ['admin' => t('Ask')];
$person  = is_array($this->person ?? null) ? $this->person : null;
$resets  = !empty($this->resets);
$open    = is_array($this->open ?? null) ? $this->open : null;
$u       = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'call' => '', 'ask' => '', 'reset' => '', 'reviews' => '', 'memories' => '', 'settings' => '', 'scheduled' => ''];
$openId  = (int) ($open['conversation']['conversation_id'] ?? 0);
$money   = static fn ($v): string => $v === null ? '' : '$' . number_format((float) $v, 4);
$keep    = static fn (array $extra): string => http_build_query(array_filter(['channel' => $filters['channel'] === 'admin' ? '' : (string) $filters['channel'], 'person' => (int) $filters['person'], 'q' => (string) $filters['q']] + $extra));
$said    = static fn ($v): string => is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE); @endphp
@php // pf-chat.js draws the answers' charts and tables' copy buttons, as on Ask's page; with no form here it asks nothing.; @endphp
<div class="px-4 py-6" data-pf-chat>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="{!! $c['card'] !!}" style="flex:0 0 300px;padding:12px" aria-label="{{ t('The assistants') }}">
            <a href="{{ $u['ask'] }}" class="{!! $c['btnGhost'] !!}">‹ {{ t('Ask') }}</a>
            <p style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">
                <a href="{{ $u['reviews'] }}">{{ t('Answers to review') }}</a>
                <a href="{{ $u['memories'] }}">{{ t('Memories') }}</a>
                <a href="{{ $u['scheduled'] }}">{{ t('Scheduled') }}</a>
                <a href="{{ $u['settings'] }}">{{ t('Costs and settings') }}</a>
            </p>
            <form method="get" action="{{ $u['all'] }}" style="display:flex;flex-direction:column;gap:6px;margin:10px 0">
                <label class="{!! $c['muted'] !!}" for="review-channel">{{ t('Channel') }}</label>
                <select id="review-channel" name="channel" class="{!! $c['select'] !!}">
                    @foreach ($channels as $space => $name)
                        <option value="{{ $space }}"{!! (string) $space === (string) $filters['channel'] ? ' selected' : '' !!}>{{ $name }}</option>
                    @endforeach
                </select>
                <label class="{!! $c['muted'] !!}" for="review-person">{{ t('Whose conversations') }}</label>
                <select id="review-person" name="person" class="{!! $c['select'] !!}">
                    <option value="0">{{ t('Everybody') }}</option>
                    @foreach ($owners as $id => $name)
                        <option value="{!! (int) $id !!}"{!! (int) $id === (int) $filters['person'] ? ' selected' : '' !!}>{{ $name }}</option>
                    @endforeach
                </select>
                <label class="{!! $c['muted'] !!}" for="review-q">{{ t('A word in the title') }}</label>
                <input id="review-q" type="search" name="q" value="{{ $filters['q'] }}" class="{!! $c['field'] !!}">
                <button type="submit" class="{!! $c['btn'] !!}">{{ t('Filter the conversations') }}</button>
            </form>
            <ul style="list-style:none;padding:0;margin:0">
                @if ($list === [])
                    <li class="{!! $c['muted'] !!}">{{ t('No conversations.') }}</li>
                @endif
                @foreach ($list as $one)
                    <li style="margin-bottom:8px">
                        <a href="{{ $u['all'] . '?' . $keep(['c' => (int) $one['conversation_id']]) }}"{!! (int) $one['conversation_id'] === $openId ? ' aria-current="page" style="font-weight:600"' : '' !!}>{{ $one['title'] }}</a>
                        <div class="{!! $c['muted'] !!}">{{ $one['owner_name'] . ' · ' . $one['updated_at'] . ' · ' . sprintf(t('%d messages'), (int) $one['messages']) . ' · ' . $money($one['cost']) }}</div>
                    </li>
                @endforeach
            </ul>
        </nav>

        <main style="flex:1 1 420px;min-width:0">
            @if ($open === null)
                <h1 class="text-2xl font-semibold mb-3">{{ t('The assistants') }}</h1>
                <p class="{!! $c['muted'] !!}">{{ t('Pick a conversation to read it as its owner sees it. Opening somebody else\'s is recorded in the audit log.') }}</p>
            @else
                <p class="{!! $c['banner'] !!}" role="note" style="padding:8px 12px">{{ sprintf(t('%s\'s conversation — read only'), $open['owner_name']) }}</p>
                <h1 class="text-2xl font-semibold mb-3">{{ $open['conversation']['title'] }}</h1>
                @foreach ($open['conversation']['messages'] as $m)
                    @php $mine = $m['role'] === 'user'; @endphp
                    <article style="display:flex;flex-direction:column;align-items:{!! $mine ? 'flex-end' : 'flex-start' !!};margin-bottom:16px">
                        <header class="{!! $c['muted'] !!}" style="margin-bottom:4px"><strong>{{ $mine ? $open['owner_name'] : ($channels[$open['channel'] ?? 'admin'] ?? t('Analyst')) }}</strong> · {{ $m['created_at'] }}@if (($m['cost'] ?? null) !== null) · {{ $money($m['cost']) }}@endif</header>
                        <div class="{!! $mine ? $c['question'] : '' !!}" style="{!! $mine ? 'padding:10px 14px;max-width:80%;white-space:pre-wrap' : 'width:100%' !!}">
                            @if ($mine)
                                {{ $m['content'] }}
                            @else
                                <div class="pf-chat-answer">{!! \Pramnos\Llm\Chat\Markdown::toHtml((string) $m['content']) !!}</div>
                                @php $steps = array_values(array_filter((array) (($m['extra'] ?? [])['steps'] ?? []), 'is_string')); @endphp
                                @if ($steps !== [])
                                    <details class="{!! $c['muted'] !!}">
                                        <summary>{{ t('What it did on the way') }}</summary>
                                        @foreach ($steps as $step)<div>{!! \Pramnos\Llm\Chat\Markdown::toHtml($step) !!}</div>@endforeach
                                    </details>
                                @endif
                                @php $tools = (array) ($m['tools'] ?? []); @endphp
                                @if ($tools !== [])
                                    <details class="{!! $c['muted'] !!}">
                                        <summary>{{ sprintf(t('Tools it used (%d)'), count($tools)) }}</summary>
                                        <ul>
                                            @foreach ($tools as $tool)
                                                <li><code>{{ $tool['name'] ?? '' }}</code> <pre style="white-space:pre-wrap;margin:2px 0 6px">{{ json_encode($tool['arguments'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                                @if (($m['calls'] ?? []) !== [])
                                    <p class="{!! $c['muted'] !!}">{{ t('The model calls, with what each tool returned:') }}
                                        @foreach ($m['calls'] as $call)<a href="{{ $u['call'] . (int) $call }}">#{!! (int) $call !!}</a> @endforeach</p>
                                @endif
                            @endif
                        </div>
                    </article>
                @endforeach
            @endif
        </main>
        @if ($person !== null)
            <aside class="{!! $c['card'] !!}" style="flex:0 0 300px;padding:12px" aria-label="{{ t('What the companion knows of them') }}">
                <h2 style="font-size:1.1rem;margin:0 0 8px">{{ $open['owner_name'] }}</h2>
                <p class="{!! $c['muted'] !!}">{{ t('Persona') }}: {{ $person['persona'] ?? '—' }}@if ($person['reset_at'] !== null) · {{ t('Experience reset') }}: {{ $person['reset_at'] }}@endif</p>
                @foreach ($person['personas'] as $key => $one)
                    <details open>
                        <summary><strong>{{ $channels[$key === 'chat' ? 'chat' : 'chat:' . $key] ?? $key }}</strong> · {{ sprintf(t('%d conversations'), count($one['conversations'])) }}</summary>
                        <dl style="margin:6px 0">
                            <dt class="{!! $c['muted'] !!}">{{ t('First meeting') }}</dt><dd>{{ $one['first_meeting'] ?? '—' }}</dd>
                            <dt class="{!! $c['muted'] !!}">{{ t('Mood') }}</dt><dd>{{ $one['mood'] === null ? '—' : $said($one['mood']['mood'] ?? $one['mood']) }}</dd>
                            <dt class="{!! $c['muted'] !!}">{{ t('Voice and style') }}</dt><dd>{{ ($one['voice'] ?? '—') . ' · ' . ($one['style'] ?? '—') }}</dd>
                            <dt class="{!! $c['muted'] !!}">{{ t('Diary') }}</dt><dd>{{ sprintf(t('%d pages, %d unread'), $one['diary']['total'], $one['diary']['unread']) }}</dd>
                            <dt class="{!! $c['muted'] !!}">{{ t('Today\'s thought') }}</dt><dd>{{ $one['thought'] ?? '—' }}</dd>
                            <dt class="{!! $c['muted'] !!}">{{ t('Memories') }}</dt><dd>@if ($one['memories'] === [])—@else<ul style="margin:0;padding-left:18px">@foreach ($one['memories'] as $memory)<li>{{ $said($memory['content'] ?? $memory) }}</li>@endforeach</ul>@endif</dd>
                            <dt class="{!! $c['muted'] !!}">{{ t('Routines') }}</dt><dd>@if ($one['routines'] === [])—@else<ul style="margin:0;padding-left:18px">@foreach ($one['routines'] as $routine)<li>{{ $routine['title'] . ' · ' . ($routine['next'] ?? '') }}</li>@endforeach</ul>@endif</dd>
                        </dl>
                    </details>
                @endforeach
                @if ($person['profile'] !== [])
                    <h3 style="font-size:1rem;margin:10px 0 4px">{{ t('Profile') }}</h3>
                    <ul style="margin:0;padding-left:18px">@foreach ($person['profile'] as $entry)<li>{{ ($entry['subject'] ?? $entry['area']) . ': ' . ($entry['content'] ?? $entry['pending'] . ' (' . t('waiting for their word') . ')') }}</li>@endforeach</ul>
                @endif
                <p class="{!! $c['muted'] !!}">{{ sprintf(t('%d push subscriptions'), count($person['push'])) }}</p>
                @if ($resets)
                    <details>
                        <summary>{{ t('Reset the experience') }}</summary>
                        <p class="{!! $c['muted'] !!}">{{ t('Their persona, voice and style go back to the defaults, and each persona meets them again for the first time. Their conversations, memories, profile, diary and routines are kept.') }}</p>
                        <form method="post" action="{{ $u['reset'] }}">
                            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                            <input type="hidden" name="userid" value="{!! (int) $open['owner'] !!}">
                            <input type="hidden" name="back" value="{{ '?' . $keep([]) }}">
                            <button type="submit" class="{!! $c['btn'] !!}">{{ sprintf(t('Yes, reset %s\'s experience'), $open['owner_name']) }}</button>
                        </form>
                    </details>
                @endif
            </aside>
        @endif
    </div>
</div>
<script src="{{ assetUrl('assets/js/pf-chat.js') }}" defer></script>
