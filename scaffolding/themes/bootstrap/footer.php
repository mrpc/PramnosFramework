    <footer class="bg-dark text-light py-4 mt-auto">
        <div class="container text-center">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
            <p class="mb-0 text-muted small">Powered by <a href="https://github.com/mrpc/PramnosFramework" target="_blank" class="text-secondary">PramnosFramework</a></p>
        </div>
    </footer>
    <script src="<?php echo sURL; ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <?php $this->document->renderJs(); ?>
