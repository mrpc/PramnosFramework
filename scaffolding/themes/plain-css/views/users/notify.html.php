<?php
/**
 * Send one user a message (plain CSS theme).
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

$hint  = 'color:#666;font-size:0.8em;display:block';
$field = 'width:100%;padding:6px 8px';
$label = 'display:block;font-size:0.85em;margin-bottom:4px';
?>
<div class="page-section" style="max-width:860px">
    <?php $this->activeNav = 'users_notify'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 style="margin:0">Message <?php echo $e($u['username'] ?? ''); ?></h2>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-size:0.9em">
            To
            <span><?php echo $e($name !== '' ? $name : ($u['username'] ?? '')); ?></span>
            <span style="color:#666;font-size:0.85em">&lt;<?php echo $e($u['email'] ?? ''); ?>&gt;</span>
        </div>
        <form method="post" action="<?php echo adminUrl('users/sendnotification/' . $uid); ?>" style="padding:16px">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>

            <fieldset style="border:0;padding:0;margin:0 0 18px">
                <legend style="<?php echo $label; ?>">Send on</legend>
                <?php foreach ($labels as $key => [$title, $text]):
                    $state     = $channels[$key] ?? ['available' => false, 'reason' => ''];
                    $available = (bool) ($state['available'] ?? false);
                    ?>
                    <label style="display:flex;gap:8px;align-items:flex-start;border:1px solid #ddd;border-radius:4px;padding:10px;margin-bottom:6px;<?php echo $available ? '' : 'opacity:0.6'; ?>">
                        <input type="checkbox" name="channels[]" value="<?php echo $e($key); ?>"
                               <?php echo $key === 'mail' && $available ? 'checked' : ''; ?>
                               <?php echo $available ? '' : 'disabled'; ?>>
                        <span>
                            <strong style="font-size:0.9em"><?php echo $e($title); ?></strong>
                            <span style="<?php echo $hint; ?>">
                                <?php echo $e($available ? $text : (string) ($state['reason'] ?? '')); ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div style="margin-bottom:14px">
                <label style="<?php echo $label; ?>" for="pf-message-subject">Subject</label>
                <input type="text" id="pf-message-subject" name="subject" style="<?php echo $field; ?>" required
                       maxlength="200">
                <span style="<?php echo $hint; ?>">The mail subject, and the push notification's title.</span>
            </div>

            <div style="margin-bottom:14px">
                <label style="<?php echo $label; ?>" for="pf-message-body">Message</label>
                <textarea id="pf-message-body" name="message" style="<?php echo $field; ?>" rows="8" required></textarea>
                <span style="<?php echo $hint; ?>">
                    Sent as text — line breaks are kept, markup is not. What was sent, on which
                    channels, and by whom, is recorded on the account's activity log.
                </span>
            </div>

            <div style="margin-bottom:14px">
                <label style="<?php echo $label; ?>" for="pf-message-link">Link (optional)</label>
                <input type="url" id="pf-message-link" name="link" style="<?php echo $field; ?>" placeholder="https://">
                <span style="<?php echo $hint; ?>">
                    Where a push notification opens, and the address stored with the in-app record.
                </span>
            </div>

            <details style="border:1px solid #ddd;border-radius:4px;margin-bottom:18px">
                <summary style="padding:8px 12px;font-size:0.9em;cursor:pointer">Email options</summary>
                <div style="padding:12px;border-top:1px solid #ddd">
                    <div style="margin-bottom:14px">
                        <label style="<?php echo $label; ?>" for="pf-message-preheader">Preheader</label>
                        <input type="text" id="pf-message-preheader" name="preheader"
                               style="<?php echo $field; ?>" maxlength="120">
                        <span style="<?php echo $hint; ?>">
                            The line the inbox shows beside the subject. Left empty it is taken from
                            the message's own opening — which beats the wrapper's, but is not a
                            sentence written for the inbox.
                        </span>
                    </div>

                    <div style="margin-bottom:14px">
                        <label style="<?php echo $label; ?>" for="pf-message-template">Wrapper</label>
                        <select id="pf-message-template" name="template" style="<?php echo $field; ?>">
                            <option value="__default__">The installation's default</option>
                            <option value="">None — send the body bare</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $e($template); ?>"><?php echo $e($template); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:14px">
                        <label style="<?php echo $label; ?>" for="pf-message-list">Unsubscribe list</label>
                        <input list="pf-message-lists" id="pf-message-list" name="list" style="<?php echo $field; ?>"
                               placeholder="none — transactional">
                        <datalist id="pf-message-lists">
                            <?php foreach ($lists as $list): ?>
                                <option value="<?php echo $e($list); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <span style="<?php echo $hint; ?>">
                            Named, the message carries a working unsubscribe and is <em>not</em> sent to
                            somebody who opted out of that list. Empty means transactional — which is
                            right for "we locked your account" and wrong for anything promotional.
                        </span>
                    </div>

                    <div style="margin-bottom:14px<?php echo $tracking ? '' : ';opacity:0.6'; ?>">
                        <label style="display:flex;gap:8px;align-items:flex-start">
                            <input type="checkbox" name="tracking" value="1" <?php echo $tracking ? '' : 'disabled'; ?>>
                            <span>
                                Track opens and clicks
                                <span style="<?php echo $hint; ?>">
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

                    <div>
                        <span style="<?php echo $label; ?>">Gmail action</span>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <select name="action_type" style="padding:6px 8px">
                                <option value="">No action</option>
                                <option value="view">View</option>
                                <option value="confirm">Confirm</option>
                                <option value="save">Save</option>
                            </select>
                            <input type="text" name="action_name" style="padding:6px 8px" placeholder="Button text"
                                   maxlength="60">
                            <input type="url" name="action_url" style="padding:6px 8px;flex:1" placeholder="https://">
                        </div>
                        <span style="<?php echo $hint; ?>">
                            Gmail shows nothing until the sending domain is registered with Google, so
                            this is safe to send and will look like an ordinary mail until then.
                            <strong>Confirm</strong> must act on the first request, without a page and
                            without a login — Gmail's server follows it, not the reader.
                        </span>
                    </div>
                </div>
            </details>

            <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm">
                    <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send
                </button>
                <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</div>
