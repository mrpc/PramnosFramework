<?php
/**
 * A question to the AI from anywhere on the site (Tailwind theme): include it in the layout.
 *
 *   <?php $this->insert('../partials/chat_widget'); ?>
 *
 * A floating button that opens a small form; the question goes to /chat, which answers it and
 * opens the conversation. Shown only to somebody signed in.
 */
$c = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'bad' => 'text-error', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'input' => 'textarea textarea-bordered', 'question' => 'rounded-box bg-base-200', 'answer' => 'rounded-box bg-base-100 border border-base-300 prose max-w-none'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (\Pramnos\Llm\Chat\Scope::current() === null) {
    return;
}
?>
<details class="pf-chat-widget" style="position:fixed;right:16px;bottom:16px;z-index:50">
    <summary class="<?php echo $c['btn']; ?>"><?php echo $e(t('Ask AI')); ?></summary>
    <form method="post" action="<?php echo $e(sURL . 'chat/ask'); ?>" class="<?php echo $c['card']; ?>" style="padding:12px;margin-top:8px;width:min(360px,calc(100vw - 32px))">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <label for="chat-widget-ask" class="pf-sr-only"><?php echo $e(t('Your question')); ?></label>
        <textarea id="chat-widget-ask" name="content" rows="3" class="<?php echo $c['input']; ?>" style="width:100%" placeholder="<?php echo $e(t('Ask a question…')); ?>" required></textarea>
        <div style="display:flex;gap:8px;margin-top:8px">
            <a href="<?php echo $e(sURL . 'chat'); ?>" class="<?php echo $c['btnGhost']; ?>"><?php echo $e(t('Ask AI')); ?></a>
            <button type="submit" class="<?php echo $c['btn']; ?>" style="margin-left:auto"><?php echo $e(t('Ask')); ?></button>
        </div>
    </form>
</details>
