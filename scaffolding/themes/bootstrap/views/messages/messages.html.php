<?php
/**
 * The account's own messages (Bootstrap theme). See the Tailwind copy for the notes.
 */
$messages = is_array($this->messages ?? null) ? $this->messages : [];
$unread   = (int) ($this->unread ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when     = static fn ($v): string => ((int) $v) > 0 ? localDateTime( (int) $v) : '—';
// `excerpt` is written at send time so this listing never opens a stored body.
// The fallback to `text` is for rows written before the store existed.
$excerpt  = static function ($text): string {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');

    return mb_strlen($plain) > 140 ? mb_substr($plain, 0, 140) . '…' : $plain;
};
?>
<div class="container py-4" style="max-width:760px">
    <div class="d-flex align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><?php echo $e(t('Messages')); ?></h1>
        <?php if ($unread > 0): ?>
        <span class="badge text-bg-primary"><?php echo $unread; ?></span>
        <form method="post" action="<?php echo sURL; ?>messages/readall" class="ms-auto">
            <button class="btn btn-sm btn-link"><?php echo $e(t('Mark all as read')); ?></button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($messages === []): ?>
    <div class="card"><div class="card-body text-muted"><?php echo $e(t('You have no messages.')); ?></div></div>
    <?php else: ?>
    <div class="list-group">
        <?php foreach ($messages as $message): ?>
        <a class="list-group-item list-group-item-action d-flex gap-3"
           href="<?php echo sURL; ?>messages/show/<?php echo (int) ($message['messageid'] ?? 0); ?>">
            <span class="mt-2 rounded-circle <?php echo !empty($message['isUnread']) ? 'bg-primary' : 'bg-secondary-subtle'; ?>"
                  style="width:8px;height:8px;flex:0 0 8px"></span>
            <span class="flex-grow-1 min-width-0">
                <span class="d-block <?php echo !empty($message['isUnread']) ? 'fw-semibold' : ''; ?>">
                    <?php echo $e($message['subject'] ?? ''); ?>
                </span>
                <span class="d-block small text-muted text-truncate">
                    <?php echo $e($excerpt(($message['excerpt'] ?? '') ?: ($message['text'] ?? ''))); ?>
                </span>
            </span>
            <span class="small text-muted text-nowrap"><?php echo $e($when($message['date'] ?? 0)); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
