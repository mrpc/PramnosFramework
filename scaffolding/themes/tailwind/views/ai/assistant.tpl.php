@php /**
 * One person, in the assistants' control centre — `/admin/Ai/all?person=<id>` (Tailwind theme): what
 * the companion knows of them (Chat\People::person()), their conversations by channel to read,
 * their memories by topic, and the experience reset where the reader may.
 *
 * Variables:
 *   $this->card          — People::person() and name
 *   $this->conversations — stored space => list of conversation_id, title, updated_at
 *   $this->memories      — stored space => topic => list of memories
 *   $this->holdings      — section => rows: routines, calendar, artifacts, shared, shares, asks, dossier, skills, the application's
 *   $this->channels      — stored space => name; $this->resets — whether the reader may reset
 *   $this->deletes       — whether the reader may delete for good; $this->deletable — the sections they may delete rows of
 *   $this->urls          — all, reset, memories, delete, deleteHeld, artifact
 */
$c        = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'btn' => 'btn btn-sm btn-primary', 'field' => 'input input-bordered input-sm', 'select' => 'select select-bordered select-sm', 'h1' => 'text-2xl font-semibold', 'table' => 'table table-sm'];
$card     = is_array($this->card ?? null) ? $this->card : [];
$channels = is_array($this->channels ?? null) ? $this->channels : [];
$u        = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'reset' => '', 'memories' => '', 'delete' => '', 'deleteHeld' => '', 'artifact' => ''];
$when     = static fn (?string $iso): string => $iso === null || $iso === '' || ($t = strtotime($iso)) === false ? '—' : date('Y-m-d H:i', $t);
$persona  = static fn (?string $key): string => $key === null ? '—' : (string) (\Pramnos\Llm\Chat\Personas::get($key)['name'] ?? ($key === 'chat' ? t('Companion') : $key));
$name     = static fn (string $space): string => (string) ($channels[$space] ?? $space);
$id       = (int) ($card['userid'] ?? 0); @endphp
<div class="px-4 py-6">
    {{-- Here, not only in the theme's header: an application's own admin theme may not show the flash. --}}
    {!! \Pramnos\Html\Flash::render() !!}
    <p><a href="{{ $u['all'] }}">‹ {{ t('The assistants') }}</a></p>
    <h1 class="{!! $c['h1'] !!}">{{ $card['name'] ?? '' }}</h1>
    <p class="{!! $c['muted'] !!}">{{ t('Persona') }}: {{ $persona($card['persona'] ?? null) }}@if (($card['reset_at'] ?? null) !== null) · {{ t('Experience reset') }}: {{ $when($card['reset_at']) }}@endif · {{ sprintf(t('%d push subscriptions'), count((array) ($card['push'] ?? []))) }}</p>
    @if (!empty($this->resets))
                <details style="margin:0 0 12px">
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
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <section class="{!! $c['card'] !!}" style="flex:1 1 320px;padding:12px">
            <h2 style="font-size:1.1rem;margin:0 0 8px">{{ t('Conversations') }}</h2>
            @if (!empty($this->deletes) && $this->conversations !== [])<form method="post" action="{{ $u['delete'] }}">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="back" value="{{ '?person=' . $id }}">@endif
            @if ($this->conversations === [])<p class="{!! $c['muted'] !!}">{{ t('No conversations.') }}</p>@endif
            @foreach ((array) $this->conversations as $space => $list)
                <h3 style="font-size:1rem;margin:10px 0 4px">{{ $name((string) $space) }} <span class="{!! $c['muted'] !!}">({{ count($list) }})</span></h3>
                <ul style="margin:0;padding-left:18px">
                @foreach (array_slice($list, 0, 50) as $one)<li>@if (!empty($this->deletes))<input type="checkbox" name="ids[]" value="{!! (int) $one['conversation_id'] !!}" aria-label="{{ t('Select') }}"> @endif<a href="{{ $u['all'] . '?channel=' . rawurlencode((string) $space) . '&c=' . (int) $one['conversation_id'] }}">{{ $one['title'] }}</a> <span class="{!! $c['muted'] !!}">{{ substr((string) $one['updated_at'], 0, 16) }}</span></li>@endforeach
                </ul>
            @endforeach
            @if (!empty($this->deletes) && $this->conversations !== [])
                <details style="margin-top:10px">
                    <summary>{{ t('Delete the selected conversations') }}</summary>
                    <p class="{!! $c['muted'] !!}">{{ t('They go for good, with their messages, what they made and their shares. This cannot be undone.') }}</p>
                    <button type="submit" class="{!! $c['btn'] !!}">{{ t('Yes, delete them for good') }}</button>
                </details>
            </form>@endif
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
        </section>
        <section class="{!! $c['card'] !!}" style="flex:1 1 320px;padding:12px">
            <h2 style="font-size:1.1rem;margin:0 0 8px">{{ t('Memories') }}</h2>
            @if (!empty($this->deletes) && $this->memories !== [])<form method="post" action="{{ $u['deleteHeld'] }}">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="section" value="memories"><input type="hidden" name="back" value="{{ '?person=' . $id }}">@endif
            @if ($this->memories === [])<p class="{!! $c['muted'] !!}">{{ t('Nothing remembered.') }}</p>@endif
            @foreach ((array) $this->memories as $space => $topics)
                <h3 style="font-size:1rem;margin:10px 0 4px"><a href="{{ $u['memories'] . '?' . http_build_query(['person' => $id, 'org' => '', 'space' => $space]) }}">{{ $name((string) $space) }}</a></h3>
                @foreach ($topics as $topic => $list)
                    <div class="{!! $c['muted'] !!}">{{ t(ucfirst((string) $topic)) }}</div>
                    <ul style="margin:0 0 6px;padding-left:18px">@foreach ($list as $memory)<li>@if (!empty($this->deletes))<input type="checkbox" name="ids[]" value="{!! (int) ($memory['memory_id'] ?? 0) !!}" aria-label="{{ t('Select') }}"> @endif{{ $memory['content'] }}</li>@endforeach</ul>
                @endforeach
            @endforeach
            @if (!empty($this->deletes) && $this->memories !== [])
                <details style="margin-top:8px">
                    <summary>{{ t('Delete the selected') }}</summary>
                    <p class="{!! $c['muted'] !!}">{{ t('They go for good. This cannot be undone.') }}</p>
                    <button type="submit" class="{!! $c['btn'] !!}">{{ t('Yes, delete them for good') }}</button>
                </details>
            </form>@endif
        </section>
    </div>
    @php
        $titles = ['routines' => t('Routines, reminders and timers'), 'calendar' => t('Calendar'), 'artifacts' => t('Artifacts they made'), 'shared' => t('Artifacts shared with them'),
            'shares' => t('Shares'), 'asks' => t('Questions'), 'dossier' => t('Dossier'), 'skills' => t('Their skills')];
        $cell   = static fn (mixed $v): string => $v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? '✓' : '—') : mb_strimwidth((string) $v, 0, 160, '…'));
        $opens  = static function (string $section, array $row) use ($u): string {
            $id = (int) ($row['_id'] ?? 0);
            return match (true) {
                in_array($section, ['artifacts', 'shared'], true)                       => $u['artifact'] . $id,
                $section === 'shares' && ($row['kind'] ?? '') === 'artifact'            => $u['artifact'] . (int) ($row['target_id'] ?? 0),
                $section === 'shares' && ($row['kind'] ?? '') === 'conversation'        => $u['all'] . '?c=' . (int) ($row['target_id'] ?? 0),
                default                                                                  => '',
            };
        };
        $deletable = !empty($this->deletes) ? (array) ($this->deletable ?? []) : [];
    @endphp
    @foreach ((array) ($this->holdings ?? []) as $section => $rows)
        @if (is_array($rows) && $rows !== [])
        @php $del = isset($deletable[$section]); $cols = array_values(array_filter(array_keys((array) reset($rows)), static fn ($k): bool => $k !== 'target_id' && !str_starts_with((string) $k, '_'))); @endphp
        <details class="{!! $c['card'] !!}" style="margin-top:16px;padding:12px">
            <summary><strong>{{ $titles[$section] ?? t((string) $section) }}</strong> <span class="{!! $c['muted'] !!}">({{ count($rows) }})</span></summary>
            @if ($del)<form method="post" action="{{ $u['deleteHeld'] }}">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="section" value="{{ $section }}"><input type="hidden" name="back" value="{{ '?person=' . $id }}">@endif
            <div style="overflow-x:auto"><table class="{!! $c['table'] !!}">
                <thead><tr>@if ($del)<th></th>@endif @foreach ($cols as $col)<th>{{ $col === 'conversation_id' ? t('From') : t(ucfirst(str_replace('_', ' ', (string) $col))) }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($rows as $row)<tr>
                    @if ($del)<td><input type="checkbox" name="ids[]" value="{!! (int) ($row['_id'] ?? 0) !!}" aria-label="{{ t('Select') }}"></td>@endif
                    @foreach ($cols as $i => $col)@php $v = $row[$col] ?? null; $open = $i === 0 ? $opens((string) $section, (array) $row) : ''; @endphp<td>
                        @if ($col === 'conversation_id')@if ((int) $v > 0)<a href="{{ $u['all'] . '?channel=' . rawurlencode((string) ($row['space'] ?? '')) . '&c=' . (int) $v }}">{{ t('Conversation') }} #{{ (int) $v }}</a>@else{{ t('Outside a conversation') }}@endif
                        @elseif ($open !== '')<a href="{{ $open }}" target="_blank" rel="noopener">{{ $cell($v) }}</a>
                        @else{{ $cell($v) }}@endif</td>@endforeach
                </tr>@endforeach</tbody>
            </table></div>
            @if ($del)
                <details style="margin-top:8px">
                    <summary>{{ t('Delete the selected') }}</summary>
                    <p class="{!! $c['muted'] !!}">{{ t('They go for good. This cannot be undone.') }}</p>
                    <button type="submit" class="{!! $c['btn'] !!}">{{ t('Yes, delete them for good') }}</button>
                </details>
            </form>@endif
        </details>
        @endif
    @endforeach
</div>
