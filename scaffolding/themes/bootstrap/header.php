    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/css/style.css">
    <?php $this->document->renderCss(); ?>
    <?php
    $_navUser     = \Pramnos\User\User::getCurrentUser() ?: null;
    $_navFeatures = \Pramnos\Application\Application::getInstance()->applicationInfo['features'] ?? [];
    $_nav         = \Pramnos\Application\NavRegistry::getForUser($_navUser, $_navFeatures);
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
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_nav[\Pramnos\Application\NavSection::Admin->value])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Admin</a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($_nav[\Pramnos\Application\NavSection::Admin->value] as $_item): ?>
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
