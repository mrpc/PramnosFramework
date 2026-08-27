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
$esc  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($subject ?? $name); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:8px;border:1px solid #e5e7eb;">
                <?php if ($name !== ''): ?>
                <tr>
                    <td style="padding:20px 28px;border-bottom:1px solid #e5e7eb;font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:bold;color:#111827;">
                        <?php if ($url !== ''): ?>
                        <a href="<?php echo $esc($url); ?>" style="color:#111827;text-decoration:none;"><?php echo $esc($name); ?></a>
                        <?php else: ?>
                        <?php echo $esc($name); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding:28px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">
                        <?php echo $content; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px;border-top:1px solid #e5e7eb;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">
                        <?php echo $esc($name !== '' ? $name . ' · ' : ''); ?><?php echo $esc($year ?? ''); ?><br>
                        This message was sent to you because of an action on your account.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
