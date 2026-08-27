<?php
/**
 * Send one user a message (Bootstrap theme).
 *
 * Variables:
 *   $this->user — ['userid', 'username', 'email', 'firstname', 'lastname']
 *
 * The address is shown and not editable: this form exists so that a message to an account
 * goes to *that account's* address and is recorded against it. An operator who needs to
 * write somewhere else has a mail client.
 */
$u    = is_array($this->user ?? null) ? $this->user : [];
$uid  = (int) ($u['userid'] ?? 0);
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$name = trim((string) ($u['firstname'] ?? '') . ' ' . (string) ($u['lastname'] ?? ''));
?>
<div class="container py-4" style="max-width:720px">
    <?php $this->activeNav = 'users_notify'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Message <?php echo $e($u['username'] ?? ''); ?></h2>
    </div>

    <div class="card">
        <div class="card-header small">
            To
            <span class="font-medium"><?php echo $e($name !== '' ? $name : ($u['username'] ?? '')); ?></span>
            <span class="text-muted small">&lt;<?php echo $e($u['email'] ?? ''); ?>&gt;</span>
        </div>
        <form method="post" action="<?php echo adminUrl('users/sendnotification/' . $uid); ?>" class="p-5">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
            <div class="mb-3">
                <label class="form-label" for="pf-message-subject">Subject</label>
                <input type="text" id="pf-message-subject" name="subject" class="form-control form-control-sm" required
                       maxlength="200">
            </div>
            <div class="mb-3">
                <label class="form-label" for="pf-message-body">Message</label>
                <textarea id="pf-message-body" name="message" class="form-control" rows="8" required></textarea>
                <p class="form-text">
                    Sent as text — line breaks are kept, markup is not. What was sent, and by
                    whom, is recorded on the account's activity log.
                </p>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary  gap-2">
                    <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send
                </button>
                <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary ">Cancel</a>
            </div>
        </form>
    </div>
</div>
