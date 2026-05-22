    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="<?php echo sURL; ?>assets/vendor/tailwind/tailwind.min.js"></script>
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/css/style.css">
    <?php $this->document->renderCss(); ?>
    <header class="bg-white shadow sticky top-0 z-50">
        <div class="container mx-auto px-4 max-w-5xl flex items-center justify-between h-16">
            <a href="<?php echo sURL; ?>" class="text-xl font-bold text-blue-600">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <nav>
                <ul class="flex gap-6 items-center">
                    <li><a href="<?php echo sURL; ?>" class="text-gray-700 hover:text-blue-600 font-medium transition-colors">Home</a></li>
                    <?php if (\Pramnos\Http\Session::staticIsLogged()): ?>
                    <li><a href="<?php echo sURL; ?>account" class="text-gray-700 hover:text-blue-600 font-medium transition-colors">My Account</a></li>
                    <li><a href="<?php echo sURL; ?>login/logout" class="text-gray-700 hover:text-blue-600 font-medium transition-colors">Logout (<?php echo htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</a></li>
                    <?php else: ?>
                    <li><a href="<?php echo sURL; ?>login" class="text-blue-600 font-semibold hover:text-blue-800 transition-colors">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
