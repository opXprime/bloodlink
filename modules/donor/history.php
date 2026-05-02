<?php
// Donor donation history — list of past completed donations

$pageTitle = 'Donation History';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

$db  = Database::getInstance();
$uid = currentUserId();

// Get donor profile ID
$s = $db->prepare("SELECT id FROM donor_profiles WHERE user_id = :u");
$s->execute([':u' => $uid]);
$dp  = $s->fetch();
$did = $dp['id'] ?? 0;

// Load all completed donations for this donor (newest first)
$s = $db->prepare(
    "SELECT dh.*, hp.hospital_name
     FROM donation_history dh
     JOIN hospital_profiles hp ON dh.hospital_id = hp.id
     WHERE dh.donor_id = :d
     ORDER BY dh.donation_date DESC"
);
$s->execute([':d' => $did]);
$history = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-history me-2 text-danger"></i>Donation History</h2>

<div class="card">
<div class="card-body">
    <?php if (empty($history)): ?>
        <!-- No donations yet -->
        <p class="text-muted">No donation records yet. Once you complete a booking, your donation history will appear here.</p>
    <?php else: ?>
        <!-- Donation records table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr><th>Date</th><th>Hospital</th><th>Blood Type</th><th>Units</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e($h['donation_date']) ?></td>
                        <td><?= e($h['hospital_name']) ?></td>
                        <td><span class="blood-type-badge blood-type-badge-sm"><?= e($h['blood_type']) ?></span></td>
                        <td><?= e($h['units']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>