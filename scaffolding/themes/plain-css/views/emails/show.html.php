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
/*
 * The body, wherever it is stored.
 *
 * Inline in `content`, or a gzipped file the row points at. Read through `BodyStore` so that an
 * installation which moved its bodies out of the database sees exactly this screen — which is
 * the entire difference between archiving a body and emptying the column.
 */
$body      = \Pramnos\Email\BodyStore::bodyOf($mail);
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

<?php
/*
 * Everything else known about this message.
 *
 * Read out of the stored body rather than out of the sending code: a template that lost its
 * unsubscribe link and one that kept it are identical from there. Rendered plainly here — this
 * theme has no component library to lean on, and the facts are the point.
 */
$report = $this->report ?? null;

if ($report !== null):
    $tracking = $report->tracking();
    $when = static fn (int $at): string => $at > 0 ? date('j M Y, H:i', $at) : '\u2014';
?>
<h3>Tracking</h3>
<?php if (!empty($tracking['recorded'])): ?>
    <p>
        <strong><?php echo (int) $tracking['clicks']; ?></strong> clicked &middot;
        <strong><?php echo (int) $tracking['opens']; ?></strong> opened &middot;
        <strong><?php echo (int) $tracking['proxyOpens']; ?></strong> prefetched
    </p>
    <p>
        Prefetched means a mailbox provider fetched the image on delivery. It is not somebody
        reading the message, which is why it is counted apart.
    </p>
    <p>
        First opened <?php echo $when((int) ($tracking['firstOpenAt'] ?? 0)); ?>,
        last opened <?php echo $when((int) ($tracking['lastOpenAt'] ?? 0)); ?>,
        first clicked <?php echo $when((int) ($tracking['firstClickAt'] ?? 0)); ?>.
    </p>
<?php else: ?>
    <p>Not tracked.</p>
<?php endif; ?>
<p>
    Pixel in the body: <?php echo !empty($tracking['pixel']) ? 'yes' : 'no'; ?>.
    Wrapped links: <?php echo (int) ($tracking['wrappedLinks'] ?? 0); ?>.
</p>
<?php if (!empty($tracking['note'])): ?>
    <p><em><?php echo htmlspecialchars($tracking['note']); ?></em></p>
<?php endif; ?>

<h3>Structured data (Gmail actions and highlights)</h3>
<?php $blocks = $report->structuredData(); ?>
<?php if ($blocks === []): ?>
    <p>None. Gmail draws no button in the message list for this mail.</p>
<?php else: foreach ($blocks as $block): ?>
    <p>
        <code><?php echo htmlspecialchars((string) $block['type']); ?></code>
        <?php echo htmlspecialchars((string) ($block['description'] ?? $block['name'] ?? '')); ?>
    </p>
    <?php if (!empty($block['actions'])): ?>
        <ul>
        <?php foreach ($block['actions'] as $action): ?>
            <li>
                <code><?php echo htmlspecialchars((string) ($action['action'] ?? '')); ?></code>
                <?php echo htmlspecialchars((string) ($action['name'] ?? '')); ?>
                <?php if (!empty($action['url'])): ?>
                    &rarr; <?php echo htmlspecialchars($action['url']); ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($block['raw'])): ?>
        <p><strong>Unreadable JSON — Gmail ignores it silently.</strong></p>
    <?php endif; ?>
<?php endforeach; endif; ?>
<p>Gmail shows none of this until the sending domain is registered with Google.</p>

<h3>Links, and where they really go</h3>
<?php $links = $report->links(); ?>
<?php if ($links === []): ?>
    <p>No links in this message.</p>
<?php else: ?>
    <table>
        <thead><tr><th>In the markup</th><th>Goes to</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($links as $link): ?>
            <tr>
                <td><?php echo htmlspecialchars((string) $link['url']); ?></td>
                <td><?php echo htmlspecialchars((string) ($link['destination'] ?? $link['url'])); ?></td>
                <td>
                    <?php if (!empty($link['broken'])): ?>token does not verify
                    <?php elseif (!empty($link['wrapped'])): ?>tracked<?php endif; ?>
                    <?php if ((int) $link['count'] > 1): ?> &times;<?php echo (int) $link['count']; ?><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php $unsub = $report->unsubscribe(); ?>
<?php if (!empty($unsub['note'])): ?>
    <p><em><?php echo htmlspecialchars($unsub['note']); ?></em></p>
<?php endif; ?>

<h3>As a text-only client shows it</h3>
<?php /* The half nobody looks at, and the half that used to arrive as the stylesheet with every
         link removed. A text part that does not match the HTML is a documented spam signal. */ ?>
<pre style="white-space:pre-wrap;word-break:break-word"><?php echo htmlspecialchars($report->plainText()); ?></pre>
<?php endif; ?>
