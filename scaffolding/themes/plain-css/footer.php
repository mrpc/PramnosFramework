    <footer class="main-footer">
        <div class="container">
            <div class="footer-content">
                <p>&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
                <p class="powered">Powered by <a href="https://github.com/mrpc/PramnosFramework" target="_blank">PramnosFramework</a></p>
            </div>
        </div>
    </footer>
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
