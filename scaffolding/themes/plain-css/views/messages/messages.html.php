<?php
/**
 * The account's own messages (plain-css theme). See the Tailwind copy for the notes.
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
<div style="max-width:760px;margin:0 auto;padding:24px 16px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
        <h1 style="margin:0;font-size:20px"><?php echo $e(t('Messages')); ?></h1>
        <?php if ($unread > 0): ?>
        <span style="background:#2563eb;color:#fff;border-radius:10px;padding:1px 8px;font-size:12px"><?php echo $unread; ?></span>
        <form method="post" action="<?php echo sURL; ?>messages/readall" style="margin-left:auto">
            <button style="background:none;border:none;color:#2563eb;cursor:pointer"><?php echo $e(t('Mark all as read')); ?></button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($messages === []): ?>
    <p style="color:#666"><?php echo $e(t('You have no messages.')); ?></p>
    <?php else: ?>
    <ul style="list-style:none;margin:0;padding:0;border:1px solid #ddd;border-radius:4px">
        <?php foreach ($messages as $message): ?>
        <li style="border-bottom:1px solid #eee">
            <a style="display:flex;gap:10px;padding:12px 14px;text-decoration:none;color:inherit"
               href="<?php echo sURL; ?>messages/show/<?php echo (int) ($message['messageid'] ?? 0); ?>">
                <span style="margin-top:6px;width:8px;height:8px;border-radius:50%;flex:0 0 8px;background:<?php echo !empty($message['isUnread']) ? '#2563eb' : '#ddd'; ?>"></span>
                <span style="flex:1;min-width:0">
                    <span style="display:block;<?php echo !empty($message['isUnread']) ? 'font-weight:600' : ''; ?>"><?php echo $e($message['subject'] ?? ''); ?></span>
                    <span style="display:block;font-size:13px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo $e($excerpt(($message['excerpt'] ?? '') ?: ($message['text'] ?? ''))); ?></span>
                </span>
                <span style="font-size:12px;color:#888;white-space:nowrap"><?php echo $e($when($message['date'] ?? 0)); ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
