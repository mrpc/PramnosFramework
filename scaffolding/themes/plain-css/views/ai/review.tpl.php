@php /**
 * The other administrators' Ask conversations, read only — `/admin/Ai/all` (plain-CSS theme).
 *
 * Variables:
 *   $this->list    — conversation_id, owner, owner_name, title, updated_at, messages, cost
 *   $this->owners  — who has conversations: id => name, for the filter
 *   $this->filters — person, q
 *   $this->open    — the one opened, or null: owner, owner_name, conversation (title, messages —
 *                    each answer with its tools and `calls`, the call log's ids)
 *   $this->me      — who is reading
 *   $this->urls    — all, call (`call/<id>`), ask
 *
 * Nothing here writes: no question box, no delete, no memory. Answers are drawn by
 * Markdown::toHtml(), which escapes everything first.
 */
$c       = ['card' => 'pf-card', 'muted' => 'pf-muted', 'btn' => 'btn', 'btnGhost' => 'btn', 'field' => '', 'select' => '', 'question' => 'pf-card', 'banner' => 'pf-card'];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$list    = is_array($this->list ?? null) ? $this->list : [];
$owners  = is_array($this->owners ?? null) ? $this->owners : [];
$filters = (is_array($this->filters ?? null) ? $this->filters : []) + ['person' => 0, 'q' => ''];
$open    = is_array($this->open ?? null) ? $this->open : null;
$u       = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'call' => '', 'ask' => ''];
$openId  = (int) ($open['conversation']['conversation_id'] ?? 0);
$money   = static fn ($v): string => $v === null ? '' : '$' . number_format((float) $v, 4);
$keep    = static fn (array $extra): string => http_build_query(array_filter(['person' => (int) $filters['person'], 'q' => (string) $filters['q']] + $extra)); @endphp
@php // pf-chat.js draws the answers' charts and tables' copy buttons, as on Ask's page; with no form here it asks nothing.; @endphp
<div class="px-4 py-6" data-pf-chat>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="{!! $c['card'] !!}" style="flex:0 0 300px;padding:12px" aria-label="{{ t('Other people\'s Ask conversations') }}">
            <a href="{{ $u['ask'] }}" class="{!! $c['btnGhost'] !!}">‹ {{ t('Ask') }}</a>
            <form method="get" action="{{ $u['all'] }}" style="display:flex;flex-direction:column;gap:6px;margin:10px 0">
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
                <h1>{{ t('Other people\'s Ask conversations') }}</h1>
                <p class="{!! $c['muted'] !!}">{{ t('Pick a conversation to read it as its owner sees it. Opening somebody else\'s is recorded in the audit log.') }}</p>
            @else
                <p class="{!! $c['banner'] !!}" role="note" style="padding:8px 12px">{{ sprintf(t('%s\'s conversation — read only'), $open['owner_name']) }}</p>
                <h1>{{ $open['conversation']['title'] }}</h1>
                @foreach ($open['conversation']['messages'] as $m)
                    @php $mine = $m['role'] === 'user'; @endphp
                    <article style="display:flex;flex-direction:column;align-items:{!! $mine ? 'flex-end' : 'flex-start' !!};margin-bottom:16px">
                        <header class="{!! $c['muted'] !!}" style="margin-bottom:4px"><strong>{{ $mine ? $open['owner_name'] : t('Analyst') }}</strong> · {{ $m['created_at'] }}@if (($m['cost'] ?? null) !== null) · {{ $money($m['cost']) }}@endif</header>
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
    </div>
</div>
<script src="{{ assetUrl('assets/js/pf-chat.js') }}" defer></script>
