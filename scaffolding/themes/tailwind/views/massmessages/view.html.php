<?php
/**
 * One mass message: what it says, who it goes to, and how far it got (Tailwind theme).
 *
 * Variables:
 *   $this->message  — the row
 *   $this->types    — channel number => label
 *   $this->progress — {total, pending, delivered, failed}
 *   $this->audience — the criteria, in a sentence
 *
 * The send button lives here rather than on the compose form because queueing is the one
 * action on this screen that cannot be undone: it is a POST with the anti-CSRF token, and
 * the count next to it is what somebody reads before pressing it.
 */
$message  = is_array($this->message ?? null) ? $this->message : [];
$types    = is_array($this->types ?? null) ? $this->types : [];
$progress = is_array($this->progress ?? null) ? $this->progress : [];
$id       = (int) ($message['messageid'] ?? 0);
$status   = (int) ($message['status'] ?? 0);
$total    = (int) ($progress['total'] ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when     = static function ($value): string {
    $time = (int) $value;

    return $time > 0 ? date('Y-m-d H:i', $time) : '—';
};
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h2 class="text-lg font-semibold"><?php echo $e($message['subject'] ?? '(no subject)'); ?></h2>
        <?php if ($status === \Pramnos\Messaging\MassMessage::STATUS_SENT): ?>
            <span class="pf-state pf-state-on">Sent</span>
        <?php elseif ($status === \Pramnos\Messaging\MassMessage::STATUS_SCHEDULED): ?>
            <span class="pf-state">Scheduled <?php echo $e($when($message['scheduled'] ?? 0)); ?></span>
        <?php elseif ($total > 0): ?>
            <span class="pf-state">Sending</span>
        <?php else: ?>
            <span class="pf-state pf-state-off">Draft</span>
        <?php endif; ?>
        <span class="text-sm text-base-content/60"><?php echo $e($types[(int) ($message['type'] ?? 0)] ?? ''); ?></span>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs uppercase text-base-content/70">Body</div>
                <pre class="p-4 text-xs whitespace-pre-wrap break-words"><?php echo $e($message['message'] ?? ''); ?></pre>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs uppercase text-base-content/70">Delivery</div>
                <table class="table table-sm text-sm">
                    <tbody>
                        <tr><td>Recipients</td><td class="text-right"><?php echo (int) $total; ?></td></tr>
                        <tr><td>Delivered</td><td class="text-right"><?php echo (int) ($progress['delivered'] ?? 0); ?></td></tr>
                        <tr>
                            <td>Failed</td>
                            <td class="text-right">
                                <?php $failed = (int) ($progress['failed'] ?? 0); ?>
                                <?php if ($failed > 0): ?>
                                    <span class="text-error font-medium"><?php echo $failed; ?></span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><td>Pending</td><td class="text-right"><?php echo (int) ($progress['pending'] ?? 0); ?></td></tr>
                        <tr><td>Created</td><td class="text-right text-xs"><?php echo $e($when($message['created'] ?? 0)); ?></td></tr>
                    </tbody>
                </table>
                <?php if ($total > 0 && (int) ($progress['pending'] ?? 0) > 0): ?>
                <div class="px-4 py-3 text-xs text-base-content/60 border-t border-base-300">
                    Delivery runs on the schedule, a batch at a time. Reload for the current
                    numbers — nothing is lost if a run is interrupted, because each recipient
                    is marked as it is attempted.
                </div>
                <?php endif; ?>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-3">
                <div class="text-xs uppercase text-base-content/70">Audience</div>
                <p class="text-sm"><?php echo $e($this->audience ?? 'every account'); ?></p>

                <?php if ($total === 0): ?>
                    <form method="post" action="<?php echo adminUrl('MassMessages/send/') . $id; ?>">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <button type="submit" class="btn btn-primary btn-sm w-full">
                            Queue for delivery
                        </button>
                    </form>
                    <p class="text-xs text-base-content/60">
                        This writes one row per recipient and returns. It cannot be undone and
                        it cannot be done twice — a message that already has recipients is
                        refused rather than queued again.
                    </p>
                <?php else: ?>
                    <p class="text-xs text-base-content/60">
                        Already queued. A second queueing would reach every person on the list
                        a second time, so it is refused.
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($status !== \Pramnos\Messaging\MassMessage::STATUS_SENT && $total === 0): ?>
            <div class="flex gap-2">
                <a href="<?php echo adminUrl('MassMessages/edit/') . $id; ?>" class="btn btn-outline btn-sm">Edit</a>
                <a href="<?php echo adminUrl('MassMessages/delete/') . $id; ?>" class="btn btn-outline btn-error btn-sm">Delete</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
