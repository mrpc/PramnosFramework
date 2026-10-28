<?php
/**
 * Conversations with a model (Tailwind theme).
 *
 * Variables:
 *   $this->conversations — this person's, latest first: conversation_id, title, updated_at
 *   $this->conversation  — the open one, or null: title, messages (role, content, tools, …)
 *   $this->ask           — a question being answered, or null: status (waiting, answered, failed), says
 *   $this->memories      — what it remembers: memory_id, kind, content, source (`learned` when picked up after an answer)
 *   $this->limits        — provider, model, turns, rounds, memory
 *   $this->consent       — provider, label, place, required, given
 *   $this->urls          — optional: page, ask, asked, consent, delete, forget, scheduled, all (the analyst's), share, unshare, shared
 *   $this->sharedWithMe  — optional: what others shared with the reader (Shares::withMe())
 *   $this->shares        — optional: who the open conversation is shared with (Shares::sharesOf())
 *   $this->bulkActions   — optional: by a list's kind of record, the bulk actions it offers (BulkActions::available())
 *   $this->pinned        — optional: the ids of the artifacts the reader pinned to their dashboard
 *   $this->heading       — optional: the title when no conversation is open
 *   $this->headingAvatar — optional: HTML of the picture beside that title (the analyst's, Assist\AvatarSets::render())
 *   $this->suggestions   — optional: example questions, shown while no conversation is open
 *   $this->assistantName — optional: the name over an answer, "Assistant" by default
 *   $this->greeting      — optional: the line over the empty page's composer
 *   $this->placeholder   — optional: the composer's placeholder
 *   $this->accent        — optional: the page's accent colour, over the theme's primary
 *   $this->commands      — optional: the application's own `/` commands (ChatLook::commands())
 *
 * Answers are the model's words, drawn by Markdown::toHtml(), which escapes everything first.
 * A question is answered by the queue worker; pf-chat.js waits for it in place, and without
 * JavaScript the page refreshes itself until it is answered.
 */
$c       = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'bad' => 'text-error', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'input' => 'textarea textarea-bordered', 'question' => 'rounded-2xl bg-base-200', 'answer' => 'prose max-w-none'];
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
// What the script says, in the page's language.
$say     = [
    'thinking' => t('Thinking'), 'failed' => t('The AI could not answer.'),
    'long' => t('Still working on a long answer — it will appear here.'), 'remove' => t('Remove'),
    'unsupported' => t('Attach a picture, or a Word (.docx), OpenDocument (.odt), text, Markdown or saved email (.eml) file.'),
    'too-many' => t('Up to 10 files with one question.'), 'too-large' => t('That is too large — up to 5 MB, all the files together.'),
    'you' => t('You'), 'today' => t('today'), 'copied' => t('Copied'), 'copy-table' => t('Copy table'),
];
$name    = (string) ($this->assistantName ?? '') !== '' ? (string) $this->assistantName : t('Assistant');
$sayAttr = '';
foreach ($say as $key => $words) {
    $sayAttr .= ' data-say-' . $key . '="' . $e($words) . '"';
}
$lines   = is_array($this->waitingLines ?? null) ? array_values($this->waitingLines) : [];
// How full the model's view of this conversation was on the last answer.
$context = 0;
foreach ($open['messages'] ?? [] as $m) {
    if ($m['role'] === 'assistant' && !empty($m['context'])) {
        $context = (int) $m['context'];
    }
}
$window  = (int) ($limits['context_window'] ?? 0);
// Where each form goes: /chat by default, the analyst's own under /admin/Ai.
$u       = (is_array($this->urls ?? null) ? $this->urls : []) + [
    'page' => $url('chat'), 'ask' => $url('chat/ask'), 'asked' => $url('chat/asked/'), 'consent' => $url('chat/consent'),
    'delete' => $url('chat/delete/'), 'forget' => $url('chat/forget/'),
    // The scheduled tasks, for the conversations page itself; a page that names its own addresses
    // (the analyst's) names this one too, or has none.
    'scheduled' => is_array($this->urls ?? null) ? '' : $url('chat/scheduled'),
    // Other people's Ask conversations, read only — the analyst's, for whoever reads them all.
    'all' => '',
    // Sharing a conversation or an artifact; a page that names its own addresses names these too, or has none.
    'share' => is_array($this->urls ?? null) ? '' : $url('chat/share/'),
    'unshare' => is_array($this->urls ?? null) ? '' : $url('chat/unshare/'),
    'shared' => is_array($this->urls ?? null) ? '' : $url('chat/shared/'),
    // Bulk actions on a list's records (Pramnos\Bulk\BulkActions).
    'act' => is_array($this->urls ?? null) ? '' : $url('chat/act/'),
    // A change the model proposed, approved or rejected by the reader (Llm\Chat\Proposals).
    'approve' => is_array($this->urls ?? null) ? '' : $url('chat/approve/'),
    'reject' => is_array($this->urls ?? null) ? '' : $url('chat/reject/'),
    // Good or bad, under each answer; and the queue of bad ones, for whoever reads everybody's.
    'rate' => is_array($this->urls ?? null) ? '' : $url('chat/rate/'),
    'reviews' => '',
    // An artifact's CSV, its page to print or save as PDF, and pinning it to the dashboard.
    'download' => is_array($this->urls ?? null) ? '' : $url('chat/download/'),
    'print' => is_array($this->urls ?? null) ? '' : $url('chat/printable/'),
    'pin' => is_array($this->urls ?? null) ? '' : $url('chat/pin/'),
];
$pinned  = is_array($this->pinned ?? null) ? $this->pinned : [];
$bulk    = is_array($this->bulkActions ?? null) ? $this->bulkActions : [];
// Empty: a greeting over one large composer, the examples under it.
$empty   = $open === null && !$waiting;
$heading = (string) ($this->heading ?? '');
$greeting = (string) ($this->greeting ?? '') !== '' ? (string) $this->greeting
    : ($heading !== '' ? sprintf(t('Hi, I am %s. How can I help?'), $heading) : t('How can I help?'));
$accent  = \Pramnos\Llm\Chat\Web\ChatLook::accent((string) ($this->accent ?? ''));
$commands = \Pramnos\Llm\Chat\Web\ChatLook::commands($u, is_array($this->commands ?? null) ? $this->commands : []);
?>
<?php if ($waiting): ?><noscript><meta http-equiv="refresh" content="4"></noscript><?php endif; ?>
<div class="pf-chat<?php echo $empty ? ' pf-chat-empty' : ''; ?> px-4 py-6"<?php echo $accent !== '' ? ' style="--pf-chat-accent:' . $e($accent) . '"' : ''; ?> data-commands="<?php echo $e(json_encode($commands, JSON_UNESCAPED_UNICODE)); ?>" data-pf-chat data-page="<?php echo $e($u['page']); ?>" data-asked="<?php echo $e($u['asked']); ?>"<?php echo $sayAttr; ?> data-waiting-lines="<?php echo $e(json_encode($lines, JSON_UNESCAPED_UNICODE)); ?>" data-question-class="<?php echo $e($c['question']); ?>">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="<?php echo $c['card']; ?>" style="flex:0 0 240px;padding:12px" aria-label="<?php echo $e(t('Conversations')); ?>">
            <a href="<?php echo $e($u['page']); ?>" class="<?php echo $c['btn']; ?>"><?php echo $e(t('New conversation')); ?></a>
            <?php if ($u['scheduled'] !== ''): ?><a href="<?php echo $e($u['scheduled']); ?>" class="<?php echo $c['btnGhost']; ?>"><?php echo $e(t('Scheduled')); ?></a><?php endif; ?>
            <ul style="list-style:none;padding:0;margin:12px 0 0">
                <?php if ($list === []): ?>
                    <li class="<?php echo $c['muted']; ?>"><?php echo $e(t('Nothing asked yet.')); ?></li>
                <?php endif; ?>
                <?php foreach ($list as $one): ?>
                    <li style="display:flex;gap:4px;align-items:center">
                        <a style="flex:1;min-width:0" href="<?php echo $e($u['page'] . '?c=' . (int) $one['conversation_id']); ?>"<?php echo (int) $one['conversation_id'] === $openId ? ' aria-current="page" style="font-weight:600"' : ''; ?>><?php echo $e($one['title']); ?></a>
                        <form method="post" action="<?php echo $e($u['delete'] . (int) $one['conversation_id']); ?>" style="display:inline">
                            <?php echo $token(); ?>
                            <button type="submit" class="<?php echo $c['btnGhost']; ?>" aria-label="<?php echo $e(t('Delete') . ' ' . $one['title']); ?>" data-confirm="<?php echo $e(t('Delete this conversation?') . ' ' . t('Every question and answer in it goes. This cannot be undone.')); ?>">✕</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($u['shared'] !== ''): ?><?php echo \Pramnos\Llm\Chat\Web\ShareView::withMe(is_array($this->sharedWithMe ?? null) ? $this->sharedWithMe : [], $u['shared'], $c); ?><?php endif; ?>
        </nav>

        <main class="pf-chat-main" style="flex:1 1 420px;min-width:0">
            <?php if ($empty): ?>
                <div class="pf-chat-hero" data-pf-chat-hero>
                    <?php if (!empty($this->headingAvatar)): ?><?php echo $this->headingAvatar; ?><?php endif; ?>
                    <h1><?php echo $e($greeting); ?></h1>
                </div>
            <?php else: ?>
                <h1 class="text-2xl font-semibold mb-3"><?php if (empty($open['title']) && !empty($this->headingAvatar)): ?><?php echo $this->headingAvatar; ?> <?php endif; ?><?php echo $e($open['title'] ?? ($this->heading ?? t('Ask AI'))); ?></h1>
            <?php endif; ?>
            <?php if ($open !== null && $u['share'] !== ''): /* share it as it stands now */ ?>
                <?php echo \Pramnos\Llm\Chat\Web\ShareView::form($u['share'], 'conversation', $openId, $openId, $token(), $c); ?>
                <?php echo \Pramnos\Llm\Chat\Web\ShareView::recipients(is_array($this->shares ?? null) ? $this->shares : [], $u['unshare'], $openId, $token(), $c); ?>
            <?php endif; ?>
            <?php // The page's own tools, out of the way: a gear with whichever of them the reader has. ?>
            <?php if (!empty($this->settingsUrl) || $u['all'] !== '' || $u['reviews'] !== ''): ?>
                <details class="pf-ask-gear" style="display:inline-block;position:relative;margin-bottom:12px">
                    <summary aria-label="<?php echo $e(t('Ask page tools')); ?>" title="<?php echo $e(t('Ask page tools')); ?>" style="list-style:none;cursor:pointer">⚙</summary>
                    <ul class="<?php echo $c['card']; ?>" style="position:absolute;z-index:10;list-style:none;margin:4px 0 0;padding:8px 12px;white-space:nowrap">
                        <?php if (!empty($this->settingsUrl)): ?><li><a href="<?php echo $e($this->settingsUrl); ?>"><?php echo $e(t('Settings')); ?></a></li><?php endif; ?>
                        <?php if ($u['all'] !== ''): ?><li><a href="<?php echo $e($u['all']); ?>"><?php echo $e(t('Other people\'s Ask conversations')); ?></a></li><?php endif; ?>
                        <?php if ($u['reviews'] !== ''): ?><li><a href="<?php echo $e($u['reviews']); ?>"><?php echo $e(t('Answers to review')); ?></a></li><?php endif; ?>
                    </ul>
                </details>
            <?php endif; ?>

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

            <div class="pf-chat-messages" data-pf-chat-messages>
                <?php foreach ($open['messages'] ?? [] as $m): ?>
                    <?php $mine = $m['role'] === 'user'; $at = \Pramnos\Llm\Chat\Web\Stamp::iso((string) ($m['created_at'] ?? '')); ?>
                    <article class="pf-chat-message" data-pf-chat-role="<?php echo $mine ? 'user' : 'assistant'; ?>" style="display:flex;flex-direction:column;align-items:<?php echo $mine ? 'flex-end' : 'flex-start'; ?>;margin-bottom:16px">
                        <header class="<?php echo $c['muted']; ?>" style="margin-bottom:4px"><strong><?php echo $e($mine ? t('You') : $name); ?></strong><?php if ($at !== ''): ?> · <time datetime="<?php echo $e($at); ?>" data-pf-chat-time><?php echo $e(\Pramnos\Llm\Chat\Web\Stamp::text($at)); ?></time><?php endif; ?></header>
                        <div class="<?php echo $mine ? $c['question'] : $c['answer']; ?>" style="<?php echo $mine ? 'padding:10px 14px;max-width:80%' : 'width:100%'; ?>">
                        <?php if ($mine): ?>
                            <?php $doc = preg_match('/^(.*?)\n*---\n📎 ([^\n]+)(?:\n\n(.*))?$/su', (string) $m['content'], $d) === 1 ? $d : null; ?>
                            <div style="white-space:pre-wrap"><?php echo $e($doc === null ? $m['content'] : $doc[1]); ?></div>
                            <?php if ($doc !== null): ?>
                                <details><summary>📎 <?php echo $e($doc[2]); ?></summary><div class="<?php echo $c['muted']; ?>" style="white-space:pre-wrap;max-height:15rem;overflow:auto"><?php echo $e($doc[3] ?? ''); ?></div></details>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="pf-chat-answer"><?php echo \Pramnos\Llm\Chat\Markdown::toHtml((string) $m['content']); ?></div>
                            <?php foreach ((array) ($m['artifacts'] ?? []) as $artifact): /* kept results: a table, a list, a text */ ?>
                                <?php echo \Pramnos\Llm\Chat\Web\ArtifactView::html($artifact, $c['card'], 'table table-sm', $c['muted']); ?>
                                <?php if ($u['share'] !== ''): ?><?php echo \Pramnos\Llm\Chat\Web\ShareView::form($u['share'], 'artifact', (int) $artifact['artifact_id'], $openId, $token(), $c); ?><?php endif; ?>
                                <?php echo \Pramnos\Llm\Chat\Web\ArtifactTools::buttons($artifact, ['download' => $u['download'], 'print' => $u['print'], 'pin' => $u['pin']], $pinned, $openId, $token(), $c); ?>
                                <?php if ($u['act'] !== '' && $artifact['kind'] === 'list'): ?><?php echo \Pramnos\Llm\Chat\Web\BulkView::forms($bulk[(string) $artifact['entity']] ?? [], $u['act'], (int) $artifact['artifact_id'], count((array) ($artifact['content']['ids'] ?? [])), $openId, $token(), $c); ?><?php endif; ?>
                                <?php if ($u['approve'] !== ''): ?><?php echo \Pramnos\Llm\Chat\Web\BulkView::decision($artifact, $u['approve'], $u['reject'], $openId, $token(), $c); ?><?php endif; ?>
                            <?php endforeach; ?>
                            <?php $steps = array_values(array_filter((array) (($m['extra'] ?? [])['steps'] ?? []), 'is_string')); ?>
                            <?php if ($steps !== []): ?>
                                <details class="<?php echo $c['muted']; ?>">
                                    <summary><?php echo $e(t('What it did on the way')); ?></summary>
                                    <?php foreach ($steps as $step): ?><div class="pf-chat-step"><?php echo \Pramnos\Llm\Chat\Markdown::toHtml($step); ?></div><?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                            <?php $tools = (array) ($m['tools'] ?? []); ?>
                            <?php if ($tools === []): ?>
                                <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('No data was read for this answer')); ?></p>
                            <?php else: ?>
                                <details class="<?php echo $c['muted']; ?>">
                                    <summary><?php echo $e(sprintf(t('Read %s times from your data'), count($tools))); ?></summary>
                                    <ul>
                                        <?php foreach ($tools as $tool): ?>
                                            <?php $args = []; foreach ((array) ($tool['arguments'] ?? []) as $k => $v) { $args[] = $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v)); } ?>
                                            <li style="font-family:monospace"><?php echo $e(($tool['name'] ?? '') . ($args === [] ? '' : ' (' . implode(', ', $args) . ')')); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                        <?php if (!$mine): ?>
                            <button type="button" class="<?php echo $c['btnGhost']; ?>" data-pf-chat-copy="<?php echo $e($m['content']); ?>" aria-label="<?php echo $e(t('Copy the answer')); ?>">⧉ <?php echo $e(t('Copy')); ?></button>
                            <?php if ($u['rate'] !== '' && !empty($m['message_id'])): ?><?php echo \Pramnos\Llm\Chat\Web\RatingView::buttons($m, $u['rate'], $openId, $token(), $c); ?><?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <?php if ($waiting): ?>
                    <p class="<?php echo $c['muted']; ?>" data-pf-chat-waiting="<?php echo (int) $ask['ask_id']; ?>" role="status"><?php echo $e(t('Thinking')); ?>…</p>
                <?php elseif ($ask !== null && ($ask['status'] ?? '') === 'failed'): ?>
                    <p class="<?php echo $c['bad']; ?>" role="alert"><?php echo $e($ask['says'] ?? ''); ?></p>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo $e($u['ask']); ?>" enctype="multipart/form-data" class="pf-chat-composer" data-pf-chat-form>
                <?php echo $token(); ?>
                <input type="hidden" name="conversation_id" value="<?php echo $openId; ?>">
                <label for="chat-ask" class="pf-sr-only"><?php echo $e(t('Your question')); ?></label>
                <textarea id="chat-ask" name="content" rows="<?php echo $empty ? 3 : 1; ?>" class="pf-chat-input" placeholder="<?php echo $e((string) ($this->placeholder ?? '') !== '' ? $this->placeholder : t('Ask a question…')); ?>" aria-describedby="chat-ask-hint"></textarea>
                <div class="pf-chat-bar">
                    <label class="pf-chat-attach" title="<?php echo $e(t('Attach a document')); ?>">📎<span class="pf-sr-only"><?php echo $e(t('Attach a document')); ?></span>
                        <input type="file" name="files[]" multiple accept=".docx,.odt,.txt,.md,.eml,image/*" class="pf-sr-only" data-pf-chat-files>
                    </label>
                    <span data-pf-chat-chips style="display:flex;flex-wrap:wrap;gap:4px"></span>
                    <span class="pf-chat-hint" id="chat-ask-hint"><?php echo $e(t('Enter to send · Shift+Enter for a new line · / for commands')); ?></span>
                    <button type="submit" class="pf-chat-send" aria-label="<?php echo $e(t('Ask')); ?>" title="<?php echo $e(t('Ask')); ?>"<?php echo !empty($consent['required']) && empty($consent['given']) ? ' disabled' : ''; ?>>↑</button>
                </div>
            </form>
            <?php $examples = $empty ? array_values(array_filter(is_array($this->suggestions ?? null) ? $this->suggestions : [], 'is_string')) : []; ?>
            <?php if ($examples !== []): ?>
                <div class="pf-chat-suggestions" aria-label="<?php echo $e(t('Example questions')); ?>">
                    <?php foreach ($examples as $example): ?>
                        <button type="button" class="btn btn-sm btn-outline" data-pf-chat-suggestion="<?php echo $e($example); ?>"><?php echo $e($example); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($context > 0 && $window > 0): ?>
                <p class="<?php echo $c['muted']; ?>" style="display:flex;gap:8px;align-items:center">
                    <?php echo $e(t('Context')); ?>
                    <progress value="<?php echo min(100, (int) round($context / $window * 100)); ?>" max="100" aria-label="<?php echo $e(t('Context')); ?>"></progress>
                    <?php echo number_format($context) . ' / ' . number_format($window); ?>
                </p>
            <?php endif; ?>

            <?php if ($open !== null): ?>
                <form method="post" action="<?php echo $e($u['delete'] . $openId); ?>" style="margin-top:8px">
                    <?php echo $token(); ?>
                    <button type="submit" class="<?php echo $c['btnGhost']; ?>" data-confirm="<?php echo $e(t('Delete this conversation?') . ' ' . t('Every question and answer in it goes. This cannot be undone.')); ?>"><?php echo $e(t('Delete this conversation?')); ?></button>
                </form>
            <?php endif; ?>
        </main>

        <aside class="<?php echo $c['card']; ?>" style="flex:0 0 260px;padding:12px">

            <details data-pf-chat-memory>

            <summary aria-label="<?php echo $e(t('Model, limits and memory')); ?>">⚙ <?php echo $e(t('Model, limits and memory')); ?></summary>
            <h2 class="text-sm font-semibold mt-3"><?php echo $e(t('Model')); ?></h2>
            <p class="<?php echo $c['muted']; ?>"><?php echo $e(($limits['provider'] ?? '') . ' · ' . ($limits['model'] ?? '')); ?></p>
            <h2 class="text-sm font-semibold mt-3"><?php echo $e(t('Limits')); ?></h2>
            <ul class="<?php echo $c['muted']; ?>">
                <li><?php echo $e(sprintf(t('The last %d messages are sent with each question'), (int) ($limits['turns'] ?? 0))); ?></li>
                <li><?php echo $e(sprintf(t('Up to %d data reads per answer'), (int) ($limits['rounds'] ?? 0))); ?></li>
                <li><?php echo $e(sprintf(t('Up to %d things remembered'), (int) ($limits['memory'] ?? 0))); ?></li>
            </ul>
            <h2 class="text-sm font-semibold mt-3"><?php echo $e(sprintf(t('What it remembers (%d)'), count($memory))); ?></h2>
            <?php if ($memory === []): ?>
                <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('Nothing remembered yet.')); ?></p>
            <?php endif; ?>
            <ul style="list-style:none;padding:0">
                <?php foreach ($memory as $one): ?>
                    <li style="margin-bottom:6px">
                        <?php echo $e($one['content']); ?> <span class="<?php echo $c['muted']; ?>"><?php echo $e(t($one['kind'])); ?><?php if (($one['source'] ?? null) === 'learned'): ?> · <?php echo $e(t('learned from what you said')); ?><?php endif; ?></span>
                        <form method="post" action="<?php echo $e($u['forget'] . (int) $one['memory_id']); ?>" style="display:inline">
                            <?php echo $token(); ?>
                            <input type="hidden" name="conversation_id" value="<?php echo $openId; ?>">
                            <button type="submit" class="<?php echo $c['btnGhost']; ?>" aria-label="<?php echo $e(t('Forget')); ?>">✕</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($consent['required']) && !empty($consent['given'])): ?>
                <h2 class="text-sm font-semibold mt-3"><?php echo $e(t('Your agreement')); ?></h2>
                <p class="<?php echo $c['muted']; ?>"><?php echo $e(sprintf(t('You agreed to the AI features using %s.'), $consent['label'])); ?></p>
                <form method="post" action="<?php echo $e($u['consent']); ?>">
                    <?php echo $token(); ?>
                    <button type="submit" class="<?php echo $c['btnGhost']; ?>"><?php echo $e(t('Withdraw — send nothing more')); ?></button>
                </form>
            <?php endif; ?>

            </details>
        </aside>
    </div>
</div>
<script src="<?php echo $e(assetUrl('assets/js/pf-chat.js')); ?>" defer></script>
