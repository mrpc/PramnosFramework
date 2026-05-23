<?php
/**
 * Email detail view (Bootstrap theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];
?>
<div class="container py-4" style="max-width:860px">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?php echo sURL; ?>Emails" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Email #<?php echo (int)($mail['id'] ?? 0); ?></h2>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-2">To</dt>
                <dd class="col-sm-10"><?php echo htmlspecialchars($mail['recipient'] ?? $mail['mailto'] ?? ''); ?></dd>
                <dt class="col-sm-2">Subject</dt>
                <dd class="col-sm-10"><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                <dt class="col-sm-2">Date</dt>
                <dd class="col-sm-10"><?php echo htmlspecialchars($mail['date'] ?? $mail['maildate'] ?? ''); ?></dd>
                <dt class="col-sm-2">Status</dt>
                <dd class="col-sm-10"><?php echo (int)($mail['status'] ?? 0) === 1 ? '<span class="badge bg-success">Sent</span>' : '<span class="badge bg-secondary">Pending</span>'; ?></dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card-header fw-semibold">Body</div>
        <div class="card-body">
            <?php if (!empty($mail['body']) || !empty($mail['mailbody'])): ?>
                <iframe srcdoc="<?php echo htmlspecialchars($mail['body'] ?? $mail['mailbody'] ?? ''); ?>"
                    style="width:100%;border:none;min-height:400px" onload="this.style.height=this.contentDocument.body.scrollHeight+'px'"></iframe>
            <?php else: ?>
                <p class="text-muted">No body content.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
