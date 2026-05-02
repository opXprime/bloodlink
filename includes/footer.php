<?php
// shared page footer — copyright, links, Bootstrap JS
if (!defined('APP_ROOT')) die('Direct access not permitted');
?>
</main>
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container text-center">
        <p class="mb-1">
            <i class="fas fa-heartbeat text-danger me-2"></i><strong>BloodLink</strong>
        </p>
        <p class="mb-1">
            <a href="<?= APP_URL ?>/contact.php" class="text-light text-decoration-none me-3"><i class="fas fa-envelope me-1"></i>Contact</a>
            <a href="<?= APP_URL ?>/privacy.php" class="text-light text-decoration-none me-3"><i class="fas fa-shield-alt me-1"></i>Privacy</a>
            <a href="<?= APP_URL ?>/terms.php" class="text-light text-decoration-none"><i class="fas fa-file-contract me-1"></i>Terms</a>
        </p>
        <p class="text-muted small mb-0">&copy; <?= date('Y') ?> BloodLink — Blood Donation Coordination System</p>
    </div>
</footer>
<script src="<?= APP_URL ?>/public/js/app.js"></script>
</body>
</html>
