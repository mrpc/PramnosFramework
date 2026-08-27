<?php
/**
 * Email detail view (plain-CSS theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];

/*
 * The column names, read from the table rather than guessed — see the note in the tailwind
 * copy of this screen. `mails` has `tomail`, `toname`, `content` and an integer `date`.
 */
$recipient = (string) ($mail['tomail'] ?? $recipient);
$body      = (string) ($mail['content'] ?? $mail['body'] ?? $mail['mailbody'] ?? '');
$rawDate   = $sentAt;
$sentAt    = is_numeric($rawDate) && (int) $rawDate > 0
    ? date('j M Y, H:i', (int) $rawDate)
    : (string) $rawDate;
?>
<div class="page-section"max-width:860px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo adminUrl('Emails'); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 >Email #<?php echo (int)($mail['id'] ?? 0); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <dl >
                <dt style="font-weight:600;min-width:120px;display:inline-block">To</dt>
                <dd ><?php echo htmlspecialchars($recipient); ?></dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block">Subject</dt>
                <dd ><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block">Date</dt>
                <dd ><?php echo htmlspecialchars($sentAt); ?></dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block">Status</dt>
                <dd ><?php echo (int)($mail['status'] ?? 0) === 1 ? '<span class="badge bg-success">Sent</span>' : '<span class="badge bg-secondary">Pending</span>'; ?></dd>
            </dl>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Body</div>
        <div class="card-body" style="padding:16px">
            <?php if ($body !== ''): ?>
                <iframe sandbox srcdoc="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>"
                    style="width:100%;border:none;min-height:400px" onload="this.style.height=this.contentDocument.body.scrollHeight+'px'"></iframe>
            <?php else: ?>
                <p style="color:#888">No body content.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
