@php /**
 * Everybody's Ask memory (Bootstrap theme) — `Ai/memories`, for whoever reads everybody's Ask
 * conversations: who has memories, how many and when the latest changed; with `?person=` one
 * person's (0: what an organisation shares), each to correct or delete. Each reading is in the
 * audit log (Pramnos\Llm\Chat\MemoryAdmin).
 *
 * Variables:
 *   $this->people   — userid, username, organization_id, space, count, latest; newest first
 *   $this->person   — the person open, or null; $this->org, $this->space — whose and where
 *   $this->memories — theirs: memory_id, kind (fact|preference), content, source (model|learned|null), topic, at
 *   $this->channels — the channels: stored space => name; $this->topics — Memory::TOPICS
 *   $this->urls     — page, correct, delete, chat
 */
$c     = ['page' => 'pf-l-page', 'card' => 'card pf-l-card', 'muted' => 'text-muted small', 'bad' => 'text-danger small', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-default btn-outline-secondary', 'danger' => 'btn btn-sm btn-default btn-outline-danger text-danger', 'input' => 'form-control form-control-sm input-sm', 'select' => 'form-control input-sm form-select form-select-sm pf-l-select', 'textarea' => 'form-control', 'badge' => 'badge text-bg-secondary', 'row' => 'pf-l-row', 'field' => 'pf-l-field small', 'answer' => '', 'h1' => 'h3', 'h2' => 'h5'];
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t     = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$u     = (array) ($this->urls ?? []);
$query = static fn (array $who): string => '?' . http_build_query(['person' => $who['userid'], 'org' => $who['organization_id'] ?? '', 'space' => $who['space']]);
$whose = static fn (array $who): string => $who['userid'] === 0
    ? $t('Shared by organisation %s', (string) ($who['organization_id'] ?? '—'))
    : (string) $who['username'];
$open  = null;
foreach ((array) ($this->people ?? []) as $who) {
    if ($this->person === $who['userid'] && $this->org === $who['organization_id'] && $this->space === $who['space']) {
        $open = $who;
    }
}
$split = 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px'; @endphp
{!! \Pramnos\Theme\NeutralLayout::style() !!}
<div class="{!! $c['page'] !!}" data-pf-memories>
    <div style="{!! $split !!};align-items:center">
        <h1 class="{!! $c['h1'] !!}" style="margin:0">{{ $t('Everybody\'s memory') }} · {{ ((array) ($this->channels ?? []))[$this->space] ?? $this->space }}</h1>
        <form method="get" action="{{ $u['page'] ?? '' }}" class="{!! $c['row'] !!}">
            <label class="{!! $c['muted'] !!}" for="memories-channel">{{ $t('Channel') }}</label>
            <select id="memories-channel" name="space" class="{!! $c['select'] !!}" data-pf-autosubmit>
                @foreach ((array) ($this->channels ?? []) as $space => $name)<option value="{{ $space }}"{!! (string) $space === (string) $this->space ? ' selected' : '' !!}>{{ $name }}</option>@endforeach
            </select>
            <noscript><button class="{!! $c['btnGhost'] !!}">{{ $t('Show') }}</button></noscript>
        </form>
        <a href="{{ $u['chat'] ?? '' }}" class="{!! $c['btnGhost'] !!}">&larr; {{ $t('Back to the conversations') }}</a>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="{!! $c['card'] !!}" style="flex:0 0 16rem" aria-label="{{ $t('People') }}">
            @if (empty($this->people))<p class="{!! $c['muted'] !!}">{{ $t('Nobody has memories yet.') }}</p>@endif
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:4px">
            @foreach ((array) ($this->people ?? []) as $who)
                <li><a href="{{ ($u['page'] ?? '') . $query($who) }}"{!! $who === $open ? ' aria-current="page" style="font-weight:600"' : '' !!}>
                    {{ $whose($who) }}</a>
                    <span class="{!! $c['muted'] !!}">{{ $t('%s · %s', (string) $who['count'], substr((string) $who['latest'], 0, 10)) }}{!! $who['space'] !== 'chat' ? ' · ' . $e($who['space']) : '' !!}</span></li>
            @endforeach
            </ul>
        </nav>
        <main style="flex:1 1 24rem;min-width:0;display:flex;flex-direction:column;gap:12px">
        @if ($open === null)
            <p class="{!! $c['muted'] !!}">{{ $t('Choose somebody to see what is remembered about them.') }}</p>
        @else
            <h2 class="{!! $c['h2'] !!}">{{ $whose($open) }}</h2>
            @foreach ((array) ($this->memories ?? []) as $memory)
            <div class="{!! $c['card'] !!}" data-pf-memory="{!! (int) $memory['memory_id'] !!}">
                <div class="{!! $c['row'] !!}"><span class="{!! $c['badge'] !!}">{{ $t($memory['kind'] === 'preference' ? 'Preference' : 'Fact') }}</span>
                    <span class="{!! $c['badge'] !!}">{{ $t(ucfirst((string) ($memory['topic'] ?? 'other'))) }}</span>
                    <span class="{!! $c['muted'] !!}">{{ $memory['source'] === 'learned' ? $t('Learned') : $t('Kept on request') }} · {{ $memory['at'] }}</span></div>
                <form method="post" action="{{ $u['correct'] ?? '' }}" style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
                    {!! $token() !!}
                    <input type="hidden" name="memory_id" value="{!! (int) $memory['memory_id'] !!}">
                    <input type="hidden" name="back" value="{{ $query($open) }}">
                    <textarea name="content" rows="2" class="{!! $c['textarea'] !!}" style="width:100%" aria-label="{{ $t('What it remembers') }}">{{ $memory['content'] }}</textarea>
                    <div class="{!! $c['row'] !!}">
                        <select name="topic" class="{!! $c['select'] !!}" aria-label="{{ $t('What it is about') }}">
                            @foreach ((array) ($this->topics ?? []) as $topic)<option value="{{ $topic }}"{!! $topic === ($memory['topic'] ?? 'other') ? ' selected' : '' !!}>{{ $t(ucfirst($topic)) }}</option>@endforeach
                        </select>
                        <button class="{!! $c['btn'] !!}">{{ $t('Save') }}</button>
                        <button class="{!! $c['danger'] !!}" formaction="{{ $u['delete'] ?? '' }}">{{ $t('Delete') }}</button>
                    </div>
                </form>
            </div>
            @endforeach
        @endif
        </main>
    </div>
</div>
