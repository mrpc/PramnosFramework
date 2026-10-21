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
<div class="max-w-6xl mx-auto py-6 px-4">
    <?php
    /*
     * A trail, like every other screen in this area. The preview had a Back button and no
     * breadcrumb, so it was the one page here that could not say where it sat — and "Back"
     * only helps somebody who arrived the way the button assumes.
     */
    $this->activeNav = 'emails_show';
    $this->insert('../partials/admin_breadcrumb');
    ?>
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo htmlspecialchars((string) ($this->backUrl ?? adminUrl('Emails')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline btn-xs">&larr; <?php echo htmlspecialchars(t('Back'), ENT_QUOTES, 'UTF-8'); ?></a>
        <h2 ><?php echo htmlspecialchars(t('Email #%d', (int) ($mail['id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4">
        <div class="p-5">
            <?php /* Two columns and label-above-value: four short facts were four full-width
                     rows, and the header pushed the message itself below the fold before the
                     message had lost any width at all. */ ?>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
                <div class="sm:col-span-2">
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide"><?php echo htmlspecialchars(t('Subject'), ENT_QUOTES, 'UTF-8'); ?></dt>
                    <dd class="break-words"><?php echo htmlspecialchars($mail['subject'] ?? ''); ?></dd>
                </div>
                <div>
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide"><?php echo htmlspecialchars(t('To'), ENT_QUOTES, 'UTF-8'); ?></dt>
                    <dd class="break-all"><?php
                        echo htmlspecialchars($recipient);
                        echo $toName !== '' ? ' (' . htmlspecialchars($toName) . ')' : '';
                    ?></dd>
                </div>
                <div>
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide"><?php echo htmlspecialchars(t('Date'), ENT_QUOTES, 'UTF-8'); ?></dt>
                    <dd><?php echo htmlspecialchars($sentAt); ?>
                        <?php echo $this->statusBadge ?? ''; ?>
                    </dd>
                </div>
            </dl>
            <?php
            /* Whether mail still goes to this address ({@see \Pramnos\Email\Bounces}): a bounce or a
               block stops every message to it, a password reset included. */
            $bounce = $this->bounce ?? null;
            if (is_array($bounce)): ?>
                <form method="post" action="<?php echo htmlspecialchars($bounce['action'], ENT_QUOTES); ?>" class="flex flex-wrap items-center gap-2 mt-4 text-sm">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($bounce['csrf'], ENT_QUOTES); ?>">
                    <?php if ($bounce['refusal'] !== null): ?>
                        <span class="text-error"><?php echo htmlspecialchars(t('Mail to this address is not sent:'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($bounce['refusal']); ?>.</span>
                        <button type="submit" name="op" value="clear" class="btn btn-outline btn-xs"><?php echo htmlspecialchars(t('Send to it again'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php else: ?>
                        <span class="text-success"><?php echo htmlspecialchars(t('Mail to this address is sent.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <input type="text" name="reason" placeholder="<?php echo htmlspecialchars(t('Why (optional)'), ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" class="input input-bordered input-xs">
                        <button type="submit" name="op" value="block" class="btn btn-outline btn-error btn-xs"><?php echo htmlspecialchars(t('Stop all mail to it'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    /*
     * Tabs, not a sidebar.
     *
     * The findings were beside the message, and they took a third of the width from the one
     * thing on the page that has a designed width: an email is laid out for about 600 pixels
     * and there is nothing useful to do with less. The result was a squeezed message with
     * "Not tracked." next to it — a permanent cost paid for a panel that is usually one line
     * saying nothing happened.
     *
     * So the message gets the page, and each finding gets the page when it is asked for. They
     * are consulted, not read: somebody opens this screen to see the mail, and goes to Tracking
     * when they have a question about tracking.
     *
     * Radio inputs rather than script — daisyUI's own tab pattern. This screen is inside the
     * administration area under a CSP, and a tab strip is not worth a nonce.
     */
    $report = $this->report ?? null;

    if ($report !== null) {
        $tracking = $report->tracking();
        $blocks   = $report->structuredData();
        $links    = $report->links();
        $unsub    = $report->unsubscribe();
        $when     = static fn (int $at): string => $at > 0 ? date('j M Y, H:i', $at) : '—';

        /*
         * The count in the label, because it is the answer for most messages.
         *
         * "Tracking" tells you to open the tab; "Tracking · off" tells you not to. A tab strip
         * whose labels carry no state is a strip somebody has to click through every time.
         */
        $trackingLabel = !empty($tracking['recorded'])
            ? 'Tracking · ' . (int) $tracking['clicks'] . ' clicked'
            : 'Tracking · off';
        $actionsLabel  = 'Gmail actions · ' . ($blocks === [] ? 'none' : count($blocks));
        $linksLabel    = 'Links · ' . count($links);
    }
    ?>

    <div role="tablist" class="tabs tabs-lift">
        <input type="radio" name="pf-mail-tabs" role="tab" class="tab" aria-label="Message" checked>
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            <?php if ($body !== ''): ?>
                <?php /* Sandboxed: the body is whatever was queued, and this screen is inside
                         the administration area. `allow-same-origin` is deliberately absent, so
                         the frame cannot reach this page's DOM or cookies — nor this page the
                         frame's, so its height is fixed and the reader drags it taller. */ ?>
                <?php /* Popups allowed and nothing else: a link opens in a window of its own (the
                         preview carries `<base target="_blank">`), scripts stay off, and the
                         preview has no tracking pixel ({@see \Pramnos\Email\MessageReport::preview()}). */ ?>
                <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?php echo htmlspecialchars((string) ($this->preview ?? $body), ENT_QUOTES, 'UTF-8'); ?>"
                    style="width:100%;border:none;height:600px;resize:vertical;overflow:auto"></iframe>
            <?php else: ?>
                <p class="text-base-content/70">No body content.</p>
            <?php endif; ?>
        </div>

        <?php if ($report !== null): ?>
        <?php /* A tracked message's figures change while this page is open — following one of its
                 links from the preview is a click — so they are fetched again when the reader
                 comes back to the page (`data-pf-refresh-on-return`, pf-utils.js). */
        $refresh = !empty($tracking['recorded']) ? ' data-pf-refresh-on-return' : ''; ?>
        <input type="radio" name="pf-mail-tabs" role="tab" class="tab" id="pf-mail-tracking-tab"<?php echo $refresh; ?>
               aria-label="<?php echo htmlspecialchars($trackingLabel, ENT_QUOTES); ?>">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5" id="pf-mail-tracking"<?php echo $refresh; ?>>
            <div class="max-w-2xl text-sm space-y-3">
                <?php if (!empty($tracking['recorded'])): ?>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-success"><?php echo (int) $tracking['clicks']; ?> clicked</span>
                        <span class="badge"><?php echo (int) $tracking['opens']; ?> opened</span>
                        <span class="badge badge-ghost"><?php echo (int) $tracking['proxyOpens']; ?> prefetched</span>
                    </div>
                    <p class="opacity-70 text-xs">
                        Prefetched means a mailbox provider fetched the image on delivery — not
                        somebody reading it, which is why it is counted apart.
                    </p>
                    <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2 text-xs">
                        <div><dt class="inline font-semibold">List:</dt>
                            <dd class="inline"><?php echo htmlspecialchars((string) ($tracking['list'] ?? '')); ?></dd></div>
                        <div><dt class="inline font-semibold">First opened:</dt>
                            <dd class="inline"><?php echo $when((int) ($tracking['firstOpenAt'] ?? 0)); ?></dd></div>
                        <div><dt class="inline font-semibold">Last opened:</dt>
                            <dd class="inline"><?php echo $when((int) ($tracking['lastOpenAt'] ?? 0)); ?></dd></div>
                        <div><dt class="inline font-semibold">First clicked:</dt>
                            <dd class="inline"><?php echo $when((int) ($tracking['firstClickAt'] ?? 0)); ?></dd></div>
                    </dl>
                <?php else: ?>
                    <p class="opacity-70">Not tracked.</p>
                <?php endif; ?>

                <p class="text-xs opacity-70">
                    Pixel in the body: <?php echo !empty($tracking['pixel']) ? 'yes' : 'no'; ?> ·
                    wrapped links: <?php echo (int) ($tracking['wrappedLinks'] ?? 0); ?>
                </p>

                <?php if (!empty($tracking['note'])): ?>
                    <div role="status" class="alert alert-warning py-2">
                        <span class="text-xs"><?php echo htmlspecialchars($tracking['note']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <input type="radio" name="pf-mail-tabs" role="tab" class="tab"
               aria-label="<?php echo htmlspecialchars($actionsLabel, ENT_QUOTES); ?>">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            <div class="max-w-2xl text-sm">
                <?php if ($blocks === []): ?>
                    <p class="opacity-70">None. No button is drawn in the message list.</p>
                <?php else: foreach ($blocks as $block): ?>
                    <div class="mb-3">
                        <span class="badge badge-outline badge-sm"><?php echo htmlspecialchars((string) $block['type']); ?></span>
                        <?php if (!empty($block['description'])): ?>
                            <span class="opacity-70 text-xs"><?php echo htmlspecialchars($block['description']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($block['actions'])): ?>
                            <ul class="mt-2 space-y-1 text-xs">
                                <?php foreach ($block['actions'] as $action): ?>
                                    <li>
                                        <code><?php echo htmlspecialchars((string) ($action['action'] ?? '')); ?></code>
                                        <?php echo htmlspecialchars((string) ($action['name'] ?? '')); ?>
                                        <?php if (!empty($action['url'])): ?>
                                            <span class="block break-all opacity-70"><?php echo htmlspecialchars($action['url']); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($block['raw'])): ?>
                            <p class="text-error text-xs">Unreadable JSON — Gmail ignores it silently.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
                <p class="opacity-70 text-xs mt-2">
                    Nothing shows until the sending domain is registered with Google.
                </p>
            </div>
        </div>

        <input type="radio" name="pf-mail-tabs" role="tab" class="tab"
               aria-label="<?php echo htmlspecialchars($linksLabel, ENT_QUOTES); ?>">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            <div class="max-w-3xl text-sm">
                <?php if ($links === []): ?>
                    <p class="opacity-70">No links in this message.</p>
                <?php else: ?>
                    <?php /* The width is worth having here: a wrapped link's real destination is
                             a long URL, and wrapping it over four lines is what made this panel
                             unreadable in a sidebar. */ ?>
                    <table class="table table-sm">
                        <tbody>
                        <?php foreach ($links as $link): ?>
                            <tr>
                                <td class="break-all"><?php
                                    echo htmlspecialchars((string) ($link['destination'] ?? $link['url']));
                                ?></td>
                                <td class="whitespace-nowrap text-right">
                                    <?php if (!empty($link['broken'])): ?>
                                        <span class="badge badge-error badge-xs">token does not verify</span>
                                    <?php elseif (!empty($link['wrapped'])): ?>
                                        <span class="badge badge-ghost badge-xs">tracked</span>
                                    <?php endif; ?>
                                    <?php if ((int) $link['count'] > 1): ?>
                                        <span class="opacity-60">×<?php echo (int) $link['count']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (!empty($unsub['note'])): ?>
                    <p class="opacity-70 text-xs mt-2"><?php echo htmlspecialchars($unsub['note']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <input type="radio" name="pf-mail-tabs" role="tab" class="tab" aria-label="Plain text">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            <?php /* The half nobody looks at — and the half that used to arrive as the
                     stylesheet with every link removed. */ ?>
            <pre class="text-xs whitespace-pre-wrap break-words opacity-90 max-w-3xl"><?php
                echo htmlspecialchars($report->plainText());
            ?></pre>
        </div>
        <?php endif; ?>
    </div>
</div>
