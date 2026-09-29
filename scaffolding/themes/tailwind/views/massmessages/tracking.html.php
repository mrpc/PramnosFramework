<?php
/**
 * Who opened and clicked one mass message (Tailwind theme).
 *
 * Variables:
 *   $this->message    — the row
 *   $this->summary    — Tracking::campaign(): tracked, opened, proxyOnly, clicked, clicks
 *   $this->links      — Tracking::campaignLinks(): url, clicks, people
 *   $this->recipients — Tracking::campaignRecipients() for $this->show
 *   $this->show       — '', 'opened', 'clicked' or 'unopened'
 *
 * An open by a mailbox provider is shown apart from an open by a person, never added to it:
 * Apple and Google fetch the image on delivery, and counted together they report a message
 * nobody read as widely read. A click is a person.
 */
$message    = is_array($this->message ?? null) ? $this->message : [];
$summary    = is_array($this->summary ?? null) ? $this->summary : [];
$links      = is_array($this->links ?? null) ? $this->links : [];
$recipients = is_array($this->recipients ?? null) ? $this->recipients : [];
$show       = (string) ($this->show ?? '');
$id         = (int) ($message['messageid'] ?? 0);
$e          = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when       = static fn ($t): string => $t ? localDateTime((int) $t) : '—';
$filters    = ['' => 'Everyone tracked', 'opened' => 'Opened', 'clicked' => 'Clicked', 'unopened' => 'Not opened by a person'];
?>
<div class="px-4 py-6">
    <h2 class="text-lg font-semibold mb-4"><?php echo $e($message['subject'] ?? ''); ?> — opens and clicks</h2>
    <p><a href="<?php echo adminUrl('MassMessages/view/') . $id; ?>">Back to the message</a></p>

    <p class="text-sm opacity-70">
        <?php echo (int) ($summary['tracked'] ?? 0); ?> tracked ·
        <strong><?php echo (int) ($summary['opened'] ?? 0); ?></strong> opened by a person ·
        <?php echo (int) ($summary['proxyOnly'] ?? 0); ?> fetched only by a mailbox provider, which is not somebody reading it ·
        <strong><?php echo (int) ($summary['clicked'] ?? 0); ?></strong> clicked (<?php echo (int) ($summary['clicks'] ?? 0); ?> clicks)
    </p>

    <?php if ($links !== []): ?>
    <h3 class="font-medium mb-2">Links followed</h3>
    <table class="table w-full">
        <thead><tr><th>Link</th><th>Clicks</th><th>People</th></tr></thead>
        <tbody>
        <?php foreach ($links as $link): ?>
            <tr><td style="word-break:break-all"><?php echo $e($link['url']); ?></td><td><?php echo (int) $link['clicks']; ?></td><td><?php echo (int) $link['people']; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 class="font-medium mb-2">Recipients</h3>
    <div class="flex flex-wrap gap-2 mb-2">
        <?php foreach ($filters as $value => $label): ?>
            <a href="<?php echo adminUrl('MassMessages/tracking/') . $id . ($value !== '' ? '?show=' . $value : ''); ?>" class="<?php echo $value === $show ? 'btn btn-primary btn-sm' : 'btn btn-outline btn-sm'; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
    <table class="table w-full">
        <thead><tr><th>Recipient</th><th>Opens</th><th>Provider fetches</th><th>Clicks</th><th>First opened</th><th>First clicked</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $row): ?>
            <tr>
                <td><?php echo $e($row['recipient']); ?></td>
                <td><?php echo (int) $row['opens']; ?></td>
                <td><?php echo (int) $row['proxy_opens']; ?></td>
                <td><?php echo (int) $row['clicks']; ?></td>
                <td><?php echo $when($row['first_open_at']); ?></td>
                <td><?php echo $when($row['first_click_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recipients === []): ?>
            <tr><td colspan="6" class="text-sm opacity-70">Nobody.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
