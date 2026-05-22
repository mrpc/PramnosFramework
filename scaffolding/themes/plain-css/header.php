    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/css/style.css">
    <?php $this->document->renderCss(); ?>
    <header class="main-header">
        <div class="container">
            <a href="<?php echo sURL; ?>" class="logo">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <nav class="main-nav">
                <ul>
                    <li><a href="<?php echo sURL; ?>">Home</a></li>
                    <?php if (\Pramnos\Http\Session::staticIsLogged()): ?>
                    <li><a href="<?php echo sURL; ?>account">My Account</a></li>
                    <li><a href="<?php echo sURL; ?>login/logout">Logout (<?php echo htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</a></li>
                    <?php else: ?>
                    <li><a href="<?php echo sURL; ?>login">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
