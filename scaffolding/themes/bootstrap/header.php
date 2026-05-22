    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/css/style.css">
    <?php $this->document->renderCss(); ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo sURL; ?>">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo sURL; ?>">Home</a>
                    </li>
                    <?php if (\Pramnos\Http\Session::staticIsLogged()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo sURL; ?>account">My Account</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo sURL; ?>login/logout">Logout (<?php echo htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</a>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo sURL; ?>login">Login</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
