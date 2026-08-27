<?php
/**
 * Email detail view (Tailwind theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];

/*
 * The column names, read from the table rather than guessed.
 *
 * This screen showed an empty recipient, a raw Unix timestamp and "No body content." for
 * every mail ever queued, because it read `recipient`/`mailto`, `body`/`mailbody` and
 * printed `date` as it found it. The `mails` table has `tomail`, `toname`, `content` and an
 * integer `date`. Nothing was broken in the mailer: the preview was reading fields that do
 * not exist, and `?? ''` turned each miss into a blank rather than into an error — which is
 * why it looked like a mailer that sends empty mail.
 *
 * The alternative names are kept as fallbacks for an application whose own table differs.
 */
$recipient = (string) ($mail['tomail'] ?? $mail['recipient'] ?? $mail['mailto'] ?? '');
$toName    = (string) ($mail['toname'] ?? '');
$body      = (string) ($mail['content'] ?? $mail['body'] ?? $mail['mailbody'] ?? '');

$rawDate = $mail['date'] ?? $mail['maildate'] ?? '';
$sentAt  = is_numeric($rawDate) && (int) $rawDate > 0
    ? date('j M Y, H:i', (int) $rawDate)
    : (string) $rawDate;
?>
<div class="max-w-4xl mx-auto py-6 px-4">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('Emails'); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 >Email #<?php echo (int)($mail['id'] ?? 0); ?></h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4">
        <div class="p-5">
            <dl >
                <dt class="font-semibold text-base-content/80 text-sm w-32 inline-block">To</dt>
                <dd ><?php
                    echo htmlspecialchars($recipient);
                    echo $toName !== '' ? ' (' . htmlspecialchars($toName) . ')' : '';
                ?></dd>
                <dt class="font-semibold text-base-content/80 text-sm w-32 inline-block">Subject</dt>
                <dd ><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                <dt class="font-semibold text-base-content/80 text-sm w-32 inline-block">Date</dt>
                <dd ><?php echo htmlspecialchars($sentAt); ?></dd>
                <dt class="font-semibold text-base-content/80 text-sm w-32 inline-block">Status</dt>
                <dd ><?php echo (int)($mail['status'] ?? 0) === 1 ? '<span class="badge badge-success">Sent</span>' : '<span class="badge badge-neutral">Pending</span>'; ?></dd>
            </dl>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Body</div>
        <div class="p-5">
            <?php if ($body !== ''): ?>
                <?php /* Sandboxed: the body is whatever was queued, and this screen is
                         inside the administration area. `allow-same-origin` is deliberately
                         absent, so the frame cannot reach this page's DOM or cookies. */ ?>
                <iframe sandbox srcdoc="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>"
                    style="width:100%;border:none;min-height:400px" onload="this.style.height=this.contentDocument.body.scrollHeight+'px'"></iframe>
            <?php else: ?>
                <p class="text-base-content/70">No body content.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
