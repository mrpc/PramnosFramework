<?php
/**
 * Conversations with a model (Bootstrap theme).
 *
 * Variables:
 *   $this->conversations — this person's, latest first: conversation_id, title, updated_at
 *   $this->conversation  — the open one, or null: title, messages (role, content, tools, …)
 *   $this->ask           — a question being answered, or null: status (waiting, answered, failed), says
 *   $this->memories      — what it remembers: memory_id, kind, content
 *   $this->limits        — provider, model, turns, rounds, memory
 *   $this->consent       — provider, label, place, required, given
 *   $this->urls          — optional: page, ask, asked, consent, delete, forget (the analyst's)
 *   $this->heading       — optional: the title when no conversation is open
 *
 * Answers are the model's words, drawn by Markdown::toHtml(), which escapes everything first.
 * A question is answered by the queue worker; pf-chat.js waits for it in place, and without
 * JavaScript the page refreshes itself until it is answered.
 */
$c       = ['card' => 'card', 'muted' => 'text-muted small', 'bad' => 'text-danger', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-link', 'input' => 'form-control', 'question' => 'card bg-light', 'answer' => 'card'];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$list    = is_array($this->conversations ?? null) ? $this->conversations : [];
$open    = is_array($this->conversation ?? null) ? $this->conversation : null;
$ask     = is_array($this->ask ?? null) ? $this->ask : null;
$limits  = is_array($this->limits ?? null) ? $this->limits : [];
$consent = is_array($this->consent ?? null) ? $this->consent : ['required' => false, 'given' => true];
$memory  = is_array($this->memories ?? null) ? $this->memories : [];
$openId  = (int) ($open['conversation_id'] ?? 0);
$token   = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$url     = static fn (string $path): string => sURL . $path;
$waiting = $ask !== null && ($ask['status'] ?? '') === 'waiting';
// Where each form goes: /chat by default, the analyst's own under /admin/Ai.
$u       = (is_array($this->urls ?? null) ? $this->urls : []) + [
    'page' => $url('chat'), 'ask' => $url('chat/ask'), 'asked' => $url('chat/asked/'), 'consent' => $url('chat/consent'),
    'delete' => $url('chat/delete/'), 'forget' => $url('chat/forget/'),
];
?>
<?php if ($waiting): ?><noscript><meta http-equiv="refresh" content="4"></noscript><?php endif; ?>
<div class="container-fluid py-3" data-pf-chat data-page="<?php echo $e($u['page']); ?>" data-asked="<?php echo $e($u['asked']); ?>">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="<?php echo $c['card']; ?>" style="flex:0 0 240px;padding:12px" aria-label="<?php echo $e(t('Conversations')); ?>">
            <a href="<?php echo $e($u['page']); ?>" class="<?php echo $c['btn']; ?>"><?php echo $e(t('New conversation')); ?></a>
            <ul style="list-style:none;padding:0;margin:12px 0 0">
                <?php if ($list === []): ?>
                    <li class="<?php echo $c['muted']; ?>"><?php echo $e(t('Nothing asked yet.')); ?></li>
                <?php endif; ?>
                <?php foreach ($list as $one): ?>
                    <li><a href="<?php echo $e($u['page'] . '?c=' . (int) $one['conversation_id']); ?>"<?php echo (int) $one['conversation_id'] === $openId ? ' aria-current="page" style="font-weight:600"' : ''; ?>><?php echo $e($one['title']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <main style="flex:1 1 420px;min-width:0">
            <h1 class="h3"><?php echo $e($open['title'] ?? ($this->heading ?? t('Ask AI'))); ?></h1>

            <?php if (!empty($consent['required']) && empty($consent['given'])): ?>
                <section class="<?php echo $c['card']; ?>" style="padding:12px;margin-bottom:12px">
                    <p><?php echo $e(sprintf(t('The AI features use %s%s. Nothing is sent until you agree.'), $consent['label'], ($consent['place'] ?? '') !== '' ? ', ' . t('established in') . ' ' . $consent['place'] : '')); ?></p>
                    <form method="post" action="<?php echo $e($u['consent']); ?>">
                        <?php echo $token(); ?>
                        <input type="hidden" name="give" value="1">
                        <input type="hidden" name="conversation_id" value="<?php echo $openId; ?>">
                        <button type="submit" class="<?php echo $c['btn']; ?>"><?php echo $e(t('Agree')); ?></button>
                    </form>
                </section>
            <?php endif; ?>

            <div class="pf-chat-messages">
                <?php foreach ($open['messages'] ?? [] as $m): ?>
                    <article class="<?php echo $m['role'] === 'user' ? $c['question'] : $c['answer']; ?>" style="padding:10px 14px;margin-bottom:10px">
                        <?php if ($m['role'] === 'user'): ?>
                            <div style="white-space:pre-wrap"><?php echo $e($m['content']); ?></div>
                        <?php else: ?>
                            <div class="pf-chat-answer"><?php echo \Pramnos\Llm\Chat\Markdown::toHtml((string) $m['content']); ?></div>
                            <?php $tools = array_unique(array_column((array) ($m['tools'] ?? []), 'name')); ?>
                            <p class="<?php echo $c['muted']; ?>"><?php echo $tools === [] ? $e(t('No data was read for this answer')) : $e(implode(' · ', $tools)); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <?php if ($waiting): ?>
                    <p class="<?php echo $c['muted']; ?>" data-pf-chat-waiting="<?php echo (int) $ask['ask_id']; ?>" role="status"><?php echo $e(t('Thinking')); ?>…</p>
                <?php elseif ($ask !== null && ($ask['status'] ?? '') === 'failed'): ?>
                    <p class="<?php echo $c['bad']; ?>" role="alert"><?php echo $e($ask['says'] ?? ''); ?></p>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo $e($u['ask']); ?>" enctype="multipart/form-data" class="<?php echo $c['card']; ?>" style="padding:12px" data-pf-chat-form>
                <?php echo $token(); ?>
                <input type="hidden" name="conversation_id" value="<?php echo $openId; ?>">
                <label for="chat-ask" class="pf-sr-only"><?php echo $e(t('Your question')); ?></label>
                <textarea id="chat-ask" name="content" rows="3" class="<?php echo $c['input']; ?>" style="width:100%" placeholder="<?php echo $e(t('Ask a question…')); ?>"></textarea>
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px">
                    <label class="<?php echo $c['muted']; ?>"><?php echo $e(t('Attach a document')); ?>
                        <input type="file" name="files[]" multiple accept=".docx,.odt,.txt,.md,.eml,image/*">
                    </label>
                    <span class="<?php echo $c['muted']; ?>" style="margin-left:auto"><?php echo $e(t('Enter to send · Shift+Enter for a new line')); ?></span>
                    <button type="submit" class="<?php echo $c['btn']; ?>"<?php echo !empty($consent['required']) && empty($consent['given']) ? ' disabled' : ''; ?>><?php echo $e(t('Ask')); ?></button>
                </div>
            </form>

            <?php if ($open !== null): ?>
                <form method="post" action="<?php echo $e($u['delete'] . $openId); ?>" style="margin-top:8px">
                    <?php echo $token(); ?>
                    <button type="submit" class="<?php echo $c['btnGhost']; ?>" data-confirm="<?php echo $e(t('Delete this conversation?') . ' ' . t('Every question and answer in it goes. This cannot be undone.')); ?>"><?php echo $e(t('Delete this conversation?')); ?></button>
                </form>
            <?php endif; ?>
        </main>

        <aside class="<?php echo $c['card']; ?>" style="flex:0 0 260px;padding:12px">
            <h2 class="h6 mt-3"><?php echo $e(t('Model')); ?></h2>
            <p class="<?php echo $c['muted']; ?>"><?php echo $e(($limits['provider'] ?? '') . ' · ' . ($limits['model'] ?? '')); ?></p>
            <h2 class="h6 mt-3"><?php echo $e(t('Limits')); ?></h2>
            <ul class="<?php echo $c['muted']; ?>">
                <li><?php echo $e(sprintf(t('The last %d messages are sent with each question'), (int) ($limits['turns'] ?? 0))); ?></li>
                <li><?php echo $e(sprintf(t('Up to %d data reads per answer'), (int) ($limits['rounds'] ?? 0))); ?></li>
                <li><?php echo $e(sprintf(t('Up to %d things remembered'), (int) ($limits['memory'] ?? 0))); ?></li>
            </ul>
            <h2 class="h6 mt-3"><?php echo $e(sprintf(t('What it remembers (%d)'), count($memory))); ?></h2>
            <?php if ($memory === []): ?>
                <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('Nothing yet.')); ?></p>
            <?php endif; ?>
            <ul style="list-style:none;padding:0">
                <?php foreach ($memory as $one): ?>
                    <li style="margin-bottom:6px">
                        <?php echo $e($one['content']); ?> <span class="<?php echo $c['muted']; ?>"><?php echo $e(t($one['kind'])); ?></span>
                        <form method="post" action="<?php echo $e($u['forget'] . (int) $one['memory_id']); ?>" style="display:inline">
                            <?php echo $token(); ?>
                            <input type="hidden" name="conversation_id" value="<?php echo $openId; ?>">
                            <button type="submit" class="<?php echo $c['btnGhost']; ?>" aria-label="<?php echo $e(t('Forget')); ?>">✕</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($consent['required']) && !empty($consent['given'])): ?>
                <h2 class="h6 mt-3"><?php echo $e(t('Your agreement')); ?></h2>
                <p class="<?php echo $c['muted']; ?>"><?php echo $e(sprintf(t('You agreed to the AI features using %s.'), $consent['label'])); ?></p>
                <form method="post" action="<?php echo $e($u['consent']); ?>">
                    <?php echo $token(); ?>
                    <button type="submit" class="<?php echo $c['btnGhost']; ?>"><?php echo $e(t('Withdraw — send nothing more')); ?></button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
</div>
<script src="<?php echo $e(assetUrl('assets/js/pf-chat.js')); ?>" defer></script>
