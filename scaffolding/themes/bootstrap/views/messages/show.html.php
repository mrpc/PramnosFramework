<?php
/**
 * One message (Bootstrap theme). See the Tailwind copy for the notes.
 */
$message = is_array($this->message ?? null) ? $this->message : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$date    = (int) ($message['date'] ?? 0);
$isHtml  = (int) ($message['html'] ?? 0) === 1;
?>
<div class="container py-4" style="max-width:760px">
    <p class="mb-3"><a href="<?php echo sURL; ?>messages">&larr; <?php echo $e(t('Messages')); ?></a></p>
    <article class="card"><div class="card-body">
        <h1 class="h4"><?php echo $e($message['subject'] ?? ''); ?></h1>
        <p class="small text-muted"><?php echo $e($date > 0 ? localDateTime( $date) : ''); ?></p>
        <div><?php echo $isHtml ? ($message['text'] ?? '') : nl2br($e($message['text'] ?? '')); ?></div>
    </div></article>
</div>
