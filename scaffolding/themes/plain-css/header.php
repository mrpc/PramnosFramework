    <?php /* Inter, self-hosted — `pramnos init` vendors it into
             assets/vendor/inter/. Linked from Google it was refused outright by the
             project's own CSP (`style-src 'self'`), so the page rendered in the
             fallback stack with nothing but two console errors to say so. */ ?>
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/vendor/inter/latest/inter.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <header class="main-header">
        <div class="container">
            <a href="<?php echo sURL; ?>" class="logo">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <nav class="main-nav">
                <ul>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
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
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?><?php if ($_badge > 0): ?> <span style="display:inline-block;min-width:18px;padding:1px 6px;border-radius:9px;background:#dc2626;color:#fff;font-size:0.75em;font-weight:700;text-align:center" aria-label="<?php echo $_badge; ?> unread"><?php echo htmlspecialchars($_item->badgeLabel((int) ($_navUser->userid ?? 0)), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Feature->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_adminTop)): ?>
                    <li class="nav-admin">
                        <span>Admin &#9660;</span>
                        <ul>
                            <?php foreach ($_adminTop as $_item):
                                $_children = $_adminSub[$_item->id] ?? [];
                                if (!empty($_children)): ?>
                            <li class="has-sub">
                                <a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a>
                                <ul>
                                    <?php foreach ($_children as $_child): ?>
                                    <li><a href="<?php echo htmlspecialchars($_child->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_child->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                            <?php else: ?>
                            <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
