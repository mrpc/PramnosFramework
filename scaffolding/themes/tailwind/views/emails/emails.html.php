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
    1 => '<span class="badge bg-success">Sent</span>',
    2 => '<span class="badge bg-warning text-dark">Queued</span>',
    default => '<span class="badge bg-secondary">Pending</span>',
};
?>
<div class="px-4 py-6">
    <h2 class="mb-6">Email Log</h2>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>Recipient</th><th>Subject</th><th>Date</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->mails ?? []) as $mail): ?>
                    <tr>
                        <td><?php echo (int)$mail['id']; ?></td>
                        <td><?php echo htmlspecialchars($mail['recipient'] ?? $mail['mailto'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($mail['date'] ?? $mail['maildate'] ?? ''); ?></td>
                        <td><?php echo $statusLabel($mail['status'] ?? 0); ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Emails/show/<?php echo (int)$mail['id']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">View</a>
                            <?php if ((int)($mail['status'] ?? 0) === 0): ?>
                                <a href="<?php echo sURL; ?>Emails/resend/<?php echo (int)$mail['id']; ?>" class="px-3 py-1 border border-blue-400 text-blue-700 text-xs rounded hover:bg-blue-50">Resend</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->mails)): ?>
                    <tr><td colspan="6" class="text-center text-gray-400 py-8">No emails found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
