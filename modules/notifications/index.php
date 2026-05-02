<?php
// Notification centre — view all notifications, mark as read

$pageTitle = 'Notifications';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireLogin();

$db  = Database::getInstance();
$uid = currentUserId();

// ---- Mark single notification as read and redirect to its link ----
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :n AND user_id = :u")
       ->execute([':n' => $nid, ':u' => $uid]);

    $s = $db->prepare("SELECT link FROM notifications WHERE id = :n AND user_id = :u");
    $s->execute([':n' => $nid, ':u' => $uid]);
    $n = $s->fetch();

    if ($n && $n['link']) redirect($n['link']);
    redirect('/modules/notifications/index.php');
}

// ---- Mark all notifications as read ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read']) && verifyCSRF()) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :u")
       ->execute([':u' => $uid]);
    setFlash('success', 'All read.');
    redirect('/modules/notifications/index.php');
}

// ---- Load notifications (most recent first, max 50) ----
$s = $db->prepare("SELECT * FROM notifications WHERE user_id = :u ORDER BY created_at DESC LIMIT 50");
$s->execute([':u' => $uid]);
$notifs = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<!-- Header with "Mark All Read" button -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-bell me-2 text-danger"></i>Notifications</h2>
    <form method="POST">
        <?= csrfField() ?>
        <button type="submit" name="mark_all_read" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-check-double me-1"></i>Mark All Read
        </button>
    </form>
</div>

<?php if (empty($notifs)): ?>
    <div class="card">
        <div class="card-body text-muted">No notifications.</div>
    </div>
<?php else: ?>
    <?php foreach ($notifs as $n): ?>
    <a href="<?= APP_URL ?>/modules/notifications/index.php?read=<?= $n['id'] ?>" class="text-decoration-none d-block">
        <div class="notification-item <?= $n['is_read'] ? '' : 'unread' ?>">
            <div class="d-flex justify-content-between">
                <strong class="text-dark"><?= e($n['title']) ?></strong>
                <small class="text-muted"><?= e($n['created_at']) ?></small>
            </div>
            <p class="mb-0 text-muted"><?= e($n['message']) ?></p>
        </div>
    </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>