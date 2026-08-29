<?php
/**
 * The bundled email wrapper.
 *
 * Copy it to `app/emails/` and edit it there — a wrapper in the application wins over this
 * one of the same name, so the copy is the customisation and this file stays the fallback.
 *
 * In scope:
 *   $content  — the message body, already HTML
 *   $subject  — the subject line
 *   $sitename, $siteurl, $year
 *   $unsubscribeUrl, $unsubscribeList — set only on mail that belongs to a list
 *   $preheader — the line the mailbox list shows beside the subject
 *   $language  — the language the message is written in, for `<html lang>`
 *
 * Written the way HTML mail has to be written, which is not how the rest of this framework
 * writes HTML: nested tables, inline attributes, no stylesheet and no class names. Outlook
 * renders with Word's engine, Gmail strips `<style>` from forwarded mail, and every layout
 * built on flex or grid collapses in at least one of them. Two tables and inline styles are
 * ugly and they arrive intact everywhere, which is the only property that matters here.
 *
 * Colours are literals rather than theme tokens for the same reason — a mail client has no
 * access to the site's stylesheet, and a token that resolved to nothing would render black
 * on black.
 */
$name = (string) ($sitename ?? '');
$url  = (string) ($siteurl ?? '');

/*
 * The visible unsubscribe line, on the messages that have one.
 *
 * Empty on transactional mail — a password reset, a second-factor code — and that is not an
 * omission. Nobody unsubscribes from being able to sign in, no mailbox provider asks you to
 * offer it there, and a link that appears on such a message teaches people that the link does
 * nothing.
 *
 * A visible link *and* the `List-Unsubscribe` headers, because Gmail and Yahoo look at both.
 * The header is what draws their own unsubscribe control; the line in the footer is what a
 * reader who does not know about that control uses instead of the spam button.
 */
$unsubscribe = (string) ($unsubscribeUrl ?? '');
$esc  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

/*
 * The preheader: the second most prominent line in the inbox, and the one nobody chooses.
 *
 * Every mailbox list prints the message's first readable text beside the subject. Without one
 * of these that is whatever the wrapper opens with — a logo's alt text, "view this in your
 * browser", the first cell of a table. `Email::preheaderText()` supplies the body's own
 * opening when nothing was set, which is at worst a repetition of what the reader is about to
 * see.
 *
 * Hidden three ways because no single one works everywhere, and padded with zero-width
 * non-joiners so the client does not follow the preheader with the *next* thing it finds —
 * which is how "Your code is 481920 View this in your browser Unsubscribe" happens.
 */
$preheaderText = trim((string) ($preheader ?? ''));
$lang = trim((string) ($language ?? '')) ?: 'en';
?>
<!DOCTYPE html>
<html lang="<?php echo $esc($lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php /*
 * Dark mode, declared rather than left to the client.
 *
 * Without these two, Apple Mail and Outlook invert the colours themselves — and their
 * inversion is per-element and unaware of images, so a dark logo on the white card it was
 * drawn for ends up black on near-black, and a border that was a hairline becomes the
 * loudest thing in the message. Declaring support means the client stops guessing and the
 * media query below decides.
 *
 * `<style>` is stripped by Gmail in a forwarded message and by a few clients outright, which
 * is why every colour that matters is *also* inline: the block below is an improvement where
 * it survives, never the only thing keeping the message readable.
 */ ?>
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?php echo $esc($subject ?? $name); ?></title>
<style>
:root { color-scheme: light dark; supported-color-schemes: light dark; }
@media (prefers-color-scheme: dark) {
    .pf-page   { background-color: #111827 !important; }
    .pf-card   { background-color: #1f2937 !important; border-color: #374151 !important; }
    .pf-head   { border-color: #374151 !important; color: #f9fafb !important; }
    .pf-head a { color: #f9fafb !important; }
    .pf-body   { color: #e5e7eb !important; }
    .pf-foot   { border-color: #374151 !important; color: #9ca3af !important; }
    .pf-foot a { color: #9ca3af !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;color-scheme:light dark;">
<?php if ($preheaderText !== ''): ?>
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f5f7;opacity:0;">
    <?php echo $esc($preheaderText); ?>
    <?php echo str_repeat('&#8204;&nbsp;', 60); ?>
</div>
<?php endif; ?>
<table role="presentation" class="pf-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" class="pf-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:8px;border:1px solid #e5e7eb;">
                <?php if ($name !== ''): ?>
                <tr>
                    <td class="pf-head" style="padding:20px 28px;border-bottom:1px solid #e5e7eb;font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:bold;color:#111827;">
                        <?php if ($url !== ''): ?>
                        <a href="<?php echo $esc($url); ?>" style="color:#111827;text-decoration:none;"><?php echo $esc($name); ?></a>
                        <?php else: ?>
                        <?php echo $esc($name); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="pf-body" style="padding:28px;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#1f2937;">
                        <?php echo $content; ?>
                    </td>
                </tr>
                <tr>
                    <td class="pf-foot" style="padding:18px 28px;border-top:1px solid #e5e7eb;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;color:#6b7280;">
                        <?php echo $esc($name !== '' ? $name . ' · ' : ''); ?><?php echo $esc($year ?? ''); ?><br>
                        <?php if ($unsubscribe !== ''): ?>
                        You are receiving this because you asked to hear from us.
                        <a href="<?php echo $esc($unsubscribe); ?>" style="color:#6b7280;">Unsubscribe</a>.
                        <?php else: ?>
                        This message was sent to you because of an action on your account.
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
