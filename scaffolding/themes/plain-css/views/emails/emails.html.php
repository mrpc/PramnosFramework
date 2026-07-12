<?php
/**
 * Email log list (plain-CSS theme).
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
<div class="page-section">
    <h2 style="margin-bottom:16px">Email Log</h2>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>Recipient</th><th>Subject</th><th>Date</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->mails ?? []) as $mail): ?>
                    <tr>
                        <td><?php echo (int)$mail['id']; ?></td>
                        <td><?php echo htmlspecialchars($mail['recipient'] ?? $mail['mailto'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($mail['date'] ?? $mail['maildate'] ?? ''); ?></td>
                        <td><?php echo $statusLabel($mail['status'] ?? 0); ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Emails/show/<?php echo (int)$mail['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <?php if ((int)($mail['status'] ?? 0) === 0): ?>
                                <a href="<?php echo sURL; ?>Emails/resend/<?php echo (int)$mail['id']; ?>" class="btn btn-sm btn-outline-primary">Resend</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->mails)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No emails found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
