<?php
// Admin reports — review user reports, send feedback messages to reporter

$pageTitle = 'Reports';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- Handle report actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF()) {
    $rid    = (int)($_POST['report_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes  = trim($_POST['admin_notes'] ?? '');

    // Review or dismiss a report
    if ($action === 'reviewed' || $action === 'dismissed') {
        $db->prepare("UPDATE reports SET status = :s, admin_notes = :n WHERE id = :id")
           ->execute([':s' => $action, ':n' => $notes, ':id' => $rid]);
        logAction('report_' . $action, "report #$rid");
        setFlash('success', 'Report marked as ' . $action . '.');
    }

    // Send a message to the reporter via contact_messages
    // Shows up in their Contact Us page as an admin reply
    if ($action === 'send_message') {
        $msgText      = trim($_POST['report_message'] ?? '');
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (strlen($msgText) >= 3 && $targetUserId > 0) {
            $u = $db->prepare("SELECT name, email FROM users WHERE id = :u");
            $u->execute([':u' => $targetUserId]);
            $usr = $u->fetch();

            if ($usr) {
                // Insert as a message FROM the user with admin_reply pre-filled
                // This way it appears in their Contact Us "My Messages" section
                $db->prepare(
                    "INSERT INTO contact_messages (user_id, name, email, message, admin_reply, replied_at, is_read)
                     VALUES (:u, :n, :e, :m, :r, NOW(), 0)"
                )->execute([
                    ':u' => $targetUserId,
                    ':n' => $usr['name'],
                    ':e' => $usr['email'],
                    ':m' => '[Report #' . $rid . '] Admin reviewed your report.',
                    ':r' => $msgText
                ]);
                logAction('report_message_sent', "to user #$targetUserId re: report #$rid");
                setFlash('success', 'Message sent to ' . $usr['email'] . '.');
            }
        } else {
            setFlash('error', 'Message must be at least 3 characters.');
        }
    }

    redirect('/modules/admin/reports.php?status=' . ($_GET['status'] ?? 'pending'));
}

// ---- Fetch reports with user info ----
$filter = $_GET['status'] ?? 'pending';

$sql = "SELECT r.*,
            reporter.name AS reporter_name, reporter.email AS reporter_email, reporter.role AS reporter_role,
            reported.name AS reported_name, reported.email AS reported_email, reported.role AS reported_role
        FROM reports r
        JOIN users reporter ON r.reporter_id = reporter.id
        JOIN users reported ON r.reported_id = reported.id";
$params = [];

if ($filter !== 'all' && in_array($filter, ['pending', 'reviewed', 'dismissed'])) {
    $sql .= " WHERE r.status = :s";
    $params[':s'] = $filter;
}

$sql .= " ORDER BY r.created_at DESC";
$s = $db->prepare($sql);
$s->execute($params);
$reports = $s->fetchAll();

// Pending count for badge
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-flag me-2 text-danger"></i>User Reports</h2>

<!-- Status filter tabs -->
<div class="mb-3">
    <a href="?status=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-warning' : 'btn-outline-secondary' ?>">
        Pending <?= $pendingCount ? '(' . $pendingCount . ')' : '' ?>
    </a>
    <a href="?status=reviewed" class="btn btn-sm <?= $filter === 'reviewed' ? 'btn-success' : 'btn-outline-secondary' ?>">Reviewed</a>
    <a href="?status=dismissed" class="btn btn-sm <?= $filter === 'dismissed' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Dismissed</a>
    <a href="?status=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-danger' : 'btn-outline-secondary' ?>">All</a>
</div>

<?php if (empty($reports)): ?>
    <div class="alert alert-info">No reports found.</div>
<?php else: ?>
    <?php foreach ($reports as $r): ?>
    <div class="card mb-3 <?= $r['status'] === 'pending' ? 'border-warning' : '' ?>">
        <div class="card-body">
            <div class="row">

                <!-- Report details -->
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge <?= $r['status'] === 'pending' ? 'bg-warning text-dark' : ($r['status'] === 'reviewed' ? 'bg-success' : 'bg-secondary') ?> me-2">
                            <?= e(ucfirst($r['status'])) ?>
                        </span>
                        <span class="badge bg-danger me-2"><?= e(str_replace('_', ' ', ucfirst($r['reason']))) ?></span>
                        <small class="text-muted"><?= e($r['created_at']) ?></small>
                    </div>
                    <p class="mb-1"><strong>Reported by:</strong> <?= e($r['reporter_name']) ?>
                        <span class="text-muted">(<?= e($r['reporter_email']) ?>, <?= e($r['reporter_role']) ?>)</span>
                    </p>
                    <p class="mb-1"><strong>Reported user:</strong> <?= e($r['reported_name']) ?>
                        <span class="text-muted">(<?= e($r['reported_email']) ?>, <?= e($r['reported_role']) ?>)</span>
                    </p>
                    <?php if ($r['reason']): ?>
                        <p class="mb-1"><strong>Reason:</strong> <?= e($r['reason']) ?></p>
                    <?php endif; ?>
                    <?php if ($r['admin_notes']): ?>
                        <p class="mb-0 text-info"><strong>Admin notes:</strong> <?= e($r['admin_notes']) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="col-md-4">
                    <?php if ($r['status'] === 'pending'): ?>
                    <!-- Review / dismiss form -->
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <div class="mb-2">
                            <textarea class="form-control form-control-sm" name="admin_notes" rows="2" placeholder="Admin notes (optional)"></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button name="action" value="reviewed" class="btn btn-success btn-sm flex-fill">
                                <i class="fas fa-check me-1"></i>Reviewed
                            </button>
                            <button name="action" value="dismissed" class="btn btn-outline-secondary btn-sm flex-fill">
                                <i class="fas fa-times me-1"></i>Dismiss
                            </button>
                        </div>
                    </form>
                    <hr class="my-2">
                    <a href="<?= APP_URL ?>/modules/admin/users.php" class="btn btn-outline-danger btn-sm w-100">
                        <i class="fas fa-users me-1"></i>Go to Users (to ban)
                    </a>
                    <?php endif; ?>

                    <!-- Send message to reporter -->
                    <div class="mt-2">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="target_user_id" value="<?= $r['reporter_id'] ?>">
                            <input type="hidden" name="action" value="send_message">
                            <div class="mb-1"><small class="text-muted fw-bold">Send message to reporter:</small></div>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" name="report_message"
                                       placeholder="e.g. We've taken action on this" required minlength="3">
                                <button type="submit" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-paper-plane me-1"></i>Send
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>