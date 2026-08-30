<?php
/**
 * The account's own messages (Tailwind theme).
 *
 * Variables:
 *   $this->messages — rows, newest first, each with `isUnread`
 *   $this->unread   — how many are unread
 *
 * A reader, not a mail client. The `messages` table has been written to since the messaging
 * feature shipped — a mass message sent as an internal message writes one row per recipient —
 * and until this screen existed nothing displayed any of it: the admin side reported every
 * recipient delivered, and delivered meant "written to a table nobody looks at".
 */
$messages = is_array($this->messages ?? null) ? $this->messages : [];
$unread   = (int) ($this->unread ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when     = static function ($value): string {
    $time = (int) $value;

    return $time > 0 ? localDateTime( $time) : '—';
};
$excerpt  = static function ($text): string {
    // Tags stripped before truncating: a message may be HTML, and cutting markup in half
    // leaves an unclosed tag that takes the rest of the page with it.
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');

    return mb_strlen($plain) > 140 ? mb_substr($plain, 0, 140) . '…' : $plain;
};
?>
<div class="px-4 py-6 max-w-3xl mx-auto">
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h1 class="text-xl font-semibold"><?php echo $e(t('Messages')); ?></h1>
        <?php if ($unread > 0): ?>
        <span class="badge badge-primary badge-sm"><?php echo $unread; ?></span>
        <form method="post" action="<?php echo sURL; ?>messages/readall" class="ms-auto">
            <button class="btn btn-ghost btn-sm"><?php echo $e(t('Mark all as read')); ?></button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($messages === []): ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-sm text-base-content/70">
        <?php echo $e(t('You have no messages.')); ?>
    </div>
    <?php else: ?>
    <ul class="card bg-base-100 border border-base-300 shadow-xs divide-y divide-base-300">
        <?php foreach ($messages as $message): ?>
        <li>
            <a href="<?php echo sURL; ?>messages/show/<?php echo (int) ($message['messageid'] ?? 0); ?>"
               class="flex gap-3 items-start p-4 hover:bg-base-200 transition-colors">
                <span class="mt-1.5 w-2 h-2 rounded-full <?php echo !empty($message['isUnread']) ? 'bg-primary' : 'bg-base-300'; ?>"
                      aria-hidden="true"></span>
                <span class="flex-1 min-w-0">
                    <span class="block <?php echo !empty($message['isUnread']) ? 'font-semibold' : ''; ?>">
                        <?php echo $e($message['subject'] ?? ''); ?>
                    </span>
                    <span class="block text-sm text-base-content/60 truncate">
                        <?php echo $e($excerpt($message['text'] ?? '')); ?>
                    </span>
                </span>
                <span class="text-xs text-base-content/50 whitespace-nowrap">
                    <?php echo $e($when($message['date'] ?? 0)); ?>
                </span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
