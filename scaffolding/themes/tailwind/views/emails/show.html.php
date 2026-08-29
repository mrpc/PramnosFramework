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
    <?php
    /*
     * The message first, the facts beside it.
     *
     * Everything below was added to one column and pushed the message itself off the screen: to
     * read the mail you had come to read, you scrolled past four cards of analysis. The message
     * is why anybody opens this page — so it gets the width, and the findings sit next to it on
     * a wide screen and after it on a narrow one, which is the order they matter in.
     */
    $report = $this->report ?? null;

    if ($report !== null) {
        $tracking = $report->tracking();
        $blocks   = $report->structuredData();
        $links    = $report->links();
        $unsub    = $report->unsubscribe();
        $when     = static fn (int $at): string => $at > 0 ? date('j M Y, H:i', $at) : '—';
    }
    ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        <div class="lg:col-span-2 space-y-4">
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">
                    Message
                </div>
                <div class="p-5">
                    <?php if ($body !== ''): ?>
                        <?php /* Sandboxed: the body is whatever was queued, and this screen is
                                 inside the administration area. `allow-same-origin` is
                                 deliberately absent, so the frame cannot reach this page's DOM
                                 or cookies. */ ?>
                        <iframe sandbox srcdoc="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>"
                            style="width:100%;border:none;min-height:400px"
                            onload="this.style.height=this.contentDocument.body.scrollHeight+'px'"></iframe>
                    <?php else: ?>
                        <p class="text-base-content/70">No body content.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($report !== null): ?>
            <details class="card bg-base-100 border border-base-300 shadow-xs">
                <summary class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm cursor-pointer">
                    As a text-only client shows it
                </summary>
                <div class="p-5">
                    <?php /* Folded away by default: it is the half nobody looks at — and the half
                             that used to arrive as the stylesheet with every link removed. */ ?>
                    <pre class="text-xs whitespace-pre-wrap break-words opacity-90"><?php
                        echo htmlspecialchars($report->plainText());
                    ?></pre>
                </div>
            </details>
            <?php endif; ?>
        </div>

        <?php if ($report !== null): ?>
        <aside class="space-y-4">
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">
                    Tracking
                </div>
                <div class="p-5 text-sm space-y-2">
                    <?php if (!empty($tracking['recorded'])): ?>
                        <div class="flex flex-wrap gap-2">
                            <span class="badge badge-success"><?php echo (int) $tracking['clicks']; ?> clicked</span>
                            <span class="badge"><?php echo (int) $tracking['opens']; ?> opened</span>
                            <span class="badge badge-ghost"><?php echo (int) $tracking['proxyOpens']; ?> prefetched</span>
                        </div>
                        <p class="opacity-70 text-xs">
                            Prefetched means a mailbox provider fetched the image on delivery —
                            not somebody reading it, which is why it is counted apart.
                        </p>
                        <dl class="text-xs mt-2 space-y-1">
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
                        <div role="alert" class="alert alert-warning py-2">
                            <span class="text-xs"><?php echo htmlspecialchars($tracking['note']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">
                    Gmail actions
                </div>
                <div class="p-5 text-sm">
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

            <details class="card bg-base-100 border border-base-300 shadow-xs">
                <summary class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm cursor-pointer">
                    Links (<?php echo count($links); ?>)
                </summary>
                <div class="p-5 text-xs">
                    <?php if ($links === []): ?>
                        <p class="opacity-70">No links in this message.</p>
                    <?php else: foreach ($links as $link): ?>
                        <div class="mb-2 pb-2 border-b border-base-200 last:border-0">
                            <div class="break-all"><?php
                                echo htmlspecialchars((string) ($link['destination'] ?? $link['url']));
                            ?></div>
                            <div class="opacity-60">
                                <?php if (!empty($link['broken'])): ?>
                                    <span class="badge badge-error badge-xs">token does not verify</span>
                                <?php elseif (!empty($link['wrapped'])): ?>
                                    <span class="badge badge-ghost badge-xs">tracked</span>
                                <?php endif; ?>
                                <?php if ((int) $link['count'] > 1): ?>×<?php echo (int) $link['count']; ?><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>

                    <?php if (!empty($unsub['note'])): ?>
                        <p class="opacity-70 mt-2"><?php echo htmlspecialchars($unsub['note']); ?></p>
                    <?php endif; ?>
                </div>
            </details>
        </aside>
        <?php endif; ?>
    </div>
</div>
