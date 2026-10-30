<?php
/**
 * Email detail view (plain-CSS theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];
$h    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

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
<div class="page-section" style="max-width:860px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo htmlspecialchars((string) ($this->backUrl ?? adminUrl('Emails')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">&larr; <?php echo htmlspecialchars(t('Back'), ENT_QUOTES, 'UTF-8'); ?></a>
        <h2 ><?php echo htmlspecialchars(t('Email #%d', (int) ($mail['id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <dl >
                <dt style="font-weight:600;min-width:120px;display:inline-block"><?php echo htmlspecialchars(t('To'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd ><?php echo htmlspecialchars($recipient); ?></dd>
                <?php if (!empty($this->origin)): $o = $this->origin; ?>
                <dt style="font-weight:600;min-width:120px;display:inline-block"><?php echo htmlspecialchars(t('Sent by'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd data-field="origin"><?php $what = htmlspecialchars(t('rule %s, version %s, step %s', (string) ($o['name'] ?? '#' . $o['rule']), (string) $o['version'], $o['step']), ENT_QUOTES, 'UTF-8');
                    echo $o['url'] !== null ? '<a href="' . htmlspecialchars($o['url'], ENT_QUOTES, 'UTF-8') . '">' . $what . '</a>' : $what; ?></dd>
                <?php endif; ?>
                <dt style="font-weight:600;min-width:120px;display:inline-block"><?php echo htmlspecialchars(t('Subject'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd ><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block"><?php echo htmlspecialchars(t('Date'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd ><?php echo htmlspecialchars($sentAt); ?></dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block"><?php echo htmlspecialchars(t('Status'), ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd ><?php echo $this->statusBadge ?? ''; ?></dd>
            </dl>
            <?php
            /* Whether mail still goes to this address ({@see \Pramnos\Email\Bounces}): a bounce or a
               block stops every message to it, a password reset included. */
            $bounce = $this->bounce ?? null;
            if (is_array($bounce)): ?>
                <form method="post" action="<?php echo htmlspecialchars($bounce['action'], ENT_QUOTES); ?>" >
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($bounce['csrf'], ENT_QUOTES); ?>">
                    <?php if ($bounce['refusal'] !== null): ?>
                        <span ><?php echo htmlspecialchars(t('Mail to this address is not sent:'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($bounce['refusal']); ?>.</span>
                        <button type="submit" name="op" value="clear" class="btn btn-sm"><?php echo htmlspecialchars(t('Send to it again'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php else: ?>
                        <span ><?php echo htmlspecialchars(t('Mail to this address is sent.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <input type="text" name="reason" placeholder="<?php echo htmlspecialchars(t('Why (optional)'), ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" >
                        <button type="submit" name="op" value="block" class="btn btn-sm"><?php echo htmlspecialchars(t('Stop all mail to it'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd"><?php echo $h(t('Body')); ?></div>
        <div class="card-body" style="padding:16px">
            <?php if ($body !== ''): ?>
                <?php /* Popups allowed and nothing else: a link opens in a window of its own (the
                         preview carries `<base target="_blank">`), scripts stay off, and the
                         preview has no tracking pixel ({@see \Pramnos\Email\MessageReport::preview()}). */ ?>
                <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?php echo htmlspecialchars((string) ($this->preview ?? $body), ENT_QUOTES, 'UTF-8'); ?>"
                    style="width:100%;border:none;height:400px;resize:vertical;overflow:auto"></iframe>
            <?php else: ?>
                <p style="color:#888"><?php echo $h(t('No body content.')); ?></p>
            <?php endif; ?>
        </div>
    </div>

<?php
/*
 * Everything else known about this message, read out of the stored body rather than out of the
 * sending code: a template that lost its unsubscribe link and one that kept it are identical
 * from there. In cards inside the page's column, as the rest of this screen; a link breaks
 * anywhere, because an unsubscribe link is one word of two hundred characters.
 */
$report = $this->report ?? null;

if ($report !== null):
    $tracking = $report->tracking();
    $when = static fn (int $at): string => $at > 0 ? date('j M Y, H:i', $at) : '—';
?>
    <?php /* Fetched again when the reader comes back to the page, for a tracked message: following
             one of its links from the preview is a click (`data-pf-refresh-on-return`, pf-utils.js). */ ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px" id="pf-mail-tracking"<?php echo !empty($tracking['recorded']) ? ' data-pf-refresh-on-return' : ''; ?>>
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd"><?php echo $h(t('Tracking')); ?></div>
        <div class="card-body" style="padding:16px">
        <?php if (!empty($tracking['recorded'])): ?>
            <p>
                <strong><?php echo (int) $tracking['clicks']; ?></strong> <?php echo $h(t('clicked')); ?> &middot;
                <strong><?php echo (int) $tracking['opens']; ?></strong> <?php echo $h(t('opened')); ?> &middot;
                <strong><?php echo (int) $tracking['proxyOpens']; ?></strong> <?php echo $h(t('prefetched')); ?>
            </p>
            <p style="color:#888;font-size:0.875em"><?php echo $h(t('Prefetched means a mailbox provider fetched the image on delivery. It is not somebody reading the message, which is why it is counted apart.')); ?></p>
            <p>
                <?php echo $h(t('First opened')); ?> <?php echo $h($when((int) ($tracking['firstOpenAt'] ?? 0))); ?>,
                <?php echo $h(t('last opened')); ?> <?php echo $h($when((int) ($tracking['lastOpenAt'] ?? 0))); ?>,
                <?php echo $h(t('first clicked')); ?> <?php echo $h($when((int) ($tracking['firstClickAt'] ?? 0))); ?>.
            </p>
        <?php else: ?>
            <p><?php echo $h(t('Not tracked.')); ?></p>
        <?php endif; ?>
            <p style="margin:0">
                <?php echo $h(t('Pixel in the body')); ?>: <?php echo $h(!empty($tracking['pixel']) ? t('yes') : t('no')); ?>.
                <?php echo $h(t('Wrapped links')); ?>: <?php echo (int) ($tracking['wrappedLinks'] ?? 0); ?>.
            </p>
        <?php if (!empty($tracking['note'])): ?>
            <p style="color:#888;font-size:0.875em;margin:8px 0 0"><em><?php echo $h(t($tracking['note'])); ?></em></p>
        <?php endif; ?>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd"><?php echo $h(t('Structured data (Gmail actions and highlights)')); ?></div>
        <div class="card-body" style="padding:16px">
        <?php $blocks = $report->structuredData(); ?>
        <?php if ($blocks === []): ?>
            <p><?php echo $h(t('None. Gmail draws no button in the message list for this mail.')); ?></p>
        <?php else: foreach ($blocks as $block): ?>
            <p>
                <code><?php echo $h((string) $block['type']); ?></code>
                <?php echo $h((string) ($block['description'] ?? $block['name'] ?? '')); ?>
            </p>
            <?php if (!empty($block['actions'])): ?>
                <ul>
                <?php foreach ($block['actions'] as $action): ?>
                    <li style="overflow-wrap:anywhere">
                        <code><?php echo $h((string) ($action['action'] ?? '')); ?></code>
                        <?php echo $h((string) ($action['name'] ?? '')); ?>
                        <?php if (!empty($action['url'])): ?>&rarr; <?php echo $h($action['url']); ?><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($block['raw'])): ?>
                <p><strong><?php echo $h(t('Unreadable JSON — Gmail ignores it silently.')); ?></strong></p>
            <?php endif; ?>
        <?php endforeach; endif; ?>
            <p style="color:#888;font-size:0.875em;margin:0"><?php echo $h(t('Gmail shows none of this until the sending domain is registered with Google.')); ?></p>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd"><?php echo $h(t('Links, and where they really go')); ?></div>
        <div class="card-body" style="padding:16px">
        <?php $links = $report->links(); ?>
        <?php if ($links === []): ?>
            <p style="margin:0"><?php echo $h(t('No links in this message.')); ?></p>
        <?php else: ?>
            <?php // Fixed layout and breaking anywhere: an unsubscribe link is one 200-character word. ?>
            <table style="table-layout:fixed;width:100%;border-collapse:collapse">
                <thead><tr><th><?php echo $h(t('In the markup')); ?></th><th><?php echo $h(t('Goes to')); ?></th><th style="width:7rem"></th></tr></thead>
                <tbody>
                <?php foreach ($links as $link): ?>
                    <tr>
                        <td style="overflow-wrap:anywhere"><?php echo $h((string) $link['url']); ?></td>
                        <td style="overflow-wrap:anywhere"><?php echo $h((string) ($link['destination'] ?? $link['url'])); ?></td>
                        <td>
                            <?php if (!empty($link['broken'])): ?><?php echo $h(t('token does not verify')); ?>
                            <?php elseif (!empty($link['wrapped'])): ?><?php echo $h(t('tracked')); ?><?php endif; ?>
                            <?php if ((int) $link['count'] > 1): ?> &times;<?php echo (int) $link['count']; ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php $unsub = $report->unsubscribe(); ?>
        <?php if (!empty($unsub['note'])): ?>
            <p style="color:#888;font-size:0.875em;margin:8px 0 0"><em><?php echo $h(t($unsub['note'])); ?></em></p>
        <?php endif; ?>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd"><?php echo $h(t('As a text-only client shows it')); ?></div>
        <div class="card-body" style="padding:16px">
            <?php /* The half nobody looks at, and the half that used to arrive as the stylesheet with every
                     link removed. A text part that does not match the HTML is a documented spam signal. */ ?>
            <pre style="margin:0;white-space:pre-wrap;overflow-wrap:anywhere"><?php echo $h($report->plainText()); ?></pre>
        </div>
    </div>
<?php endif; ?>
</div>
