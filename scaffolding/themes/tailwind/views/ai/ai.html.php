<?php
/**
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
 *   $this->sections   — the application's own sections: key, title, html
 *   $this->canEncrypt — whether keys are stored encrypted
 *
 * A key is never printed back: its field is empty with a placeholder saying whether one is
 * saved, and an empty submit keeps it. Every cost is an estimate from the prices below, and says
 * so; the provider's own balance, where it publishes one, is shown beside it.
 */
$c = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'ok' => 'text-success', 'bad' => 'text-error', 'btn' => 'btn btn-sm btn-primary', 'btnOutline' => 'btn btn-sm btn-ghost', 'input' => 'input input-sm input-bordered', 'table' => 'table table-sm'];
$e         = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$usd       = static fn ($v): string => $v === null ? '—' : '$' . number_format((float) $v, 4);
$providers = is_array($this->providers ?? null) ? $this->providers : [];
$features  = is_array($this->features ?? null) ? $this->features : [];
$calls     = is_array($this->calls ?? null) ? $this->calls : [];
$byModel   = is_array($this->byModel ?? null) ? $this->byModel : [];
$sections  = is_array($this->sections ?? null) ? $this->sections : [];
$day       = is_array($this->day ?? null) ? $this->day : [];
$month     = is_array($this->month ?? null) ? $this->month : [];
$openai    = is_array($this->openai ?? null) ? $this->openai : null;
$selected  = (string) ($this->selected ?? '');
$action    = $e(adminUrl('Ai/save'));
$labels    = array_column($providers, 'label', 'id');
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'ai'; $this->insert('../partials/admin_breadcrumb'); ?>

    <h1 class="text-2xl font-semibold mb-2">AI</h1>
    <p class="<?php echo $c['muted']; ?>">
        The language models this installation calls, what each of its features uses, and what it has cost.
        Costs are estimates from the prices below<?php echo $openai !== null ? '; OpenAI\'s own figure is beside them' : ''; ?>.
    </p>

    <?php if (!($this->canEncrypt ?? true)): ?>
        <p class="<?php echo $c['bad']; ?>">APP_KEY is not set, so keys saved here are stored unencrypted. Run <code>key:generate</code>.</p>
    <?php endif; ?>

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin:16px 0">
        <?php foreach (['Last 24 hours' => $day, 'Last 30 days' => $month] as $title => $sum): ?>
            <div class="<?php echo $c['card']; ?>" style="padding:10px 14px;min-width:220px">
                <div class="<?php echo $c['muted']; ?>"><?php echo $e($title); ?></div>
                <div style="font-size:1.6em"><?php echo $usd($sum['cost'] ?? 0); ?></div>
                <div class="<?php echo $c['muted']; ?>">
                    <?php echo (int) ($sum['calls'] ?? 0); ?> calls ·
                    <?php echo number_format((int) ($sum['tokens'] ?? 0)); ?> tokens ·
                    <span class="<?php echo (int) ($sum['failed'] ?? 0) > 0 ? $c['bad'] : ''; ?>"><?php echo (int) ($sum['failed'] ?? 0); ?> failed</span>
                    <?php if ((int) ($sum['unpriced'] ?? 0) > 0): ?>
                        · <?php echo (int) $sum['unpriced']; ?> not priced, not in the total
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($openai !== null): ?>
            <div class="<?php echo $c['card']; ?>" style="padding:10px 14px;min-width:220px">
                <div class="<?php echo $c['muted']; ?>">OpenAI says, this month</div>
                <?php if (!empty($openai['ok'])): ?>
                    <div style="font-size:1.6em"><?php echo $usd($openai['month_usd'] ?? null); ?></div>
                    <div class="<?php echo $c['muted']; ?>">
                        our log: <?php echo $usd($openai['meter_month_usd'] ?? null); ?>
                        <?php if (($openai['balance_usd'] ?? null) !== null): ?> · left: <?php echo $usd($openai['balance_usd']); ?><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="<?php echo $c['bad']; ?>"><?php echo $e($openai['says'] ?? ''); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">General</h2>
        <form method="post" action="<?php echo $action; ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="section" value="general">
            <label>Provider
                <select name="provider" class="<?php echo $c['input']; ?>">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?php echo $e($p['id']); ?>"<?php echo $p['id'] === $selected ? ' selected' : ''; ?>><?php echo $e($p['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Timeout, seconds
                <input type="number" name="timeout" min="0" max="300" value="<?php echo (int) ($this->timeout ?? 0) ?: ''; ?>" placeholder="60" class="<?php echo $c['input']; ?>">
            </label>
            <label><input type="checkbox" name="log" value="1"<?php echo !empty($this->logging) ? ' checked' : ''; ?>> Log every call</label>
            <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
        </form>
    </section>

    <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px" id="keys">
        <h2 class="text-lg font-semibold mb-2">API keys</h2>
        <p class="<?php echo $c['muted']; ?>">
            Saved encrypted, and never shown again: an empty field keeps the key a provider has.
            <?php foreach ($providers as $p): ?>
                <?php echo $e($p['label']); ?>: <span class="<?php echo $p['configured'] ? $c['ok'] : $c['muted']; ?>"><?php echo $p['configured'] ? 'saved' : 'none'; ?></span><?php if ($p['api_key_url'] !== ''): ?> (<a href="<?php echo $e($p['api_key_url']); ?>" target="_blank" rel="noopener">get one</a>)<?php endif; ?>.
            <?php endforeach; ?>
        </p>
        <form method="post" action="<?php echo $action; ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="section" value="key">
            <label>Provider
                <select name="provider" class="<?php echo $c['input']; ?>">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?php echo $e($p['id']); ?>"<?php echo $p['id'] === $selected ? ' selected' : ''; ?>><?php echo $e($p['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Key
                <input type="password" name="api_key" id="llm-api-key" autocomplete="off" class="<?php echo $c['input']; ?>">
                <?php echo \Pramnos\Html\PasswordToggle::render('llm-api-key', '', '', 'btn btn-sm btn-ghost'); ?>
            </label>
            <label><input type="checkbox" name="clear_key" value="1"> Remove this provider's key</label>
            <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
        </form>
    </section>

    <?php foreach ($providers as $p): ?>
        <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px" id="provider-<?php echo $e($p['id']); ?>">
            <h2 class="text-lg font-semibold mb-2">
                <?php echo $e($p['label']); ?>
                <span class="<?php echo $p['configured'] ? $c['ok'] : $c['muted']; ?>"><?php echo $p['configured'] ? 'key saved' : 'no key'; ?></span>
                <?php if ($p['id'] === $selected): ?><span class="<?php echo $c['muted']; ?>">· in use</span><?php endif; ?>
            </h2>
            <?php if ($p['consent']): ?>
                <p class="<?php echo $c['muted']; ?>">Established in <?php echo $e($p['place'] ?: 'a country without an adequacy decision'); ?>: data may need agreement before it is sent.</p>
            <?php endif; ?>
            <?php if (is_array($p['balance'] ?? null)): ?>
                <p class="<?php echo $c['muted']; ?>">Balance:
                    <?php foreach ((array) ($p['balance']['balance_infos'] ?? []) as $info): ?>
                        <?php echo $e(($info['total_balance'] ?? '') . ' ' . ($info['currency'] ?? '')); ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo $action; ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="section" value="provider">
                <input type="hidden" name="provider" value="<?php echo $e($p['id']); ?>">
                <label>Model
                    <select name="model" class="<?php echo $c['input']; ?>">
                        <?php foreach ((array) $p['models'] as $id => $what): ?>
                            <option value="<?php echo $e($id); ?>"<?php echo (string) $id === $p['model'] ? ' selected' : ''; ?>><?php echo $e($what); ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset($p['models'][$p['model']])): ?><option value="<?php echo $e($p['model']); ?>" selected><?php echo $e($p['model']); ?></option><?php endif; ?>
                    </select>
                </label>
                <label>Base URL
                    <input type="url" name="base_url" value="<?php echo $e($p['base_url']); ?>" placeholder="<?php echo $e($p['default_url']); ?>" class="<?php echo $c['input']; ?>">
                </label>
                <label>Temperature
                    <input type="number" name="temperature" step="0.1" min="0" max="2" value="<?php echo $e($p['temperature']); ?>" placeholder="0.7" class="<?php echo $c['input']; ?>">
                </label>
                <label>Output budget, tokens
                    <input type="number" name="max_tokens" min="1" value="<?php echo $e($p['max_tokens']); ?>" placeholder="2000" class="<?php echo $c['input']; ?>">
                </label>
                <label><input type="checkbox" name="reasoning" value="1"<?php echo $p['reasoning'] ? ' checked' : ''; ?>> Let the model reason first (billed)</label>
                <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
            </form>
            <form method="post" action="<?php echo $e(adminUrl('Ai/check')); ?>" style="margin-top:8px">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="provider" value="<?php echo $e($p['id']); ?>">
                <button type="submit" class="<?php echo $c['btnOutline']; ?>"<?php echo $p['configured'] ? '' : ' disabled'; ?>>Test the key</button>
            </form>
        </section>
    <?php endforeach; ?>

    <?php if ($features !== []): ?>
        <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px">
            <h2 class="text-lg font-semibold mb-2">Features</h2>
            <p class="<?php echo $c['muted']; ?>">What each part of the application uses. Empty follows the provider's own settings.</p>
            <form method="post" action="<?php echo $action; ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="section" value="features">
                <table class="<?php echo $c['table']; ?>">
                    <thead><tr><th>Feature</th><th>Provider</th><th>Model</th><th>Temperature</th><th>Budget</th><th>Reasoning</th></tr></thead>
                    <tbody>
                    <?php foreach ($features as $f): $n = 'features[' . $e($f['name']) . ']'; $v = $f['values']; ?>
                        <tr>
                            <td><?php echo $e($f['label']); ?></td>
                            <td><select name="<?php echo $n; ?>[provider]" class="<?php echo $c['input']; ?>">
                                <option value="">—</option>
                                <?php foreach ($labels as $id => $label): ?>
                                    <option value="<?php echo $e($id); ?>"<?php echo $v['provider'] === (string) $id ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                            <td><input name="<?php echo $n; ?>[model]" value="<?php echo $e($v['model']); ?>" class="<?php echo $c['input']; ?>"></td>
                            <td><input type="number" step="0.1" min="0" max="2" name="<?php echo $n; ?>[temperature]" value="<?php echo $e($v['temperature']); ?>" class="<?php echo $c['input']; ?>"></td>
                            <td><input type="number" min="1" name="<?php echo $n; ?>[max_tokens]" value="<?php echo $e($v['max_tokens']); ?>" class="<?php echo $c['input']; ?>"></td>
                            <td><select name="<?php echo $n; ?>[reasoning]" class="<?php echo $c['input']; ?>">
                                <?php foreach (['' => '—', '1' => 'on', '0' => 'off'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"<?php echo $v['reasoning'] === (string) $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
            </form>
        </section>
    <?php endif; ?>

    <?php foreach ($sections as $s): ?>
        <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px" id="section-<?php echo $e($s['key']); ?>">
            <h2 class="text-lg font-semibold mb-2"><?php echo $e($s['title']); ?></h2>
            <form method="post" action="<?php echo $action; ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="section" value="<?php echo $e($s['key']); ?>">
                <?php echo $s['html']; ?>
                <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
            </form>
        </section>
    <?php endforeach; ?>

    <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">Prices</h2>
        <p class="<?php echo $c['muted']; ?>">USD per million tokens, per model: <code>cache_hit</code>, <code>cache_miss</code>, <code>output</code>, and optionally <code>off_peak</code> with <code>peak_utc</code>. A model not listed is not priced, and is counted apart.</p>
        <form method="post" action="<?php echo $action; ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="section" value="prices">
            <textarea name="prices" rows="14" class="<?php echo $c['input']; ?>" style="width:100%;font-family:monospace"><?php echo $e($this->prices ?? ''); ?></textarea>
            <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
            <label><input type="checkbox" name="reset" value="1"> Go back to the framework's prices</label>
        </form>
    </section>

    <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">OpenAI billing</h2>
        <p class="<?php echo $c['muted']; ?>">An Admin key (<code>sk-admin-…</code>) reads what OpenAI billed. The balance is worked out from the top-ups recorded here, one per line: <code>2026-10-01 50</code>.</p>
        <form method="post" action="<?php echo $action; ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="section" value="openai">
            <label>Admin key
                <input type="password" name="admin_key" id="llm-openai-admin-key" autocomplete="off" class="<?php echo $c['input']; ?>"
                       placeholder="<?php echo $openai !== null ? 'saved — leave empty to keep it' : 'not set'; ?>">
                <?php echo \Pramnos\Html\PasswordToggle::render('llm-openai-admin-key', '', '', 'btn btn-sm btn-ghost'); ?>
            </label>
            <?php if ($openai !== null): ?><label><input type="checkbox" name="clear_key" value="1"> Remove the key</label><?php endif; ?>
            <label>Project
                <input name="project" value="<?php echo $e(\Pramnos\Application\Settings::getSetting(\Pramnos\Llm\Billing\OpenAiCosts::PROJECT, '')); ?>" placeholder="proj_…" class="<?php echo $c['input']; ?>">
            </label>
            <label>Top-ups
                <textarea name="topups" rows="3" class="<?php echo $c['input']; ?>"><?php echo $e(\Pramnos\Application\Settings::getSetting(\Pramnos\Llm\Billing\OpenAiCosts::TOPUPS, '')); ?></textarea>
            </label>
            <button type="submit" class="<?php echo $c['btn']; ?>">Save</button>
        </form>
    </section>

    <section class="<?php echo $c['card']; ?>" style="padding:14px;margin-bottom:16px">
        <h2 class="text-lg font-semibold mb-2">Calls</h2>
        <p>
            <a href="<?php echo $e(adminUrl('Ai')); ?>" class="<?php echo empty($this->problems) ? $c['btn'] : $c['btnOutline']; ?>">Recent</a>
            <a href="<?php echo $e(adminUrl('Ai') . '?show=problems'); ?>" class="<?php echo !empty($this->problems) ? $c['btn'] : $c['btnOutline']; ?>">Problems only</a>
        </p>
        <?php if ($byModel !== []): ?>
            <table class="<?php echo $c['table']; ?>">
                <thead><tr><th>Model, 30 days</th><th>Calls</th><th>Cost</th></tr></thead>
                <tbody>
                <?php foreach ($byModel as $m): ?>
                    <tr><td><?php echo $e($m['model']); ?></td><td><?php echo (int) $m['calls']; ?></td>
                        <td><?php echo $usd($m['cost']); ?><?php echo (int) $m['unpriced'] > 0 ? ' + ' . (int) $m['unpriced'] . ' not priced' : ''; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($calls === []): ?>
            <p class="<?php echo $c['muted']; ?>"><?php echo !empty($this->problems) ? 'No problems.' : 'No calls yet.'; ?></p>
        <?php else: ?>
            <table class="<?php echo $c['table']; ?>">
                <thead><tr><th>#</th><th>When</th><th>Purpose</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Outcome</th></tr></thead>
                <tbody>
                <?php foreach ($calls as $row): $failed = \Pramnos\Llm\CallLog::failed($row); ?>
                    <tr>
                        <td><a href="<?php echo $e(adminUrl('Ai/call/') . (int) $row['call_id']); ?>"><?php echo (int) $row['call_id']; ?></a></td>
                        <td><?php echo $e($row['created_at'] ?? ''); ?></td>
                        <td><?php echo $e($row['purpose'] ?? ''); ?></td>
                        <td><?php echo $e(($labels[$row['provider'] ?? ''] ?? ($row['provider'] ?? '')) . ' · ' . ($row['model'] ?? '')); ?></td>
                        <td><?php echo $row['tokens'] === null ? '—' : number_format((int) $row['tokens']); ?></td>
                        <td><?php echo $usd($row['cost'] ?? null); ?></td>
                        <td class="<?php echo $failed ? $c['bad'] : $c['ok']; ?>"><?php echo $failed ? $e($row['error'] ?? ('HTTP ' . ($row['http_status'] ?? '—'))) : $e($row['finish_reason'] ?? 'ok'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
