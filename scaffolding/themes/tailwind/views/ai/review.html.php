<?php
/**
 * The other administrators' Ask conversations, read only — `/admin/Ai/all` (Tailwind theme).
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
$c       = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'field' => 'input input-bordered input-sm', 'select' => 'select select-bordered select-sm', 'question' => 'rounded-2xl bg-base-200', 'banner' => 'alert alert-warning'];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$list    = is_array($this->list ?? null) ? $this->list : [];
$owners  = is_array($this->owners ?? null) ? $this->owners : [];
$filters = (is_array($this->filters ?? null) ? $this->filters : []) + ['person' => 0, 'q' => ''];
$open    = is_array($this->open ?? null) ? $this->open : null;
$u       = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'call' => '', 'ask' => ''];
$openId  = (int) ($open['conversation']['conversation_id'] ?? 0);
$money   = static fn ($v): string => $v === null ? '' : '$' . number_format((float) $v, 4);
$keep    = static fn (array $extra): string => http_build_query(array_filter(['person' => (int) $filters['person'], 'q' => (string) $filters['q']] + $extra));
?>
<?php // pf-chat.js draws the answers' charts and tables' copy buttons, as on Ask's page; with no form here it asks nothing. ?>
<div class="px-4 py-6" data-pf-chat>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="<?php echo $c['card']; ?>" style="flex:0 0 300px;padding:12px" aria-label="<?php echo $e(t('Other people\'s Ask conversations')); ?>">
            <a href="<?php echo $e($u['ask']); ?>" class="<?php echo $c['btnGhost']; ?>">‹ <?php echo $e(t('Ask')); ?></a>
            <form method="get" action="<?php echo $e($u['all']); ?>" style="display:flex;flex-direction:column;gap:6px;margin:10px 0">
                <label class="<?php echo $c['muted']; ?>" for="review-person"><?php echo $e(t('Whose conversations')); ?></label>
                <select id="review-person" name="person" class="<?php echo $c['select']; ?>">
                    <option value="0"><?php echo $e(t('Everybody')); ?></option>
                    <?php foreach ($owners as $id => $name): ?>
                        <option value="<?php echo (int) $id; ?>"<?php echo (int) $id === (int) $filters['person'] ? ' selected' : ''; ?>><?php echo $e($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="<?php echo $c['muted']; ?>" for="review-q"><?php echo $e(t('A word in the title')); ?></label>
                <input id="review-q" type="search" name="q" value="<?php echo $e($filters['q']); ?>" class="<?php echo $c['field']; ?>">
                <button type="submit" class="<?php echo $c['btn']; ?>"><?php echo $e(t('Filter the conversations')); ?></button>
            </form>
            <ul style="list-style:none;padding:0;margin:0">
                <?php if ($list === []): ?>
                    <li class="<?php echo $c['muted']; ?>"><?php echo $e(t('No conversations.')); ?></li>
                <?php endif; ?>
                <?php foreach ($list as $one): ?>
                    <li style="margin-bottom:8px">
                        <a href="<?php echo $e($u['all'] . '?' . $keep(['c' => (int) $one['conversation_id']])); ?>"<?php echo (int) $one['conversation_id'] === $openId ? ' aria-current="page" style="font-weight:600"' : ''; ?>><?php echo $e($one['title']); ?></a>
                        <div class="<?php echo $c['muted']; ?>"><?php echo $e($one['owner_name'] . ' · ' . $one['updated_at'] . ' · ' . sprintf(t('%d messages'), (int) $one['messages']) . ' · ' . $money($one['cost'])); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <main style="flex:1 1 420px;min-width:0">
            <?php if ($open === null): ?>
                <h1 class="text-2xl font-semibold mb-3"><?php echo $e(t('Other people\'s Ask conversations')); ?></h1>
                <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('Pick a conversation to read it as its owner sees it. Opening somebody else\'s is recorded in the audit log.')); ?></p>
            <?php else: ?>
                <p class="<?php echo $c['banner']; ?>" role="note" style="padding:8px 12px"><?php echo $e(sprintf(t('%s\'s conversation — read only'), $open['owner_name'])); ?></p>
                <h1 class="text-2xl font-semibold mb-3"><?php echo $e($open['conversation']['title']); ?></h1>
                <?php foreach ($open['conversation']['messages'] as $m): ?>
                    <?php $mine = $m['role'] === 'user'; ?>
                    <article style="display:flex;flex-direction:column;align-items:<?php echo $mine ? 'flex-end' : 'flex-start'; ?>;margin-bottom:16px">
                        <header class="<?php echo $c['muted']; ?>" style="margin-bottom:4px"><strong><?php echo $e($mine ? $open['owner_name'] : t('Analyst')); ?></strong> · <?php echo $e($m['created_at']); ?><?php if (($m['cost'] ?? null) !== null): ?> · <?php echo $e($money($m['cost'])); ?><?php endif; ?></header>
                        <div class="<?php echo $mine ? $c['question'] : ''; ?>" style="<?php echo $mine ? 'padding:10px 14px;max-width:80%;white-space:pre-wrap' : 'width:100%'; ?>">
                            <?php if ($mine): ?>
                                <?php echo $e($m['content']); ?>
                            <?php else: ?>
                                <div class="pf-chat-answer"><?php echo \Pramnos\Llm\Chat\Markdown::toHtml((string) $m['content']); ?></div>
                                <?php $steps = array_values(array_filter((array) (($m['extra'] ?? [])['steps'] ?? []), 'is_string')); ?>
                                <?php if ($steps !== []): ?>
                                    <details class="<?php echo $c['muted']; ?>">
                                        <summary><?php echo $e(t('What it did on the way')); ?></summary>
                                        <?php foreach ($steps as $step): ?><div><?php echo \Pramnos\Llm\Chat\Markdown::toHtml($step); ?></div><?php endforeach; ?>
                                    </details>
                                <?php endif; ?>
                                <?php $tools = (array) ($m['tools'] ?? []); ?>
                                <?php if ($tools !== []): ?>
                                    <details class="<?php echo $c['muted']; ?>">
                                        <summary><?php echo $e(sprintf(t('Tools it used (%d)'), count($tools))); ?></summary>
                                        <ul>
                                            <?php foreach ($tools as $tool): ?>
                                                <li><code><?php echo $e($tool['name'] ?? ''); ?></code> <pre style="white-space:pre-wrap;margin:2px 0 6px"><?php echo $e(json_encode($tool['arguments'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                                <?php if (($m['calls'] ?? []) !== []): ?>
                                    <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('The model calls, with what each tool returned:')); ?>
                                        <?php foreach ($m['calls'] as $call): ?><a href="<?php echo $e($u['call'] . (int) $call); ?>">#<?php echo (int) $call; ?></a> <?php endforeach; ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>
<script src="<?php echo $e(assetUrl('assets/js/pf-chat.js')); ?>" defer></script>
