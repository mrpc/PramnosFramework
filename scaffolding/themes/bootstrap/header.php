    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/css/style.css">
    <?php $this->document->renderCss(); ?>
    <?php
    $_navUser     = \Pramnos\User\User::getCurrentUser() ?: null;
    $_navFeatures = \Pramnos\Application\Application::getInstance()->applicationInfo['features'] ?? [];
    $_nav         = \Pramnos\Application\NavRegistry::getForUser($_navUser, $_navFeatures);
    $_adminAll    = $_nav[\Pramnos\Application\NavSection::Admin->value] ?? [];
    $_adminTop    = [];
    $_adminSub    = [];
    foreach ($_adminAll as $_ai) {
        if ($_ai->parent === null) { $_adminTop[] = $_ai; }
        else { $_adminSub[$_ai->parent][] = $_ai; }
    }
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo sURL; ?>">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Feature->value] ?? [] as $_item): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php
                    /*
                     * The unread badge, beside the label.
                     *
                     * `badgeCount()` is zero for every item that did not register one, and zero
                     * for a signed-out visitor, so nothing is drawn unless there is something to
                     * draw. `aria-label` carries the meaning: a number on its own is announced
                     * as a number, and «Messages 3» tells a screen-reader user nothing about
                     * what the three are.
                     */
                    ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
                    <?php $_badge = $_item->badgeCount((int) ($_navUser->userid ?? 0)); ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?><?php if ($_badge > 0): ?> <span class="badge rounded-pill text-bg-danger" aria-label="<?php echo $_badge; ?> unread"><?php echo htmlspecialchars($_item->badgeLabel((int) ($_navUser->userid ?? 0)), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_adminTop)): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Admin</a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($_adminTop as $_item):
                                $_children = $_adminSub[$_item->id] ?? [];
                                if (!empty($_children)): ?>
                            <li class="dropdown-submenu">
                                <a class="dropdown-item dropdown-toggle" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a>
                                <ul class="dropdown-menu">
                                    <?php foreach ($_children as $_child): ?>
                                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_child->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_child->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                            <?php else: ?>
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
