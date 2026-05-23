<?php
/**
 * Email detail view (Tailwind theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];
?>
<div class="max-w-4xl mx-auto py-6 px-4">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo sURL; ?>Emails" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">&larr; Back</a>
        <h2 >Email #<?php echo (int)($mail['id'] ?? 0); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-4">
        <div class="p-5">
            <dl >
                <dt class="font-semibold text-gray-600 text-sm w-32 inline-block">To</dt>
                <dd ><?php echo htmlspecialchars($mail['recipient'] ?? $mail['mailto'] ?? ''); ?></dd>
                <dt class="font-semibold text-gray-600 text-sm w-32 inline-block">Subject</dt>
                <dd ><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                <dt class="font-semibold text-gray-600 text-sm w-32 inline-block">Date</dt>
                <dd ><?php echo htmlspecialchars($mail['date'] ?? $mail['maildate'] ?? ''); ?></dd>
                <dt class="font-semibold text-gray-600 text-sm w-32 inline-block">Status</dt>
                <dd ><?php echo (int)($mail['status'] ?? 0) === 1 ? '<span class="badge bg-success">Sent</span>' : '<span class="badge bg-secondary">Pending</span>'; ?></dd>
            </dl>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Body</div>
        <div class="p-5">
            <?php if (!empty($mail['body']) || !empty($mail['mailbody'])): ?>
                <iframe srcdoc="<?php echo htmlspecialchars($mail['body'] ?? $mail['mailbody'] ?? ''); ?>"
                    style="width:100%;border:none;min-height:400px" onload="this.style.height=this.contentDocument.body.scrollHeight+'px'"></iframe>
            <?php else: ?>
                <p class="text-gray-500">No body content.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
