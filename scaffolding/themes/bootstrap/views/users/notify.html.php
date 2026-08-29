<?php
/**
 * Send one user a message (Bootstrap theme).
 *
 * Variables:
 *   $this->user      — ['userid', 'username', 'email', 'firstname', 'lastname']
 *   $this->channels  — ['mail'|'database'|'push' => ['available' => bool, 'reason' => string]]
 *   $this->templates — mail wrapper names this installation can render
 *   $this->lists     — unsubscribe list names that have records, plus 'all'
 *   $this->tracking  — whether this installation has open/click tracking switched on at all
 *
 * The address is shown and not editable: this form exists so that a message to an account
 * goes to *that account's* address and is recorded against it. An operator who needs to
 * write somewhere else has a mail client.
 *
 * A channel this account cannot receive is shown disabled **with its reason**, rather than
 * hidden. Hidden, an operator wonders where push went; disabled and unexplained, they wonder
 * whose fault it is — and "no browser has subscribed" and "this installation has no key pair"
 * are two entirely different problems.
 */
$u    = is_array($this->user ?? null) ? $this->user : [];
$uid  = (int) ($u['userid'] ?? 0);
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$name = trim((string) ($u['firstname'] ?? '') . ' ' . (string) ($u['lastname'] ?? ''));

$channels  = is_array($this->channels ?? null) ? $this->channels : [];
$templates = is_array($this->templates ?? null) ? $this->templates : [];
$lists     = is_array($this->lists ?? null) ? $this->lists : [];
$tracking  = (bool) ($this->tracking ?? false);

$labels = [
    'mail'     => ['Email', 'To the address on the account.'],
    'database' => ['Notification', 'A record in the application, readable next time they sign in.'],
    'push'     => ['Push', 'On the device, even with the browser closed.'],
];
?>
<div class="container py-4" style="max-width:860px">
    <?php $this->activeNav = 'users_notify'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Message <?php echo $e($u['username'] ?? ''); ?></h2>
    </div>

    <div class="card">
        <div class="card-header small">
            To
            <span class="fw-medium"><?php echo $e($name !== '' ? $name : ($u['username'] ?? '')); ?></span>
            <span class="text-muted small">&lt;<?php echo $e($u['email'] ?? ''); ?>&gt;</span>
        </div>
        <form method="post" action="<?php echo adminUrl('users/sendnotification/' . $uid); ?>" class="p-4">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>

            <fieldset class="mb-4">
                <legend class="form-label">Send on</legend>
                <div class="row g-2">
                    <?php foreach ($labels as $key => [$title, $hint]):
                        $state     = $channels[$key] ?? ['available' => false, 'reason' => ''];
                        $available = (bool) ($state['available'] ?? false);
                        ?>
                        <div class="col-sm-4">
                            <label class="border rounded p-3 d-flex gap-2 h-100 <?php echo $available ? '' : 'opacity-50'; ?>">
                                <input type="checkbox" name="channels[]" value="<?php echo $e($key); ?>"
                                       class="form-check-input mt-1"
                                       <?php echo $key === 'mail' && $available ? 'checked' : ''; ?>
                                       <?php echo $available ? '' : 'disabled'; ?>>
                                <span>
                                    <span class="d-block fw-medium"><?php echo $e($title); ?></span>
                                    <span class="d-block text-muted small">
                                        <?php echo $e($available ? $hint : (string) ($state['reason'] ?? '')); ?>
                                    </span>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="mb-3">
                <label class="form-label" for="pf-message-subject">Subject</label>
                <input type="text" id="pf-message-subject" name="subject" class="form-control form-control-sm" required
                       maxlength="200">
                <p class="form-text">The mail subject, and the push notification's title.</p>
            </div>

            <div class="mb-3">
                <label class="form-label" for="pf-message-body">Message</label>
                <textarea id="pf-message-body" name="message" class="form-control" rows="8" required></textarea>
                <p class="form-text">
                    Sent as text — line breaks are kept, markup is not. What was sent, on which
                    channels, and by whom, is recorded on the account's activity log.
                </p>
            </div>

            <div class="mb-3">
                <label class="form-label" for="pf-message-link">Link <span class="text-muted">(optional)</span></label>
                <input type="url" id="pf-message-link" name="link" class="form-control form-control-sm"
                       placeholder="https://">
                <p class="form-text">
                    Where a push notification opens, and the address stored with the in-app record.
                </p>
            </div>

            <details class="border rounded mb-4">
                <summary class="px-3 py-2 fw-medium">Email options</summary>
                <div class="p-3 border-top row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="pf-message-template">Wrapper</label>
                        <select id="pf-message-template" name="template" class="form-select form-select-sm">
                            <option value="__default__">The installation's default</option>
                            <option value="">None — send the body bare</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $e($template); ?>"><?php echo $e($template); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label" for="pf-message-list">Unsubscribe list</label>
                        <input list="pf-message-lists" id="pf-message-list" name="list"
                               class="form-control form-control-sm" placeholder="none — transactional">
                        <datalist id="pf-message-lists">
                            <?php foreach ($lists as $list): ?>
                                <option value="<?php echo $e($list); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <p class="form-text">
                            Named, the message carries a working unsubscribe and is <em>not</em> sent to
                            somebody who opted out of that list. Empty means transactional — which is
                            right for "we locked your account" and wrong for anything promotional.
                        </p>
                    </div>

                    <div class="col-12">
                        <label class="d-flex gap-2 <?php echo $tracking ? '' : 'opacity-50'; ?>">
                            <input type="checkbox" name="tracking" value="1" class="form-check-input mt-1"
                                   <?php echo $tracking ? '' : 'disabled'; ?>>
                            <span>
                                Track opens and clicks
                                <span class="d-block text-muted small">
                                    <?php if ($tracking): ?>
                                        Only takes effect for a message that names a list — tracking a
                                        transactional mail is tracking somebody who never agreed to it.
                                    <?php else: ?>
                                        Switched off for this installation.
                                    <?php endif; ?>
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="col-12">
                        <div class="form-label">Gmail action</div>
                        <div class="row g-2">
                            <div class="col-sm-4">
                                <select name="action_type" class="form-select form-select-sm">
                                    <option value="">No action</option>
                                    <option value="view">View</option>
                                    <option value="confirm">Confirm</option>
                                    <option value="save">Save</option>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <input type="text" name="action_name" class="form-control form-control-sm"
                                       placeholder="Button text" maxlength="60">
                            </div>
                            <div class="col-sm-4">
                                <input type="url" name="action_url" class="form-control form-control-sm"
                                       placeholder="https://">
                            </div>
                        </div>
                        <p class="form-text">
                            Gmail shows nothing until the sending domain is registered with Google, so
                            this is safe to send and will look like an ordinary mail until then.
                            <strong>Confirm</strong> must act on the first request, without a page and
                            without a login — Gmail's server follows it, not the reader.
                        </p>
                    </div>
                </div>
            </details>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary gap-2">
                    <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send
                </button>
                <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
