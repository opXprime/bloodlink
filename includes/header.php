<?php
// Shared page header — navbar with role-based navigation, notification bell, flash messages

if (!defined('APP_ROOT')) die('Direct access not permitted');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'BloodLink') ?> - BloodLink</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css">

    <meta name="app-url" content="<?= APP_URL ?>">
</head>
<body>

<!-- ========== NAVBAR ========== -->
<nav class="navbar navbar-expand-lg navbar-dark bg-danger sticky-top shadow-sm">
    <div class="container">

        <?php
        // Logged-in users go to their dashboard, guests go to homepage
        $brandLink = APP_URL . '/index.php';
        if (isLoggedIn()) {
            $r = currentUserRole();
            if ($r === 'donor')        $brandLink = APP_URL . '/modules/donor/dashboard.php';
            elseif ($r === 'hospital') $brandLink = APP_URL . '/modules/hospital/dashboard.php';
            elseif ($r === 'admin')    $brandLink = APP_URL . '/modules/admin/dashboard.php';
        }
        ?>

        <a class="navbar-brand fw-bold" href="<?= $brandLink ?>">
            <i class="fas fa-heartbeat me-2"></i>BloodLink
        </a>

        <button class="navbar-toggler" type="button" onclick="document.getElementById('navbarContent').classList.toggle('show')">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">

            <!-- Left-side nav links (role-based) -->
            <ul class="navbar-nav me-auto">
                <?php if (!isLoggedIn()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/index.php"><i class="fas fa-home me-1"></i>Home</a></li>

                <?php elseif (currentUserRole() === 'donor'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/donor/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/donor/profile.php">My Profile</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/matching/requests.php">Find Requests</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/booking/my_bookings.php">My Bookings</a></li>

                <?php elseif (currentUserRole() === 'hospital'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/hospital/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/hospital/requests.php">Blood Requests</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/hospital/campaigns.php">Campaigns</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/booking/hospital_bookings.php">Bookings</a></li>

                <?php elseif (currentUserRole() === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/admin/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/admin/hospitals.php">Hospitals</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/admin/locations.php">Locations</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/admin/users.php">Users</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/admin/messages.php">Messages</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/admin/logs.php">Logs</a></li>
                <?php endif; ?>
            </ul>

            <!-- Right-side nav (notifications, user menu, login/register) -->
            <ul class="navbar-nav">
                <?php if (isLoggedIn()): ?>

                    <?php // Contact reply badge (non-admin only)
                    if (currentUserRole() !== 'admin'):
                        $contactBadge = getUnreadContactReplyCount();
                    ?>
                        <?php if ($contactBadge > 0): ?>
                        <li class="nav-item">
                            <a class="nav-link position-relative" href="<?= APP_URL ?>/contact.php">
                                <i class="fas fa-envelope"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:.6em"><?= $contactBadge ?></span>
                            </a>
                        </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php // Notification bell with unread count
                    $nc = getUnreadNotificationCount(); ?>
                    <li class="nav-item">
                        <a class="nav-link position-relative" href="<?= APP_URL ?>/modules/notifications/index.php">
                            <i class="fas fa-bell"></i>
                            <?php if ($nc > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:.6em"><?= $nc ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="fas fa-user-circle me-1"></i><?= e(currentUser()['name'] ?? '') ?>
                            <small class="badge bg-light text-dark ms-1"><?= e(ucfirst(currentUserRole())) ?></small>
                        </span>
                    </li>

                    <li class="nav-item">
                        <form method="POST" action="<?= APP_URL ?>/modules/auth/logout.php" class="d-inline" onsubmit="return confirm('Are you sure you want to log out?');">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-outline-light btn-sm ms-2">
                                <i class="fas fa-sign-out-alt me-1"></i>Logout
                            </button>
                        </form>
                    </li>

                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/auth/login.php"><i class="fas fa-sign-in-alt me-1"></i>Login</a></li>
                    <li class="nav-item"><a class="nav-link btn btn-outline-light btn-sm ms-2 px-3" href="<?= APP_URL ?>/modules/auth/register.php">Register</a></li>
                <?php endif; ?>
            </ul>

        </div>
    </div>
</nav>

<!-- ========== MAIN CONTENT ========== -->
<main class="container py-4">
    <?= renderFlash() ?>

<?php
// ========== NOTIFICATION TOAST ==========
// Pops up once per notification per session
// Does NOT mark as read — bell badge stays until user clicks "Mark All Read"
if (isLoggedIn()) {
    $db2 = Database::getInstance();
    $latestNote = $db2->prepare(
        "SELECT * FROM notifications WHERE user_id = :u AND is_read = 0 ORDER BY created_at DESC LIMIT 1"
    );
    $latestNote->execute([':u' => currentUserId()]);
    $popNote = $latestNote->fetch();

    // Track which notifications have been shown this session
    if (!isset($_SESSION['shown_notif_ids'])) $_SESSION['shown_notif_ids'] = [];

    if ($popNote && !in_array((int)$popNote['id'], $_SESSION['shown_notif_ids'])):
        $_SESSION['shown_notif_ids'][] = (int)$popNote['id'];
?>

<!-- Toast notification slide-in -->
<div id="notifToast" style="position:fixed;top:80px;right:20px;z-index:9999;max-width:380px;animation:slideIn .4s ease">
    <div class="card border-<?= e($popNote['type']) ?> shadow-lg" style="border-left:5px solid">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong class="text-<?= e($popNote['type']) ?>">
                        <i class="fas fa-<?= $popNote['type'] === 'danger' || $popNote['type'] === 'warning' ? 'exclamation-circle' : ($popNote['type'] === 'success' ? 'check-circle' : 'bell') ?> me-1"></i>
                        <?= e($popNote['title']) ?>
                    </strong>
                    <p class="mb-0 mt-1 small"><?= e(substr($popNote['message'], 0, 150)) ?></p>
                </div>
                <button class="btn-close btn-sm" onclick="document.getElementById('notifToast').remove()"></button>
            </div>
            <?php if ($popNote['link']): ?>
            <a href="<?= APP_URL ?><?= e($popNote['link']) ?>" class="btn btn-sm btn-outline-<?= e($popNote['type']) ?> mt-2">View</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Toast animation and auto-dismiss -->
<style>@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}</style>
<script>
setTimeout(function(){
    var t = document.getElementById('notifToast');
    if (t) {
        t.style.transition = 'opacity .5s';
        t.style.opacity = '0';
        setTimeout(function(){ if(t) t.remove(); }, 500);
    }
}, 6000);
</script>

<?php endif; } ?>