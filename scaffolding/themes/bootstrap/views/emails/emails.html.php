<?php
/**
 * Email log list (Bootstrap theme).
 *
 * Variables:
 *   $this->mails — iterable rows (id, recipient, subject, date, status)
 *   $this->page  — current page
 *   $this->total — total count
 */
$statusLabel = fn($s) => match((int)$s) {
    1 => '<span class="badge bg-success">Sent</span>',
    2 => '<span class="badge bg-warning text-dark">Queued</span>',
    default => '<span class="badge bg-secondary">Pending</span>',
};
?>
<div class="container-fluid py-4">
    <h2 class="mb-4">Email Log</h2>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>ID</th><th>Recipient</th><th>Subject</th><th>Date</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->mails ?? []) as $mail): ?>
                    <tr>
                        <td><?php echo (int)$mail['id']; ?></td>
                        <td><?php echo htmlspecialchars($mail['recipient'] ?? $mail['mailto'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($mail['date'] ?? $mail['maildate'] ?? ''); ?></td>
                        <td><?php echo $statusLabel($mail['status'] ?? 0); ?></td>
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Emails/show/<?php echo (int)$mail['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <?php if ((int)($mail['status'] ?? 0) === 0): ?>
                                <a href="<?php echo sURL; ?>Emails/resend/<?php echo (int)$mail['id']; ?>" class="btn btn-sm btn-outline-primary">Resend</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->mails)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No emails found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
