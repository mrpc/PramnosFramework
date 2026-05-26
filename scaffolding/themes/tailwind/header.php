    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="<?php echo sURL; ?>assets/vendor/tailwind/tailwind.min.js"></script>
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
    <header class="bg-white shadow sticky top-0 z-50">
        <div class="container mx-auto px-4 max-w-5xl flex items-center justify-between h-16">
            <a href="<?php echo sURL; ?>" class="text-xl font-bold text-blue-600">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <nav>
                <ul class="flex gap-6 items-center">
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-700 hover:text-blue-600 font-medium transition-colors"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Feature->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-700 hover:text-blue-600 font-medium transition-colors"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 font-semibold hover:text-blue-800 transition-colors"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_adminTop)): ?>
                    <li class="relative group">
                        <span class="text-gray-700 hover:text-blue-600 font-medium transition-colors cursor-pointer">Admin &#9660;</span>
                        <ul class="absolute right-0 mt-2 bg-white border border-gray-200 rounded shadow-lg hidden group-hover:block z-50 py-1 min-w-[180px]">
                            <?php foreach ($_adminTop as $_item):
                                $_children = $_adminSub[$_item->id] ?? [];
                                if (!empty($_children)): ?>
                            <li class="relative group/sub">
                                <a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="flex justify-between items-center px-4 py-2 text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                                    <?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?> <span class="text-xs opacity-50">&#9658;</span>
                                </a>
                                <ul class="absolute left-full top-0 bg-white border border-gray-200 rounded shadow-lg hidden group-hover/sub:block z-50 py-1 min-w-[160px]">
                                    <?php foreach ($_children as $_child): ?>
                                    <li><a href="<?php echo htmlspecialchars($_child->url, ENT_QUOTES, 'UTF-8'); ?>" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 whitespace-nowrap"><?php echo htmlspecialchars($_child->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                            <?php else: ?>
                            <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 whitespace-nowrap"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
