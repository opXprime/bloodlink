<?php
// Hospital verification — approve or reject hospital registrations

$pageTitle = 'Manage Hospitals';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- Handle verification actions (approve/reject) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF()) {
    $hpId   = (int)($_POST['hospital_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        // Approve hospital
        $db->prepare("UPDATE hospital_profiles SET verification_status = 'verified', verified_at = NOW() WHERE id = :i")
           ->execute([':i' => $hpId]);

        $s = $db->prepare("SELECT user_id, hospital_name FROM hospital_profiles WHERE id = :i");
        $s->execute([':i' => $hpId]);
        $hp = $s->fetch();

        if ($hp) {
            createNotification(
                $hp['user_id'],
                'Hospital Verified!',
                '"' . $hp['hospital_name'] . '" is now verified.',
                'success',
                '/modules/notifications/index.php'
            );
        }
        logAction('hospital_verified', "#$hpId");

    } elseif ($action === 'reject') {
        // Reject hospital with optional reason
        $reason = trim($_POST['verification_notes'] ?? '');
        $db->prepare("UPDATE hospital_profiles SET verification_status = 'rejected', verification_notes = :r WHERE id = :i")
           ->execute([':i' => $hpId, ':r' => $reason]);

        $s = $db->prepare("SELECT user_id FROM hospital_profiles WHERE id = :i");
        $s->execute([':i' => $hpId]);
        $hp = $s->fetch();

        if ($hp) {
            createNotification(
                $hp['user_id'],
                'Verification Rejected',
                'Rejected' . ($reason ? ": $reason" : ''),
                'danger',
                '/modules/notifications/index.php'
            );
        }
        logAction('hospital_rejected', "#$hpId: $reason");
    }

    setFlash('success', 'Updated.');
    redirect('/modules/admin/hospitals.php');
}

// ---- Load hospitals with optional status filter ----
$filter = $_GET['status'] ?? 'all';

$sql = "SELECT hp.*, u.name AS user_name, u.email, c.name AS country_name, a.name AS area_name
        FROM hospital_profiles hp
        JOIN users u ON hp.user_id = u.id
        JOIN countries c ON u.country_id = c.id
        LEFT JOIN areas a ON hp.area_id = a.id";
$params = [];

if ($filter !== 'all' && in_array($filter, ['pending', 'verified', 'rejected'])) {
    $sql .= " WHERE hp.verification_status = :s";
    $params[':s'] = $filter;
}

$sql .= " ORDER BY FIELD(hp.verification_status, 'pending', 'verified', 'rejected'), hp.created_at DESC";
$s = $db->prepare($sql);
$s->execute($params);
$hospitals = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-hospital me-2 text-danger"></i>Manage Hospitals</h2>

<!-- Verification guidance -->
<div class="alert alert-info py-2 mb-3">
    <i class="fas fa-clipboard-check me-2"></i>
    <strong>Before verifying:</strong> Check the hospital's licence number, registered address, and uploaded legal document (PDF) to confirm legitimacy.
</div>

<!-- Status filter tabs -->
<div class="mb-3">
    <a href="?status=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-danger' : 'btn-outline-secondary' ?>">All</a>
    <a href="?status=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-warning' : 'btn-outline-secondary' ?>">Pending</a>
    <a href="?status=verified" class="btn btn-sm <?= $filter === 'verified' ? 'btn-success' : 'btn-outline-secondary' ?>">Verified</a>
    <a href="?status=rejected" class="btn btn-sm <?= $filter === 'rejected' ? 'btn-danger text-white' : 'btn-outline-secondary' ?>">Rejected</a>
</div>

<!-- Hospital listing -->
<div class="card">
<div class="card-body">
    <?php if (empty($hospitals)): ?>
        <p class="text-muted">No hospitals.</p>
    <?php else: ?>
        <?php foreach ($hospitals as $h): ?>
        <div class="border rounded p-3 mb-3">
            <div class="row align-items-start">

                <!-- Hospital details -->
                <div class="col-md-6">
                    <h5 class="mb-1"><?= e($h['hospital_name']) ?></h5>
                    <p class="mb-1 text-muted"><i class="fas fa-envelope me-1"></i><?= e($h['email'] ?? '-') ?></p>
                    <p class="mb-1 text-muted"><i class="fas fa-id-card me-1"></i>License: <strong><?= e($h['license_number'] ?? '-') ?></strong></p>
                    <p class="mb-1 text-muted"><i class="fas fa-phone me-1"></i><?= e($h['phone'] ?? '-') ?></p>
                    <p class="mb-1 text-muted"><i class="fas fa-globe me-1"></i><?= e($h['country_name']) ?> &middot; <?= e($h['area_name'] ?? '-') ?></p>
                    <?php if ($h['address']): ?>
                        <p class="mb-1 text-muted"><i class="fas fa-map me-1"></i><?= e($h['address']) ?></p>
                    <?php endif; ?>
                    <?php if ($h['verification_doc']): ?>
                        <p class="mb-0">
                            <a href="<?= APP_URL ?>/public/uploads/<?= e($h['verification_doc']) ?>" target="_blank">
                                <i class="fas fa-file-pdf text-danger me-1"></i>View Document
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Status badge -->
                <div class="col-md-2 text-center py-2">
                    <span class="badge badge-status-<?= e($h['verification_status']) ?> px-3 py-2">
                        <?= e(ucfirst($h['verification_status'])) ?>
                    </span>
                </div>

                <!-- Action buttons (pending only) -->
                <div class="col-md-4">
                    <?php if ($h['verification_status'] === 'pending'): ?>
                    <div class="d-flex gap-2">
                        <!-- Approve -->
                        <form method="POST" class="flex-fill">
                            <?= csrfField() ?>
                            <input type="hidden" name="hospital_id" value="<?= $h['id'] ?>">
                            <button name="action" value="verify" class="btn btn-success w-100">
                                <i class="fas fa-check me-1"></i>Verify
                            </button>
                        </form>

                        <!-- Reject (prompts for reason) -->
                        <form method="POST" class="flex-fill"
                              onsubmit="var r = prompt('Reason (optional):'); this.querySelector('[name=verification_notes]').value = r || ''; return true">
                            <?= csrfField() ?>
                            <input type="hidden" name="hospital_id" value="<?= $h['id'] ?>">
                            <input type="hidden" name="verification_notes" value="">
                            <button name="action" value="reject" class="btn btn-outline-danger w-100">
                                <i class="fas fa-times me-1"></i>Reject
                            </button>
                        </form>
                    </div>

                    <?php elseif ($h['verification_status'] === 'rejected' && $h['verification_notes']): ?>
                        <small class="text-muted">Reason: <?= e($h['verification_notes']) ?></small>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>