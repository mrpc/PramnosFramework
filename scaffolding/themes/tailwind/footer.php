    <footer class="bg-gray-800 text-gray-300 py-8 mt-auto">
        <div class="container mx-auto px-4 max-w-5xl text-center">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
            <p class="text-sm text-gray-500">Powered by <a href="https://github.com/mrpc/PramnosFramework" target="_blank" class="text-gray-400 hover:text-white">PramnosFramework</a></p>
        </div>
    </footer>
    <?php $this->document->renderJs(); ?>
