<?php
/**
 * Email detail view (Bootstrap theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];

/*
 * The column names, read from the table rather than guessed — see the note in the tailwind
 * copy of this screen. `mails` has `tomail`, `toname`, `content` and an integer `date`.
 */
$recipient = (string) ($mail['tomail'] ?? '');
$body      = (string) ($mail['content'] ?? $mail['body'] ?? $mail['mailbody'] ?? '');
$rawDate   = $mail['date'] ?? '';
$sentAt    = is_numeric($rawDate) && (int) $rawDate > 0
    ? date('j M Y, H:i', (int) $rawDate)
    : (string) $rawDate;
?>
<div class="container py-4" style="max-width:860px">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?php echo htmlspecialchars((string) ($this->backUrl ?? adminUrl('Emails')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">&larr; <?php echo htmlspecialchars(t('Back'), ENT_QUOTES, 'UTF-8'); ?></a>
        <h2 class="mb-0"><?php echo htmlspecialchars(t('Email #%d', (int) ($mail['id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-2"><?php echo htmlspecialchars(t('To'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd class="col-sm-10"><?php echo htmlspecialchars($recipient); ?></dd>
                <dt class="col-sm-2"><?php echo htmlspecialchars(t('Subject'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd class="col-sm-10"><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                <dt class="col-sm-2"><?php echo htmlspecialchars(t('Date'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd class="col-sm-10"><?php echo htmlspecialchars($sentAt); ?></dd>
                <dt class="col-sm-2"><?php echo htmlspecialchars(t('Status'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd class="col-sm-10"><?php echo $this->statusBadge ?? ''; ?></dd>
            </dl>
            <?php
            /* Whether mail still goes to this address ({@see \Pramnos\Email\Bounces}): a bounce or a
               block stops every message to it, a password reset included. */
            $bounce = $this->bounce ?? null;
            if (is_array($bounce)): ?>
                <form method="post" action="<?php echo htmlspecialchars($bounce['action'], ENT_QUOTES); ?>" class="d-flex flex-wrap align-items-center gap-2 mt-3">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($bounce['csrf'], ENT_QUOTES); ?>">
                    <?php if ($bounce['refusal'] !== null): ?>
                        <span class="text-danger"><?php echo htmlspecialchars(t('Mail to this address is not sent:'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($bounce['refusal']); ?>.</span>
                        <button type="submit" name="op" value="clear" class="btn btn-sm btn-outline-secondary"><?php echo htmlspecialchars(t('Send to it again'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php else: ?>
                        <span class="text-success"><?php echo htmlspecialchars(t('Mail to this address is sent.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <input type="text" name="reason" placeholder="<?php echo htmlspecialchars(t('Why (optional)'), ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" class="form-control form-control-sm w-auto">
                        <button type="submit" name="op" value="block" class="btn btn-sm btn-outline-danger"><?php echo htmlspecialchars(t('Stop all mail to it'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header fw-semibold">Body</div>
        <div class="card-body">
            <?php if ($body !== ''): ?>
                <iframe sandbox srcdoc="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>"
                    style="width:100%;border:none;height:400px;resize:vertical;overflow:auto"></iframe>
            <?php else: ?>
                <p class="text-muted">No body content.</p>
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
