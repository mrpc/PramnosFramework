@php /**
 * The assistant's picture in every state (plain CSS theme) — `Ai/avatar`, opened as the settings are.
 *
 * Variables:
 *   $this->picture — Assist\AvatarSets::render('idle', 160): the set in use, large
 *   $this->states  — Assist\AvatarSets::STATES
 *   $this->moods   — the set's own signals and the state (or pose) each plays: `avatar_set.moods`, or `avatar_moods`
 *   $this->urls    — settings, chat
 */
$c = ['page' => 'container', 'card' => 'card card-body', 'muted' => 'pf-muted', 'btn' => 'btn btn-outline-secondary btn-sm', 'btnGhost' => 'btn btn-outline-secondary btn-sm', 'input' => 'form-control pf-ui-input-sm', 'table' => 'table', 'h1' => '', 'h2' => ''];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$u = (array) ($this->urls ?? []);
$states = (array) ($this->states ?? []);
$moods  = (array) ($this->moods ?? []);
$btn = $c['btn'] . ' pf-btn pf-btn-sm'; @endphp
<div class="{!! $c['page'] !!}" data-pf-avatar-page>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px">
        <h1 class="{!! $c['h1'] !!}" style="margin:0">{{ $t('The assistant\'s picture') }}</h1>
        <a href="{{ $u['settings'] ?? '' }}" class="{!! $c['btnGhost'] !!}">&larr; {{ $t('AI settings') }}</a>
    </div>
    <section class="{!! $c['card'] !!}" style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
        {{-- The dock's own element, inline: the set's CSS for "away" reacts to it as it does in a corner. --}}
        <div class="pf-companion-dock pf-companion-dock--inline" data-pf-avatar-preview data-pf-companion-activity="active" style="padding:16px">{!! (string) ($this->picture ?? '') !!}</div>
        <div style="flex:1 1 18rem;display:flex;flex-direction:column;gap:12px">
            <div>
                <h2 class="{!! $c['h2'] !!}">{{ $t('States') }}</h2>
                <p class="{!! $c['muted'] !!}">{{ $t('Success, greeting and notify go back to idle by themselves.') }}</p>
                <div style="display:flex;flex-wrap:wrap;gap:6px" role="group" aria-label="{{ $t('States') }}">
                    @foreach ($states as $state)<button type="button" class="{!! $btn !!}" data-pf-avatar-state="{{ $state }}" aria-pressed="{!! $state === 'idle' ? 'true' : 'false' !!}">{{ $state }}</button>@endforeach
                </div>
            </div>
            <div>
                <h2 class="{!! $c['h2'] !!}">{{ $t('Pose') }}</h2>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    <label class="pf-sr-only" for="pf-avatar-pose">{{ $t('Any pose name') }}</label>
                    <input id="pf-avatar-pose" type="text" class="{!! $c['input'] !!}" placeholder="{{ $t('Any pose name') }}" data-pf-avatar-pose>
                    <button type="button" class="{!! $btn !!}" data-pf-avatar-pose-apply>{{ $t('Apply') }}</button>
                    <button type="button" class="{!! $btn !!}" data-pf-avatar-away aria-pressed="false">{{ $t('Away') }}</button>
                </div>
            </div>
        </div>
    </section>
    @if ($moods !== [])
        <section class="{!! $c['card'] !!}">
            <h2 class="{!! $c['h2'] !!}">{{ $t('Signals') }}</h2>
            <table class="{!! $c['table'] !!}">
                <thead><tr><th>{{ $t('Signal') }}</th><th>{{ $t('Plays') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($moods as $signal => $plays)
                    <tr><td>{{ $signal }}</td><td>{{ $plays }}</td><td><button type="button" class="{!! $btn !!}" data-pf-avatar-try="{{ $plays }}">{{ $t('Try') }}</button></td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
<script nonce="{{ defined('PAGE_NONCE') ? PAGE_NONCE : '' }}">
// The picture driven through pfAssistant (pf-utils.js), within this preview only; the mark follows
// data-state, so a state that ends by itself moves it back to idle too.
(function () {
    var page = document.querySelector('[data-pf-avatar-page]');
    var preview = page.querySelector('[data-pf-avatar-preview]');
    var avatar = preview.querySelector('.pf-assistant-avatar');
    if (!avatar) { return; }
    var states = {!! json_encode(array_values($states)) !!};
    var play = function (name) {
        if (states.indexOf(name) === -1) {
            avatar.setAttribute('data-pose', name);
            return;
        }
        if (window.pfAssistant) {
            window.pfAssistant.state(name, preview);
        } else {
            avatar.setAttribute('data-state', name);
        }
    };
    var mark = function () {
        var now = avatar.getAttribute('data-state');
        page.querySelectorAll('[data-pf-avatar-state]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-pf-avatar-state') === now ? 'true' : 'false');
        });
    };
    new MutationObserver(mark).observe(avatar, { attributes: true, attributeFilter: ['data-state'] });
    page.querySelectorAll('[data-pf-avatar-state]').forEach(function (b) {
        b.addEventListener('click', function () { play(b.getAttribute('data-pf-avatar-state')); });
    });
    page.querySelectorAll('[data-pf-avatar-try]').forEach(function (b) {
        b.addEventListener('click', function () { play(b.getAttribute('data-pf-avatar-try')); });
    });
    var pose = page.querySelector('[data-pf-avatar-pose]');
    page.querySelector('[data-pf-avatar-pose-apply]').addEventListener('click', function () {
        if (pose.value.trim() !== '') { avatar.setAttribute('data-pose', pose.value.trim()); }
    });
    var away = page.querySelector('[data-pf-avatar-away]');
    away.addEventListener('click', function () {
        var isAway = preview.getAttribute('data-pf-companion-activity') !== 'away';
        preview.setAttribute('data-pf-companion-activity', isAway ? 'away' : 'active');
        away.setAttribute('aria-pressed', isAway ? 'true' : 'false');
        document.dispatchEvent(new CustomEvent('pf-companion:activity', { bubbles: true, detail: { active: !isAway } }));
    });
})();
</script>
