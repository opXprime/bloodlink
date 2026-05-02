<?php
// Logout — only accepts POST to prevent CSRF-based forced logout

require_once __DIR__ . '/../../includes/bootstrap.php';

// If someone hits this via GET, show a confirm button instead of logging out
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once APP_ROOT . '/includes/header.php';
    echo '<div class="auth-container">';
    echo '<div class="card auth-card shadow">';
    echo '<div class="card-body p-4 text-center">';
    echo '<h5>Are you sure you want to log out?</h5>';
    echo '<form method="POST">' . csrfField() . '<button type="submit" class="btn btn-blood mt-3"><i class="fas fa-sign-out-alt me-2"></i>Log Out</button></form>';
    echo '</div></div></div>';
    require_once APP_ROOT . '/includes/footer.php';
    exit;
}

// Verify CSRF token
if (!verifyCSRF()) {
    redirect('/index.php');
}

// Log the action before destroying the session
if (isLoggedIn()) logAction('user_logout', '');

// Destroy session and redirect to login
session_unset();
session_destroy();

// Start a fresh session just for the flash message
session_start();
$_SESSION['flash_success'] = 'Logged out.';

header('Location: ' . APP_URL . '/modules/auth/login.php');
exit;