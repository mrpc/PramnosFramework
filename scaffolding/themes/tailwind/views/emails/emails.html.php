<?php
/**
 * Email log list (Tailwind theme).
 *
 * Variables:
 *   $this->mails — iterable rows (id, recipient, subject, date, status)
 *   $this->page  — current page
 *   $this->total — total count
 */
$statusLabel = fn($s) => match((int)$s) {
    1 => '<span class="badge badge-success">Sent</span>',
    2 => '<span class="badge badge-warning">Queued</span>',
    default => '<span class="badge badge-neutral">Pending</span>',
};
?>
<div class="px-4 py-6">
    <h2 class="mb-6">Email Log</h2>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>ID</th><th>Recipient</th><th>Subject</th><th>Date</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->mails ?? []) as $mail): ?>
                    <tr>
                        <td><?php echo (int)$mail['id']; ?></td>
                        <td><?php echo htmlspecialchars($mail['recipient'] ?? $mail['mailto'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></td>
                        <td class="text-base-content/60 text-xs"><?php echo htmlspecialchars($mail['date'] ?? $mail['maildate'] ?? ''); ?></td>
                        <td><?php echo $statusLabel($mail['status'] ?? 0); ?></td>
                        <td class="text-right">
                            <a href="<?php echo adminUrl('Emails' . '/show/' . ((int)$mail['id'])); ?>" class="btn btn-outline btn-xs">View</a>
                            <?php if ((int)($mail['status'] ?? 0) === 0): ?>
                                <a href="<?php echo adminUrl('Emails' . '/resend/' . ((int)$mail['id'])); ?>" class="btn btn-outline btn-primary btn-xs">Resend</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->mails)): ?>
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">No emails found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
