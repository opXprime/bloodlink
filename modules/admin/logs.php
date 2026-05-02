<?php
// System audit log viewer — paginated list of all logged actions

$pageTitle = 'System Logs';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- Pagination setup ----
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 50;
$off   = ($page - 1) * $per;
$total = $db->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$pages = max(1, ceil($total / $per));

// ---- Fetch logs for current page ----
$s = $db->prepare(
    "SELECT sl.action, sl.details, sl.created_at, u.name AS uname, u.email
     FROM system_logs sl
     LEFT JOIN users u ON sl.user_id = u.id
     ORDER BY sl.created_at DESC
     LIMIT :l OFFSET :o"
);
$s->bindValue(':l', $per, PDO::PARAM_INT);
$s->bindValue(':o', $off, PDO::PARAM_INT);
$s->execute();
$logs = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-file-alt me-2 text-danger"></i>System Logs</h2>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between">
        <h5 class="mb-0">Logs (<?= $total ?>)</h5>
        <small class="text-muted">Page <?= $page ?>/<?= $pages ?></small>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><small><?= e($l['created_at']) ?></small></td>
                        <td>
                            <?php if ($l['uname']): ?>
                                <?= e($l['uname']) ?><br>
                                <small class="text-muted"><?= e($l['email']) ?></small>
                            <?php else: ?>
                                <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($l['action']) ?></code></td>
                        <td><small><?= e($l['details'] ?? '-') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php // Pagination links ?>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
        <nav>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>