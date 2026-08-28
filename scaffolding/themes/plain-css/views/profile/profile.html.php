<?php
/**
 * User profile page (plain-CSS theme) — editable.
 *
 * Matches the fields the Account::profile() POST handler saves
 * (firstname / lastname / email / phone). Username and account dates are shown
 * read-only. Uses the shared account sidebar for consistent navigation.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::profile):
 *   $this->routeBase — Account controller route base
 *   $this->user      — User object
 */
$routeBase = $this->routeBase ?? 'Account';
$u         = $this->user;
$this->activeNav = 'profile';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 style="margin-bottom:16px">My Profile</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card">
                <div class="card-header"><strong>Personal Information</strong></div>
                <div class="card-body" style="padding:20px">
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/profile">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>

                        <div style="margin-bottom:16px">
                            <label style="display:block;margin-bottom:4px;font-weight:500">Username</label>
                            <input type="text" value="<?php echo htmlspecialchars((string) ($u->username ?? '')); ?>"
                                   style="width:100%;padding:8px 12px;border:1px solid #eee;border-radius:4px;box-sizing:border-box;background:#f8f8f8;color:#888" disabled>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                            <div>
                                <label for="firstname" style="display:block;margin-bottom:4px;font-weight:500">First Name</label>
                                <input type="text" id="firstname" name="firstname" value="<?php echo htmlspecialchars((string) ($u->firstname ?? '')); ?>"
                                       style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                            </div>
                            <div>
                                <label for="lastname" style="display:block;margin-bottom:4px;font-weight:500">Last Name</label>
                                <input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars((string) ($u->lastname ?? '')); ?>"
                                       style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                            </div>
                        </div>

                        <div style="margin-bottom:16px">
                            <label for="email" style="display:block;margin-bottom:4px;font-weight:500">Email</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars((string) ($u->email ?? '')); ?>" required
                                   style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                        </div>

                        <div style="margin-bottom:20px">
                            <label for="phone" style="display:block;margin-bottom:4px;font-weight:500">Phone</label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars((string) ($u->phone ?? '')); ?>"
                                   style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                        </div>

                        <button type="submit" class="btn">Save Changes</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($u->regdate) || !empty($u->lastlogin)): ?>
            <div class="card" style="margin-top:16px">
                <div class="card-header"><strong>Account</strong></div>
                <div class="card-body" style="padding:0">
                    <table style="width:100%;border-collapse:collapse;font-size:.9em">
                        <?php if (!empty($u->regdate)): ?>
                        <tr style="border-bottom:1px solid #f0f0f0">
                            <th style="text-align:left;padding:10px 16px;width:160px;color:#444">Member Since</th>
                            <td style="padding:10px 16px;color:#666"><?php echo htmlspecialchars(localDate( is_numeric($u->regdate) ? (int) $u->regdate : (int) strtotime((string) $u->regdate))); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($u->lastlogin)): ?>
                        <tr>
                            <th style="text-align:left;padding:10px 16px;color:#444">Last Login</th>
                            <td style="padding:10px 16px;color:#666"><?php echo htmlspecialchars(localDateTime( is_numeric($u->lastlogin) ? (int) $u->lastlogin : (int) strtotime((string) $u->lastlogin))); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
