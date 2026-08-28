<?php
/**
 * One message (Tailwind theme).
 *
 * Variables:
 *   $this->message — the row: subject, text, date, html
 *
 * `html` decides whether the body is printed or escaped, and the decision is the message's own
 * rather than this view's: a mass message composed in the administration area is HTML on
 * purpose, and escaping it would show an operator's markup to the reader as text.
 */
$message = is_array($this->message ?? null) ? $this->message : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$date    = (int) ($message['date'] ?? 0);
$isHtml  = (int) ($message['html'] ?? 0) === 1;
?>
<div class="px-4 py-6 max-w-3xl mx-auto">
    <p class="mb-4">
        <a href="<?php echo sURL; ?>messages" class="link link-primary text-sm">
            &larr; <?php echo $e(t('Messages')); ?>
        </a>
    </p>

    <article class="card bg-base-100 border border-base-300 shadow-xs p-6">
        <h1 class="text-xl font-semibold mb-1"><?php echo $e($message['subject'] ?? ''); ?></h1>
        <p class="text-xs text-base-content/50 mb-4">
            <?php echo $e($date > 0 ? date('Y-m-d H:i', $date) : ''); ?>
        </p>
        <div class="prose max-w-none text-base-content">
            <?php echo $isHtml ? ($message['text'] ?? '') : nl2br($e($message['text'] ?? '')); ?>
        </div>
    </article>
</div>
