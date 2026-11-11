@php /**
 * The assistant's picture in every state (Bootstrap theme) — `Ai/avatar`, opened as the settings are.
 *
 * Variables:
 *   $this->picture — Assist\AvatarSets::render('idle', 160): the set in use, large
 *   $this->states  — Assist\AvatarSets::states(): the framework's nine, then the set's own
 *   $this->poses   — Assist\AvatarSets::poses(): the set's poses, if it declares any
 *   $this->moods   — the set's own signals and the state (or pose) each plays: `avatar_set.moods`, or `avatar_moods`
 *   $this->urls    — settings, chat
 */
$c = ['page' => 'pf-l-page', 'card' => 'card pf-l-card', 'muted' => 'text-muted small', 'btn' => 'btn btn-sm btn-default btn-outline-primary', 'btnGhost' => 'btn btn-sm btn-default btn-outline-secondary', 'input' => 'form-control form-control-sm input-sm', 'table' => 'table table-sm', 'h1' => 'h3', 'h2' => 'h5'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$u = (array) ($this->urls ?? []);
$states = (array) ($this->states ?? []);
$moods  = (array) ($this->moods ?? []);
$poses  = (array) ($this->poses ?? []);
$btn = $c['btn']; @endphp
<div class="{!! $c['page'] !!}" data-pf-avatar-page>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px">
        <h1 class="{!! $c['h1'] !!}" style="margin:0">{{ $t('The assistant\'s picture') }}</h1>
        <a href="{{ $u['settings'] ?? '' }}" class="{!! $c['btnGhost'] !!}">&larr; {{ $t('AI settings') }}</a>
    </div>
    <section class="{!! $c['card'] !!}" style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
        {{-- The dock's own element, inline: the set's CSS for "away" reacts to it as it does in a corner. --}}
        <div class="pf-companion-dock pf-companion-dock--inline" data-pf-avatar-preview data-pf-companion-activity="active" style="position:relative;inset:auto;flex:none;width:auto;height:auto;max-width:100%;box-sizing:border-box;padding:16px 128px;overflow:hidden">{!! (string) ($this->picture ?? '') !!}</div>
        <div style="flex:1 1 18rem;display:flex;flex-direction:column;gap:12px">
            <div>
                <h2 class="{!! $c['h2'] !!}">{{ $t('States') }}</h2>
                <p class="{!! $c['muted'] !!}">{{ $t('A state that ends by itself goes back to idle. A state and a pose play together.') }}</p>
                <div style="display:flex;flex-wrap:wrap;gap:6px" role="group" aria-label="{{ $t('The picture\'s states') }}">
                    @foreach ($states as $state)<button type="button" class="{!! $btn !!}" data-pf-avatar-state="{{ $state }}" aria-pressed="{!! $state === 'idle' ? 'true' : 'false' !!}">{{ $state }}</button>@endforeach
                </div>
            </div>
            <div>
                <h2 class="{!! $c['h2'] !!}">{{ $t('Pose') }}</h2>
                @if ($poses !== [])
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px" role="group" aria-label="{{ $t('The picture\'s poses') }}">
                        <button type="button" class="{!! $btn !!}" data-pf-avatar-pose-pick="" aria-pressed="true">{{ $t('No pose') }}</button>
                        @foreach ($poses as $pose)<button type="button" class="{!! $btn !!}" data-pf-avatar-pose-pick="{{ $pose }}" aria-pressed="false">{{ $pose }}</button>@endforeach
                    </div>
                @endif
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    <label class="pf-sr-only" for="pf-avatar-pose">{{ $t('Any pose name') }}</label>
                    <input id="pf-avatar-pose" type="text" class="{!! $c['input'] !!}" placeholder="{{ $t('Any pose name') }}" data-pf-avatar-pose>
                    <button type="button" class="{!! $btn !!}" data-pf-avatar-pose-apply>{{ $t('Apply the pose') }}</button>
                    <button type="button" class="{!! $btn !!}" data-pf-avatar-away aria-pressed="false">{{ $t('Preview away') }}</button>
                </div>
            </div>
        </div>
    </section>
    @if ($moods !== [])
        <section class="{!! $c['card'] !!}">
            <h2 class="{!! $c['h2'] !!}">{{ $t('Signals and what they play') }}</h2>
            <table class="{!! $c['table'] !!}">
                <thead><tr><th>{{ $t('Signal') }}</th><th>{{ $t('Plays') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($moods as $signal => $plays)
                    <tr><td>{{ $signal }}</td><td>{{ $plays }}</td><td><button type="button" class="{!! $btn !!}" data-pf-avatar-try="{{ $plays }}">{{ $t('Try it') }}</button></td></tr>
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
    var pose = function (name) {
        name === '' ? avatar.removeAttribute('data-pose') : avatar.setAttribute('data-pose', name);
        page.querySelectorAll('[data-pf-avatar-pose-pick]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-pf-avatar-pose-pick') === name ? 'true' : 'false');
        });
    };
    var play = function (name) {
        if (states.indexOf(name) === -1) {
            pose(name);
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
    page.querySelectorAll('[data-pf-avatar-pose-pick]').forEach(function (b) {
        b.addEventListener('click', function () { pose(b.getAttribute('data-pf-avatar-pose-pick')); });
    });
    var typed = page.querySelector('[data-pf-avatar-pose]');
    page.querySelector('[data-pf-avatar-pose-apply]').addEventListener('click', function () {
        if (typed.value.trim() !== '') { pose(typed.value.trim()); }
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
