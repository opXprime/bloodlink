<?php
// Hospital booking management — confirm, reject, complete donations
// Completion triggers atomic transaction: record donation, update eligibility, check fulfilment

$pageTitle = 'Manage Bookings';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('hospital');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

// Load hospital profile
$s = $db->prepare("SELECT * FROM hospital_profiles WHERE user_id = :u");
$s->execute([':u' => $uid]);
$hospital = $s->fetch();
$hid = $hospital['id'] ?? 0;

// Helper: get user_id from donor_profiles
$getDonorUid = function($donorId) use ($db) {
    $s = $db->prepare("SELECT user_id FROM donor_profiles WHERE id = :d");
    $s->execute([':d' => $donorId]);
    $r = $s->fetch();
    return $r ? $r['user_id'] : null;
};

// ---- Handle booking actions (confirm / reject / complete) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF()) {
    $bid    = (int)($_POST['booking_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    // Verify this booking belongs to this hospital and country
    $s = $db->prepare(
        "SELECT b.*, br.units_needed, br.units_fulfilled, br.id AS req_id, br.urgency, dp.user_id AS donor_uid
         FROM bookings b
         JOIN blood_requests br ON b.blood_request_id = br.id
         JOIN donor_profiles dp ON b.donor_id = dp.id
         WHERE b.id = :b AND br.hospital_id = :h AND br.country_id = :c"
    );
    $s->execute([':b' => $bid, ':h' => $hid, ':c' => $cid]);
    $bk = $s->fetch();

    if ($bk) {

        // ---- Confirm a pending booking ----
        if ($action === 'confirm' && $bk['status'] === 'pending') {
            // Overbooking check — don't exceed units needed
            $obc = $db->prepare(
                "SELECT COALESCE(SUM(units), 0) FROM bookings
                 WHERE blood_request_id = :r AND status IN ('confirmed','completed')"
            );
            $obc->execute([':r' => $bk['req_id']]);
            $cu = (int)$obc->fetchColumn();

            if ($cu + $bk['units'] > $bk['units_needed']) {
                setFlash('warning', 'Would exceed units needed.');
                redirect('/modules/booking/hospital_bookings.php');
            }

            $db->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = :b")
               ->execute([':b' => $bid]);

            $du = $getDonorUid($bk['donor_id']);
            if ($du) {
                createNotification($du, 'Booking Confirmed',
                    $hospital['hospital_name'] . ' confirmed your booking.',
                    'success', '/modules/booking/my_bookings.php');
            }
            logAction('booking_confirmed', "#$bid");

        // ---- Reject a pending booking ----
        } elseif ($action === 'reject' && $bk['status'] === 'pending') {
            $db->prepare("UPDATE bookings SET status = 'rejected' WHERE id = :b")
               ->execute([':b' => $bid]);

            $du = $getDonorUid($bk['donor_id']);
            if ($du) {
                createNotification($du, 'Booking Rejected',
                    $hospital['hospital_name'] . ' could not accept your booking.',
                    'warning', '/modules/booking/my_bookings.php');
            }
            logAction('booking_rejected', "#$bid");

        } elseif ($action === 'report') {
            $reason = trim($_POST['report_reason'] ?? '');
            if (strlen($reason) < 5) {
                setFlash('error', 'Report reason must be at least 5 characters.');
                redirect('/modules/booking/hospital_bookings.php');
            }
            if (!empty($bk['donor_uid'])) {
                $db->prepare(
                    "INSERT INTO reports (reporter_id, reported_id, reason)
                     VALUES (:r, :t, :m)"
                )->execute([
                    ':r' => $uid,
                    ':t' => $bk['donor_uid'],
                    ':m' => $reason
                ]);
                foreach ($db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                    createNotification(
                        (int)$adminId,
                        'New report submitted',
                        'A hospital reported a donor. Review reports.',
                        'warning',
                        '/modules/admin/reports.php'
                    );
                }
                logAction('report_created', "hospital #$hid reported donor #{$bk['donor_uid']} booking #$bid");
                setFlash('success', 'Report submitted. Admin will review it.');
            } else {
                setFlash('error', 'Unable to locate donor account for this booking.');
            }
            redirect('/modules/booking/hospital_bookings.php');

        // ---- Complete a confirmed booking (atomic transaction) ----
        } elseif ($action === 'complete' && $bk['status'] === 'confirmed') {
            $db->beginTransaction();
            try {
                // Mark booking as completed
                $db->prepare("UPDATE bookings SET status = 'completed' WHERE id = :b")
                   ->execute([':b' => $bid]);

                // Increment units fulfilled on the request
                $db->prepare("UPDATE blood_requests SET units_fulfilled = units_fulfilled + :u WHERE id = :r")
                   ->execute([':u' => $bk['units'], ':r' => $bk['req_id']]);

                // Get donor blood type for donation history
                $s = $db->prepare("SELECT blood_type FROM donor_profiles WHERE id = :d");
                $s->execute([':d' => $bk['donor_id']]);
                $dpf = $s->fetch();

                // Record in donation history
                $db->prepare(
                    "INSERT INTO donation_history (donor_id, hospital_id, booking_id, blood_type, units, donation_date)
                     VALUES (:d, :h, :b, :bt, :u, CURDATE())"
                )->execute([
                    ':d' => $bk['donor_id'], ':h' => $hid, ':b' => $bid,
                    ':bt' => $dpf['blood_type'], ':u' => $bk['units']
                ]);

                // Set donor ineligible for 90 days and disable availability
                markDonorPostDonation($bk['donor_id'], date('Y-m-d'));

                // Check if request is now fully fulfilled
                $s = $db->prepare("SELECT units_needed, units_fulfilled FROM blood_requests WHERE id = :r");
                $s->execute([':r' => $bk['req_id']]);
                $rc = $s->fetch();

                if ($rc && $rc['units_fulfilled'] >= $rc['units_needed']) {
                    $db->prepare("UPDATE blood_requests SET status = 'fulfilled' WHERE id = :r")
                       ->execute([':r' => $bk['req_id']]);
                }

                $db->commit();

                // Notify donor of completion and next eligible date
                $du = $getDonorUid($bk['donor_id']);
                if ($du) {
                    $ned = date('Y-m-d', strtotime('+90 days'));
                    createNotification($du, 'Donation Completed!',
                        'Thank you! You are ineligible until ' . $ned . ' (12 weeks).',
                        'success', '/modules/donor/history.php');
                }
                logAction('booking_completed', "#$bid");

            } catch (PDOException $ex) {
                $db->rollBack();
            }
        }
    }

    setFlash('success', 'Updated.');
    redirect('/modules/booking/hospital_bookings.php');
}

// ---- Load bookings with donor info (phone only, no email for privacy) ----
$s = $db->prepare(
    "SELECT b.*, br.blood_type, br.urgency, u.name AS donor_name,
            dp.phone AS donor_phone, dp.blood_type AS donor_blood
     FROM bookings b
     JOIN blood_requests br ON b.blood_request_id = br.id
     JOIN donor_profiles dp ON b.donor_id = dp.id
     JOIN users u ON dp.user_id = u.id
     WHERE br.hospital_id = :h AND br.country_id = :c
     ORDER BY FIELD(b.status, 'pending', 'confirmed', 'completed', 'rejected', 'cancelled'), b.created_at DESC"
);
$s->execute([':h' => $hid, ':c' => $cid]);
$bookings = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-calendar-check me-2 text-danger"></i>Manage Bookings</h2>

<div class="card">
<div class="card-body">
    <?php if (empty($bookings)): ?>
        <p class="text-muted">No bookings yet.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Donor</th><th>Phone</th><th>Blood</th><th>Units</th><th>Scheduled</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b):
                    $isEmg = in_array($b['urgency'], ['high', 'critical']);
                ?>
                <tr class="<?= $isEmg && in_array($b['status'], ['pending', 'confirmed']) ? 'table-warning' : '' ?>">
                    <!-- Donor name and blood type -->
                    <td>
                        <strong><?= e($b['donor_name']) ?></strong><br>
                        <small class="text-muted"><?= e($b['donor_blood']) ?></small>
                    </td>

                    <!-- Phone number -->
                    <td>
                        <?php if ($b['donor_phone']): ?>
                            <i class="fas fa-phone text-muted me-1"></i><?= e($b['donor_phone']) ?>
                        <?php else: ?>
                            <small class="text-muted">Not provided</small>
                        <?php endif; ?>
                    </td>

                    <td><span class="blood-type-badge blood-type-badge-sm"><?= e($b['blood_type']) ?></span></td>
                    <td><?= e($b['units']) ?></td>

                    <!-- Scheduled date (emergency bookings show ASAP) -->
                    <td>
                        <?= e($b['scheduled_date'] ?? '-') ?>
                        <?php if ($isEmg): ?>
                            <br><small class="text-danger fw-bold">ASAP — Donor confirmed arrival</small>
                        <?php endif; ?>
                    </td>

                    <td><span class="badge badge-status-<?= e($b['status']) ?>"><?= e(ucfirst($b['status'])) ?></span></td>

                    <!-- Action buttons based on current status -->
                    <td>
                        <?php if ($b['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                            <button name="action" value="confirm" class="btn btn-sm btn-success">
                                <i class="fas fa-check me-1"></i>Confirm
                            </button>
                            <button name="action" value="reject" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-times me-1"></i>Reject
                            </button>
                        </form>

                        <?php elseif ($b['status'] === 'confirmed'): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Mark as complete?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                            <button name="action" value="complete" class="btn btn-sm btn-blood">
                                <i class="fas fa-check-double me-1"></i>Complete
                            </button>
                        </form>
                        <?php endif; ?>
                        <div class="mt-2">
                            <form method="POST" class="input-group input-group-sm">
                                <?= csrfField() ?>
                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="action" value="report">
                                <input type="text" name="report_reason" class="form-control"
                                       placeholder="Report donor (reason)" required minlength="5">
                                <button type="submit" class="btn btn-outline-danger">Report</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>