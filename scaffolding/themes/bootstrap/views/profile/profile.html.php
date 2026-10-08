<?php
/**
 * User profile page (Bootstrap theme) — editable.
 *
 * Matches the fields Account::profile() saves (firstname / lastname / email /
 * phone). Username and account dates are read-only. Uses the shared account
 * sidebar for consistent navigation.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::profile):
 *   $this->routeBase — Account controller route base
 *   $this->user      — User object; `avatarurl` and `photo` drive the picture card,
 *                      whose form posts to Account::profilephoto()
 */
$routeBase = $this->routeBase ?? 'Account';
$u         = $this->user;
$this->activeNav = 'profile';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4">My Profile</h2>

    <?php if ($this->hasMessages()): ?>
        <div role="status" class="alert alert-success"><?php echo $this->_printMessages(); ?></div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card mb-4">
                <div class="card-header fw-semibold">Profile Picture</div>
                <div class="card-body d-flex align-items-center gap-4 flex-wrap">
                    <?php if (!empty($u->avatarurl)): ?>
                        <img src="<?php echo htmlspecialchars((string) $u->avatarurl); ?>" alt="Your profile picture" width="96" height="96" class="rounded-circle">
                    <?php endif; ?>
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/profilephoto" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-center">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <label for="photo" class="visually-hidden">Choose a picture</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif" class="form-control">
                        <button type="submit" class="btn btn-primary">Upload</button>
                        <?php if ((int) ($u->photo ?? 0) > 0): ?>
                            <button type="submit" name="remove" value="1" class="btn btn-outline-danger">Remove</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold">Personal Information</div>
                <div class="card-body">
                    <form method="post" action="<?php echo sURL . $routeBase; ?>/profile">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>

                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars((string) ($u->username ?? '')); ?>" disabled>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="firstname" class="form-label">First Name</label>
                                <input type="text" id="firstname" name="firstname" class="form-control" value="<?php echo htmlspecialchars((string) ($u->firstname ?? '')); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="lastname" class="form-label">Last Name</label>
                                <input type="text" id="lastname" name="lastname" class="form-control" value="<?php echo htmlspecialchars((string) ($u->lastname ?? '')); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars((string) ($u->email ?? '')); ?>" required>
                        </div>

                        <div class="mb-4">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars((string) ($u->phone ?? '')); ?>">
                        </div>

                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($u->regdate) || !empty($u->lastlogin)): ?>
            <div class="card mt-3">
                <div class="card-header fw-semibold">Account</div>
                <ul class="list-group list-group-flush">
                    <?php if (!empty($u->regdate)): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Member Since</span>
                        <span><?php echo htmlspecialchars(localDate( is_numeric($u->regdate) ? (int) $u->regdate : (int) strtotime((string) $u->regdate))); ?></span>
                    </li>
                    <?php endif; ?>
                    <?php if (!empty($u->lastlogin)): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Last Login</span>
                        <span><?php echo htmlspecialchars(localDateTime( is_numeric($u->lastlogin) ? (int) $u->lastlogin : (int) strtotime((string) $u->lastlogin))); ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
