    <footer class="bg-dark text-light py-4 mt-auto">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
        </div>
    </footer>
    <script src="<?php echo sURL; ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
