<?php
// Admin contact messages — read, reply, delete

$pageTitle = 'Contact Messages';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- Mark message as read ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read']) && verifyCSRF()) {
    $mid = (int)$_POST['msg_id'];
    $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = :i")
       ->execute([':i' => $mid]);
    setFlash('success', 'Marked read.');
    redirect('/modules/admin/messages.php');
}

// ---- Reply to message ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply']) && verifyCSRF()) {
    $mid   = (int)$_POST['msg_id'];
    $reply = trim($_POST['reply_text'] ?? '');

    if (strlen($reply) >= 2) {
        $db->prepare(
            "UPDATE contact_messages SET admin_reply = :r, replied_at = NOW(), is_read = 1 WHERE id = :i"
        )->execute([':r' => $reply, ':i' => $mid]);

        // If sender is a registered user, send them an in-app notification
        $s = $db->prepare("SELECT user_id, name FROM contact_messages WHERE id = :i");
        $s->execute([':i' => $mid]);
        $cm = $s->fetch();

        if ($cm && $cm['user_id']) {
            createNotification(
                (int)$cm['user_id'],
                'Reply to Your Message',
                'Admin replied to your contact message. Check the Contact page to see the response.',
                'info',
                '/contact.php'
            );
        }

        logAction('message_replied', "#$mid");
        setFlash('success', 'Reply sent.');
    } else {
        setFlash('error', 'Reply cannot be empty.');
    }

    redirect('/modules/admin/messages.php');
}

// ---- Delete message ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_msg']) && verifyCSRF()) {
    $mid = (int)$_POST['msg_id'];
    $db->prepare("DELETE FROM contact_messages WHERE id = :i")
       ->execute([':i' => $mid]);
    setFlash('success', 'Deleted.');
    redirect('/modules/admin/messages.php');
}

// ---- Load all messages (unread first, then newest) ----
$msgs   = $db->query("SELECT * FROM contact_messages ORDER BY is_read ASC, created_at DESC")->fetchAll();
$unread = count(array_filter($msgs, fn($m) => !$m['is_read']));

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4">
    <i class="fas fa-envelope me-2 text-danger"></i>Contact Messages
    <?php if ($unread): ?>
        <span class="badge bg-warning text-dark"><?= $unread ?> unread</span>
    <?php endif; ?>
</h2>

<div class="card">
<div class="card-body">
    <?php if (empty($msgs)): ?>
        <p class="text-muted">No messages.</p>
    <?php else: ?>
        <?php foreach ($msgs as $m): ?>
        <div class="border rounded p-3 mb-3 <?= $m['is_read'] ? '' : 'bg-light border-warning' ?>">

            <!-- Sender info + action buttons -->
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong><?= e($m['name']) ?></strong>
                    <small class="text-muted">&lt;<?= e($m['email']) ?>&gt;</small>
                    <?php if ($m['user_id']): ?>
                        <span class="badge bg-info ms-1">Registered</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1">Guest</span>
                    <?php endif; ?>
                    <br><small class="text-muted"><?= e($m['created_at']) ?></small>
                </div>
                <div class="d-flex gap-1">
                    <?php // Mark as read button (unread only) ?>
                    <?php if (!$m['is_read']): ?>
                    <form method="POST" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                        <button type="submit" name="mark_read" class="btn btn-sm btn-outline-success" title="Mark read">
                            <i class="fas fa-check"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php // Delete button ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this message?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                        <button type="submit" name="delete_msg" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Message body -->
            <p class="mt-2 mb-2"><?= nl2br(e($m['message'])) ?></p>

            <?php if ($m['admin_reply']): ?>
                <!-- Admin's reply (already sent) -->
                <div class="alert alert-success py-2 mb-0">
                    <strong><i class="fas fa-reply me-1"></i>Your reply:</strong> <?= e($m['admin_reply']) ?>
                    <br><small class="text-muted"><?= e($m['replied_at']) ?></small>
                </div>
            <?php else: ?>
                <!-- Reply form -->
                <form method="POST" class="mt-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm" name="reply_text"
                               placeholder="Type your reply..." required>
                        <button type="submit" name="send_reply" class="btn btn-sm btn-success">
                            <i class="fas fa-reply me-1"></i>Reply
                        </button>
                    </div>
                    <?php if (!$m['user_id']): ?>
                        <small class="text-muted mt-1 d-block">
                            Guest user — reply will not be visible to them (email them separately).
                        </small>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>