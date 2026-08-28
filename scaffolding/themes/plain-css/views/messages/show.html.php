<?php
/**
 * One message (plain-css theme). See the Tailwind copy for the notes.
 */
$message = is_array($this->message ?? null) ? $this->message : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$date    = (int) ($message['date'] ?? 0);
$isHtml  = (int) ($message['html'] ?? 0) === 1;
?>
<div style="max-width:760px;margin:0 auto;padding:24px 16px">
    <p><a href="<?php echo sURL; ?>messages">&larr; <?php echo $e(t('Messages')); ?></a></p>
    <article style="border:1px solid #ddd;border-radius:4px;padding:20px">
        <h1 style="margin:0 0 4px;font-size:20px"><?php echo $e($message['subject'] ?? ''); ?></h1>
        <p style="margin:0 0 16px;font-size:12px;color:#888"><?php echo $e($date > 0 ? localDateTime( $date) : ''); ?></p>
        <div><?php echo $isHtml ? ($message['text'] ?? '') : nl2br($e($message['text'] ?? '')); ?></div>
    </article>
</div>
