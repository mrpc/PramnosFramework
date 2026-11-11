@php /**
 * Every language-model setting of the installation, in one place (Tailwind theme).
 *
 * Variables:
 *   $this->providers  — each provider: id, label, configured, model, models, base_url, …; never the key
 *   $this->selected   — the provider used unless a feature or a caller names another
 *   $this->timeout    — seconds to wait for a completion; 0 for the default
 *   $this->logging    — whether calls are written to the log
 *   $this->features   — the application's features and what each has set
 *   $this->prices     — the unit prices, as JSON
 *   $this->day        — the last 24 hours: calls, failed, tokens, cost, unpriced, ms
 *   $this->month      — the same, over 30 days
 *   $this->byModel    — the 30 days, by model
 *   $this->calls      — the most recent calls, or the problems only
 *   $this->problems   — whether only the problems are shown
 *   $this->openai     — OpenAI's billed costs, or null without an Admin key
 *   $this->appSections — the application's own sections: key, title, html (not
 *                       `sections`: that is View's own property, and the template would read it)
 *   $this->canEncrypt — whether keys are stored encrypted
 *
 * Whoever manages skills is shown the way to the application's skills (`Ai/skillcatalog`).
 *
 * A key is never printed back: its field is empty with a placeholder saying whether one is
 * saved, and an empty submit keeps it. Every cost is an estimate from the prices below, and says
 * so; the provider's own balance, where it publishes one, is shown beside it.
 */
$c = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'ok' => 'text-success', 'bad' => 'text-error', 'btn' => 'btn btn-sm btn-primary', 'btnOutline' => 'btn btn-sm btn-ghost', 'input' => 'input input-sm input-bordered', 'table' => 'table table-sm'];
$e         = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Amounts and counts in the page's language: `1,05 $` and `34.571.857` in Greek, `$1.05` and `34,571,857` in English.
$usd       = static fn ($v): string => $v === null ? '—' : \Pramnos\General\NumberFormat::smallMoney((float) $v, 'USD');
$count     = static fn ($v): string => \Pramnos\General\NumberFormat::number((float) $v);
$providers = is_array($this->providers ?? null) ? $this->providers : [];
$features  = is_array($this->features ?? null) ? $this->features : [];
$calls     = is_array($this->calls ?? null) ? $this->calls : [];
$byModel   = is_array($this->byModel ?? null) ? $this->byModel : [];
$sections  = is_array($this->appSections ?? null) ? $this->appSections : [];
$day       = is_array($this->day ?? null) ? $this->day : [];
$month     = is_array($this->month ?? null) ? $this->month : [];
$openai    = is_array($this->openai ?? null) ? $this->openai : null;
$selected  = (string) ($this->selected ?? '');
$action    = $e(adminUrl('Ai/save'));
$labels    = array_column($providers, 'label', 'id'); @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'ai'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <h1 class="text-2xl font-semibold mb-2">AI settings</h1>

    <p><a href="{{ adminUrl('Ai/avatar') }}">The assistant's picture, in every state &rarr;</a></p>

    <p><a href="{{ adminUrl('Ai') }}">← Ask</a>@if (\Pramnos\Llm\Chat\CatalogSkills::canManage(\Pramnos\User\User::current())) · <a href="{{ adminUrl('Ai/skillcatalog') }}">Skills</a>@endif</p>
    <p class="{!! $c['muted'] !!}">
        The language models this installation calls, what each of its features uses, and what it has cost.
        Costs are estimates from the prices below{!! $openai !== null ? '; OpenAI\'s own figure is beside them' : '' !!}.
    </p>

    @if (!($this->canEncrypt ?? true))
        <p class="{!! $c['bad'] !!}">APP_KEY is not set, so keys saved here are stored unencrypted. Run <code>key:generate</code>.</p>
    @endif

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin:16px 0">
        @foreach ([t('Last 24 hours') => $day, t('Last 30 days') => $month] as $title => $sum)
            <div class="{!! $c['card'] !!}" style="padding:10px 14px;min-width:220px">
                <div class="{!! $c['muted'] !!}">{{ $title }}</div>
                <div style="font-size:1.6em">{!! $usd($sum['cost'] ?? 0) !!}</div>
                <div class="{!! $c['muted'] !!}">
                    {{ $count($sum['calls'] ?? 0) }} {{ t('calls') }} ·
                    {{ $count($sum['tokens'] ?? 0) }} {{ t('tokens') }} ·
                    <span class="{!! (int) ($sum['failed'] ?? 0) > 0 ? $c['bad'] : '' !!}">{{ $count($sum['failed'] ?? 0) }} {{ t('failed') }}</span>
                    @if ((int) ($sum['unpriced'] ?? 0) > 0)
                        · {{ $count($sum['unpriced']) }} {{ t('not priced, not in the total') }}
                    @endif
                </div>
            </div>
        @endforeach
        @if ($openai !== null)
            <div class="{!! $c['card'] !!}" style="padding:10px 14px;min-width:220px">
                <div class="{!! $c['muted'] !!}">OpenAI says, this month</div>
                @if (!empty($openai['ok']))
                    <div style="font-size:1.6em">{!! $usd($openai['month_usd'] ?? null) !!}</div>
                    <div class="{!! $c['muted'] !!}">
                        our log: {!! $usd($openai['meter_month_usd'] ?? null) !!}
                        @if (($openai['balance_usd'] ?? null) !== null) · left: {!! $usd($openai['balance_usd']) !!}@endif
                    </div>
                @else
                    <div class="{!! $c['bad'] !!}">{{ $openai['says'] ?? '' }}</div>
                @endif
            </div>
        @endif
    </div>

    <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">General</h2>
        <form method="post" action="{!! $action !!}">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="section" value="general">
            <label>Provider
                <select name="provider" class="{!! $c['input'] !!}">
                    @foreach ($providers as $p)
                        <option value="{{ $p['id'] }}"{!! $p['id'] === $selected ? ' selected' : '' !!}>{{ $p['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Timeout, seconds
                <input type="number" name="timeout" min="0" max="300" value="{!! (int) ($this->timeout ?? 0) ?: '' !!}" placeholder="60" class="{!! $c['input'] !!}">
            </label>
            <label><input type="checkbox" name="log" value="1"{!! !empty($this->logging) ? ' checked' : '' !!}> Log every call</label>
            <button type="submit" class="{!! $c['btn'] !!}">Save</button>
        </form>
    </section>

    <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px" id="keys">
        <h2 class="text-lg font-semibold mb-2">API keys</h2>
        <p class="{!! $c['muted'] !!}">
            Saved encrypted, and never shown again: an empty field keeps the key a provider has.
            @foreach ($providers as $p)
                {{ $p['label'] }}: <span class="{!! $p['configured'] ? $c['ok'] : $c['muted'] !!}">{!! $p['configured'] ? 'saved' : 'none' !!}</span>@if ($p['api_key_url'] !== '') (<a href="{{ $p['api_key_url'] }}" target="_blank" rel="noopener">get one</a>)@endif.
            @endforeach
        </p>
        <form method="post" action="{!! $action !!}">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="section" value="key">
            <label>Provider
                <select name="provider" class="{!! $c['input'] !!}">
                    @foreach ($providers as $p)
                        <option value="{{ $p['id'] }}"{!! $p['id'] === $selected ? ' selected' : '' !!}>{{ $p['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Key
                <input type="password" name="api_key" id="llm-api-key" autocomplete="off" class="{!! $c['input'] !!}">
                {!! \Pramnos\Html\PasswordToggle::render('llm-api-key', '', '', 'btn btn-sm btn-ghost') !!}
            </label>
            <label><input type="checkbox" name="clear_key" value="1"> Remove this provider's key</label>
            <button type="submit" class="{!! $c['btn'] !!}">Save</button>
        </form>
    </section>

    @foreach ($providers as $p)
        <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px" id="provider-{{ $p['id'] }}">
            <h2 class="text-lg font-semibold mb-2">
                {{ $p['label'] }}
                <span class="{!! $p['configured'] ? $c['ok'] : $c['muted'] !!}">{!! $p['configured'] ? 'key saved' : 'no key' !!}</span>
                @if ($p['id'] === $selected)<span class="{!! $c['muted'] !!}">· in use</span>@endif
            </h2>
            @if ($p['consent'])
                <p class="{!! $c['muted'] !!}">Established in {{ $p['place'] ?: 'a country without an adequacy decision' }}: data may need agreement before it is sent.</p>
            @endif
            @if (is_array($p['balance'] ?? null))
                <p class="{!! $c['muted'] !!}">Balance:
                    @foreach ((array) ($p['balance']['balance_infos'] ?? []) as $info)
                        {{ ($info['total_balance'] ?? '') . ' ' . ($info['currency'] ?? '') }}
                    @endforeach
                </p>
            @endif
            <form method="post" action="{!! $action !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="section" value="provider">
                <input type="hidden" name="provider" value="{{ $p['id'] }}">
                <label>Model
                    <select name="model" class="{!! $c['input'] !!}">
                        @foreach ((array) $p['models'] as $id => $what)
                            <option value="{{ $id }}"{!! (string) $id === $p['model'] ? ' selected' : '' !!}>{{ $what }}</option>
                        @endforeach
                        @if (!isset($p['models'][$p['model']]))<option value="{{ $p['model'] }}" selected>{{ $p['model'] }}</option>@endif
                    </select>
                </label>
                <label>Base URL
                    <input type="url" name="base_url" value="{{ $p['base_url'] }}" placeholder="{{ $p['default_url'] }}" class="{!! $c['input'] !!}">
                </label>
                <label>Temperature
                    <input type="number" name="temperature" step="0.1" min="0" max="2" value="{{ $p['temperature'] }}" placeholder="0.7" class="{!! $c['input'] !!}">
                </label>
                <label>Output budget, tokens
                    <input type="number" name="max_tokens" min="1" value="{{ $p['max_tokens'] }}" placeholder="2000" class="{!! $c['input'] !!}">
                </label>
                <label><input type="checkbox" name="reasoning" value="1"{!! $p['reasoning'] ? ' checked' : '' !!}> Let the model reason first (billed)</label>
                <button type="submit" class="{!! $c['btn'] !!}">Save</button>
            </form>
            <form method="post" action="{{ adminUrl('Ai/check') }}" style="margin-top:8px">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="provider" value="{{ $p['id'] }}">
                <button type="submit" class="{!! $c['btnOutline'] !!}"{!! $p['configured'] ? '' : ' disabled' !!}>Test the key</button>
            </form>
        </section>
    @endforeach

    @if ($features !== [])
        <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px">
            <h2 class="text-lg font-semibold mb-2">Features</h2>
            <p class="{!! $c['muted'] !!}">What each part of the application uses. Empty follows the provider's own settings.</p>
            <form method="post" action="{!! $action !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="section" value="features">
                <table class="{!! $c['table'] !!}">
                    <thead><tr><th>Feature</th><th>Provider</th><th>Model</th><th>Temperature</th><th>Budget</th><th>Reasoning</th></tr></thead>
                    <tbody>
                    @php foreach ($features as $f): $n = 'features[' . $e($f['name']) . ']'; $v = $f['values']; @endphp
                        <tr>
                            <td>{{ $f['label'] }}</td>
                            <td><select name="{!! $n !!}[provider]" class="{!! $c['input'] !!}">
                                <option value="">—</option>
                                @foreach ($labels as $id => $label)
                                    <option value="{{ $id }}"{!! $v['provider'] === (string) $id ? ' selected' : '' !!}>{{ $label }}</option>
                                @endforeach
                            </select></td>
                            @php $rowProvider = $v['provider'] !== '' ? $v['provider'] : $selected; $rowModels = (array) (array_column($providers, 'models', 'id')[$rowProvider] ?? []); @endphp
                            <td><select name="{!! $n !!}[model]" class="{!! $c['input'] !!}" data-pf-feature-model data-default-provider="{{ $selected }}">
                                <option value="">— (the provider's)</option>
                                @foreach ($providers as $p)
                                    <optgroup label="{{ $p['label'] }}" data-provider="{{ $p['id'] }}">
                                        @foreach ((array) $p['models'] as $id => $what)
                                            <option value="{{ $id }}"{!! $p['id'] === $rowProvider && (string) $id === $v['model'] ? ' selected' : '' !!}>{{ $what }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                                @if ($v['model'] !== '' && !isset($rowModels[$v['model']]))<option value="{{ $v['model'] }}" selected>{{ $v['model'] }}</option>@endif
                            </select></td>
                            <td><input type="number" step="0.1" min="0" max="2" name="{!! $n !!}[temperature]" value="{{ $v['temperature'] }}" class="{!! $c['input'] !!}"></td>
                            <td><input type="number" min="1" name="{!! $n !!}[max_tokens]" value="{{ $v['max_tokens'] }}" class="{!! $c['input'] !!}"></td>
                            <td><select name="{!! $n !!}[reasoning]" class="{!! $c['input'] !!}">
                                @foreach (['' => '—', '1' => 'on', '0' => 'off'] as $value => $label)
                                    <option value="{!! $value !!}"{!! $v['reasoning'] === (string) $value ? ' selected' : '' !!}>{!! $label !!}</option>
                                @endforeach
                            </select></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <button type="submit" class="{!! $c['btn'] !!}">Save</button>
            </form>
            <script nonce="{{ defined('PAGE_NONCE') ? PAGE_NONCE : '' }}">
            // A feature's Model offers its own provider's models — or the installation's, with no provider of its own.
            (function () {
                document.querySelectorAll('[data-pf-feature-model]').forEach(function (model) {
                    var provider = model.closest('tr').querySelector('select[name$="[provider]"]');
                    var show = function () {
                        var id = provider.value || model.getAttribute('data-default-provider');
                        model.querySelectorAll('optgroup').forEach(function (group) {
                            group.hidden = group.disabled = group.getAttribute('data-provider') !== id;
                        });
                        if (model.selectedOptions[0] && model.selectedOptions[0].parentNode.disabled) { model.value = ''; }
                    };
                    provider.addEventListener('change', show);
                    show();
                });
            })();
            </script>
        </section>
    @endif

    @foreach ($sections as $s)
        <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px" id="section-{{ $s['key'] }}">
            <h2 class="text-lg font-semibold mb-2">{{ $s['title'] }}</h2>
            @if (($s['saves'] ?? true) === false)
                {!! $s['html'] !!}
            @else
            <form method="post" action="{!! $action !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="section" value="{{ $s['key'] }}">
                {!! $s['html'] !!}
                <button type="submit" class="{!! $c['btn'] !!}">Save</button>
            </form>
            @endif
        </section>
    @endforeach

    <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">Prices</h2>
        <p class="{!! $c['muted'] !!}">USD per million tokens, per model: <code>cache_hit</code>, <code>cache_miss</code>, <code>output</code>, and optionally <code>off_peak</code> with <code>peak_utc</code>. A model not listed is not priced, and is counted apart.</p>
        <form method="post" action="{!! $action !!}">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="section" value="prices">
            <textarea name="prices" rows="14" class="{!! $c['input'] !!}" style="width:100%;font-family:monospace">{{ $this->prices ?? '' }}</textarea>
            <button type="submit" class="{!! $c['btn'] !!}">Save</button>
            <label><input type="checkbox" name="reset" value="1"> Go back to the framework's prices</label>
        </form>
    </section>

    <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">OpenAI billing</h2>
        <p class="{!! $c['muted'] !!}">An Admin key (<code>sk-admin-…</code>) reads what OpenAI billed. The balance is worked out from the top-ups recorded here, one per line: <code>2026-10-01 50</code>.</p>
        <form method="post" action="{!! $action !!}">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            <input type="hidden" name="section" value="openai">
            <label>Admin key
                <input type="password" name="admin_key" id="llm-openai-admin-key" autocomplete="off" class="{!! $c['input'] !!}"
                       placeholder="{!! $openai !== null ? 'saved — leave empty to keep it' : 'not set' !!}">
                {!! \Pramnos\Html\PasswordToggle::render('llm-openai-admin-key', '', '', 'btn btn-sm btn-ghost') !!}
            </label>
            @if ($openai !== null)<label><input type="checkbox" name="clear_key" value="1"> Remove the key</label>@endif
            <label>Project
                <input name="project" value="{{ \Pramnos\Application\Settings::getSetting(\Pramnos\Llm\Billing\OpenAiCosts::PROJECT, '') }}" placeholder="proj_…" class="{!! $c['input'] !!}">
            </label>
            <label>Top-ups
                <textarea name="topups" rows="3" class="{!! $c['input'] !!}">{{ \Pramnos\Application\Settings::getSetting(\Pramnos\Llm\Billing\OpenAiCosts::TOPUPS, '') }}</textarea>
            </label>
            <button type="submit" class="{!! $c['btn'] !!}">Save</button>
        </form>
    </section>

    <section class="{!! $c['card'] !!}" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">Calls</h2>
        <p>
            <a href="{{ adminUrl('Ai/settings') }}" class="{!! empty($this->problems) ? $c['btn'] : $c['btnOutline'] !!}">Recent</a>
            <a href="{{ adminUrl('Ai/settings') . '?show=problems' }}" class="{!! !empty($this->problems) ? $c['btn'] : $c['btnOutline'] !!}">Problems only</a>
        </p>
        @if ($byModel !== [])
            <table class="{!! $c['table'] !!}">
                <thead><tr><th>Model, 30 days</th><th>Calls</th><th>Cost</th></tr></thead>
                <tbody>
                @foreach ($byModel as $m)
                    <tr><td>{{ $m['model'] }}</td><td>{!! (int) $m['calls'] !!}</td>
                        <td>{!! $usd($m['cost']) !!}{{ (int) $m['unpriced'] > 0 ? ' + ' . $count($m['unpriced']) . ' ' . t('not priced') : '' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @if ($calls === [])
            <p class="{!! $c['muted'] !!}">{!! !empty($this->problems) ? 'No problems.' : 'No calls yet.' !!}</p>
        @else
            <table class="{!! $c['table'] !!}">
                <thead><tr><th>#</th><th>When</th><th>Purpose</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Outcome</th></tr></thead>
                <tbody>
                @foreach ($calls as $row) @php $failed = \Pramnos\Llm\CallLog::failed($row); @endphp
                    <tr>
                        <td><a href="{{ adminUrl('Ai/call/') . (int) $row['call_id'] }}">{!! (int) $row['call_id'] !!}</a></td>
                        <td>{{ $row['created_at'] ?? '' }}</td>
                        <td>{{ $row['purpose'] ?? '' }}</td>
                        <td>{{ ($labels[$row['provider'] ?? ''] ?? ($row['provider'] ?? '')) . ' · ' . ($row['model'] ?? '') }}</td>
                        <td>{{ $row['tokens'] === null ? '—' : $count($row['tokens']) }}</td>
                        <td>{!! $usd($row['cost'] ?? null) !!}</td>
                        <td class="{!! $failed ? $c['bad'] : $c['ok'] !!}">{!! $failed ? $e($row['error'] ?? ('HTTP ' . ($row['http_status'] ?? '—'))) : $e($row['finish_reason'] ?? 'ok') !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
