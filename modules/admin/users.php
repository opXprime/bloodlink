<?php
// Admin user management — list, search, filter, paginate, export, delete

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- CSV export — send file and exit before any HTML ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT u.id, u.name, u.email, u.role, c.name AS country, u.created_at
            FROM users u JOIN countries c ON u.country_id = c.id
            ORDER BY u.created_at DESC";
    $rows = $db->query($sql)->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bloodlink_users_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Name', 'Email', 'Role', 'Country', 'Joined']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['name'], $r['email'], $r['role'], $r['country'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

// ---- Delete user ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && verifyCSRF()) {
    $tid    = (int)$_POST['user_id'];
    $reason = trim($_POST['delete_reason'] ?? '');

    if ($tid === currentUserId()) {
        setFlash('error', 'Cannot delete your own account from here.');
    } else {
        $s = $db->prepare("SELECT name, email, role FROM users WHERE id = :i");
        $s->execute([':i' => $tid]);
        $tu = $s->fetch();

        if ($tu) {
            logAction('account_deleted_by_admin', $tu['email'] . '|' . $tu['role'] . '|reason:' . $reason);
            $db->prepare("DELETE FROM users WHERE id = :i")->execute([':i' => $tid]);
            setFlash('success', 'Account for ' . $tu['email'] . ' has been deleted.');
        }
    }
    redirect('/modules/admin/users.php');
}

// ---- Load users with optional role filter ----
$rf  = $_GET['role'] ?? 'all';
$sql = "SELECT u.*, c.name AS cname FROM users u JOIN countries c ON u.country_id = c.id";
$p   = [];

if ($rf !== 'all' && in_array($rf, ['donor', 'hospital', 'admin'])) {
    $sql .= " WHERE u.role = :r";
    $p[':r'] = $rf;
}

$sql .= " ORDER BY u.created_at DESC";
$s = $db->prepare($sql);
$s->execute($p);
$allUsers = $s->fetchAll();

// ---- Pagination (20 per page) ----
$perPage    = 20;
$totalUsers = count($allUsers);
$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$page       = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset     = ($page - 1) * $perPage;
$users      = array_slice($allUsers, $offset, $perPage);

require_once APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-users me-2 text-danger"></i>Manage Users</h2>
    <a href="?export=csv" class="btn btn-outline-success btn-sm">
        <i class="fas fa-download me-1"></i>Export CSV
    </a>
</div>

<!-- Client-side search -->
<div class="mb-3">
    <input type="text" id="userSearch" class="form-control"
           placeholder="Search by name, email, or ID..." onkeyup="filterUsers()">
</div>

<!-- Role filter tabs -->
<div class="mb-3">
    <a href="?role=all" class="btn btn-sm <?= $rf === 'all' ? 'btn-danger' : 'btn-outline-secondary' ?>">All (<?= $totalUsers ?>)</a>
    <a href="?role=donor" class="btn btn-sm <?= $rf === 'donor' ? 'btn-danger' : 'btn-outline-secondary' ?>">Donors</a>
    <a href="?role=hospital" class="btn btn-sm <?= $rf === 'hospital' ? 'btn-danger' : 'btn-outline-secondary' ?>">Hospitals</a>
    <a href="?role=admin" class="btn btn-sm <?= $rf === 'admin' ? 'btn-danger' : 'btn-outline-secondary' ?>">Admins</a>
</div>

<!-- User listing -->
<div class="card">
<div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="usersTable">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Country</th><th>Joined</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><small class="text-muted">#<?= e($u['id']) ?></small></td>
                    <td><strong><?= e($u['name']) ?></strong></td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <span class="badge <?= $u['role'] === 'admin' ? 'bg-dark' : ($u['role'] === 'hospital' ? 'bg-primary' : 'bg-success') ?>">
                            <?= e(ucfirst($u['role'])) ?>
                        </span>
                    </td>
                    <td><?= e($u['cname']) ?></td>
                    <td><small><?= e(date('Y-m-d', strtotime($u['created_at']))) ?></small></td>
                    <td>
                        <?php if ($u['id'] !== currentUserId()): ?>
                        <!-- Delete with reason prompt -->
                        <form method="POST" class="d-inline"
                              onsubmit="var r = prompt('Reason for deleting this account (required):');
                                        if (!r || r.trim().length < 3) { alert('Please provide a reason.'); return false; }
                                        this.querySelector('[name=delete_reason]').value = r;
                                        return confirm('Permanently delete ' + <?= htmlspecialchars(json_encode($u['name']), ENT_QUOTES) ?> + '? This cannot be undone.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="delete_reason" value="">
                            <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </form>
                        <?php else: ?>
                            <small class="text-muted">You</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php // Pagination ?>
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?role=<?= e($rf) ?>&page=<?= $page - 1 ?>">Previous</a></li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?role=<?= e($rf) ?>&page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?role=<?= e($rf) ?>&page=<?= $page + 1 ?>">Next</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

</div>
</div>

<!-- Live search — hides rows that don't match the query -->
<script>
function filterUsers() {
    var q = document.getElementById('userSearch').value.toLowerCase();
    var rows = document.querySelectorAll('#usersTable tbody tr');
    rows.forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>