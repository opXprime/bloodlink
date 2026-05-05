<?php
// Donor bookings — view current/past bookings and cancel pending ones

$pageTitle = 'My Bookings';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

$db  = Database::getInstance();
$cid = currentCountryId();

// Get donor profile ID
$s = $db->prepare("SELECT id FROM donor_profiles WHERE user_id = :u");
$s->execute([':u' => currentUserId()]);
$dp  = $s->fetch();
$did = $dp['id'] ?? 0;

// ---- Cancel or report a booking ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF()) {
    if (isset($_POST['cancel_booking'])) {
        $bid         = (int)$_POST['booking_id'];
        $cancelNotes = trim($_POST['cancel_notes'] ?? '');

    // Verify booking belongs to this donor and is still pending
    $bs = $db->prepare(
        "SELECT b.*, hp.hospital_name, hp.user_id AS hospital_uid
         FROM bookings b
         JOIN blood_requests br2 ON b.blood_request_id = br2.id
         JOIN hospital_profiles hp ON br2.hospital_id = hp.id
         WHERE b.id = :b AND b.donor_id = :d AND br2.country_id = :c AND b.status = 'pending'"
    );
    $bs->execute([':b' => $bid, ':d' => $did, ':c' => $cid]);
    $bk = $bs->fetch();

    if ($bk) {
        // Update booking status and append cancellation note
        $db->prepare(
            "UPDATE bookings SET status = 'cancelled', notes = CONCAT(IFNULL(notes,''), '\n[Cancelled] ', :cn) WHERE id = :b"
        )->execute([':b' => $bid, ':cn' => $cancelNotes]);

        // Restore donor availability
        $db->prepare("UPDATE donor_profiles SET is_available = 1 WHERE id = :d")
           ->execute([':d' => $did]);

        // Release the time slot if one was booked
        if ($bk['time_slot_id']) {
            $db->prepare("UPDATE time_slots SET booked_count = GREATEST(0, booked_count - 1) WHERE id = :s")
               ->execute([':s' => $bk['time_slot_id']]);
        }

        // Notify the hospital
        $msg = currentUser()['name'] . ' cancelled their booking';
        if ($cancelNotes) $msg .= ': ' . $cancelNotes;
        createNotification($bk['hospital_uid'], 'Booking Cancelled', $msg, 'warning', '/modules/booking/hospital_bookings.php');

        logAction('booking_cancelled', "#$bid");
        setFlash('success', 'Booking cancelled. Availability restored.');
    }
    }
    elseif (isset($_POST['report_booking'])) {
        $bid    = (int)$_POST['booking_id'];
        $reason = trim($_POST['report_reason'] ?? '');

        if (strlen($reason) < 5) {
            setFlash('error', 'Report reason must be at least 5 characters.');
            redirect('/modules/booking/my_bookings.php');
        }

        $bs = $db->prepare(
            "SELECT b.id, hp.user_id AS hospital_uid
             FROM bookings b
             JOIN blood_requests br2 ON b.blood_request_id = br2.id
             JOIN hospital_profiles hp ON br2.hospital_id = hp.id
             WHERE b.id = :b AND b.donor_id = :d AND br2.country_id = :c"
        );
        $bs->execute([':b' => $bid, ':d' => $did, ':c' => $cid]);
        $bk = $bs->fetch();

        if ($bk && !empty($bk['hospital_uid'])) {
            $db->prepare(
                "INSERT INTO reports (reporter_id, reported_id, reason)
                 VALUES (:r, :t, :m)"
            )->execute([
                ':r' => currentUserId(),
                ':t' => $bk['hospital_uid'],
                ':m' => $reason
            ]);
            foreach ($db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                createNotification(
                    (int)$adminId,
                    'New report submitted',
                    'A donor reported a hospital. Review reports.',
                    'warning',
                    '/modules/admin/reports.php'
                );
            }
            logAction('report_created', "donor reported hospital #{$bk['hospital_uid']} booking #$bid");
            setFlash('success', 'Report submitted. Admin will review it.');
        } else {
            setFlash('error', 'Unable to locate hospital account for this booking.');
        }
    }

    redirect('/modules/booking/my_bookings.php');
}

// ---- Load all bookings for this donor ----
$s = $db->prepare(
    "SELECT b.*, br.blood_type, br.urgency, hp.hospital_name, hp.address AS hospital_address,
            ts.slot_date AS ts_date, ts.start_time AS ts_start, ts.end_time AS ts_end
     FROM bookings b
     JOIN blood_requests br ON b.blood_request_id = br.id
     JOIN hospital_profiles hp ON br.hospital_id = hp.id
     LEFT JOIN time_slots ts ON b.time_slot_id = ts.id
     WHERE b.donor_id = :d AND br.country_id = :c
     ORDER BY b.created_at DESC"
);
$s->execute([':d' => $did, ':c' => $cid]);
$bookings = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-calendar-check me-2 text-danger"></i>My Bookings</h2>

<div class="card">
<div class="card-body">
    <?php if (empty($bookings)): ?>
        <p class="text-muted">No bookings. <a href="<?= APP_URL ?>/modules/matching/requests.php">Browse requests</a>.</p>
    <?php else: ?>
        <?php foreach ($bookings as $b):
            $isEmg = in_array($b['urgency'], ['high', 'critical']);
        ?>
        <div class="border rounded p-3 mb-3 <?= $isEmg && in_array($b['status'], ['pending', 'confirmed']) ? 'border-danger' : '' ?>">

            <!-- Booking header: hospital, blood type, urgency, status -->
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong><?= e($b['hospital_name']) ?></strong>
                    <span class="blood-type-badge blood-type-badge-sm ms-2"><?= e($b['blood_type']) ?></span>
                    <span class="badge badge-urgency-<?= e($b['urgency']) ?> ms-1"><?= e(ucfirst($b['urgency'])) ?></span>
                    <span class="badge badge-status-<?= e($b['status']) ?> ms-1"><?= e(ucfirst($b['status'])) ?></span>
                    <?php if ($b['hospital_address']): ?>
                        <br><small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= e($b['hospital_address']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <small class="text-muted"><?= e($b['units']) ?> unit(s)</small><br>
                    <?php if ($b['ts_date']): ?>
                        <small><?= e($b['ts_date']) ?> <?= e(substr($b['ts_start'], 0, 5)) ?>–<?= e(substr($b['ts_end'], 0, 5)) ?></small>
                    <?php elseif ($b['scheduled_date']): ?>
                        <small><?= e($b['scheduled_date']) ?></small>
                        <?php if ($isEmg): ?> <small class="text-danger fw-bold">ASAP</small><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php // Emergency arrival reminder ?>
            <?php if ($isEmg && in_array($b['status'], ['pending', 'confirmed'])): ?>
            <div class="alert alert-warning mt-2 mb-0 py-2">
                <i class="fas fa-clock me-2"></i>You confirmed you can arrive within 1–2 hours. Please head to the hospital.
            </div>
            <?php endif; ?>

            <?php // Cancel button (pending bookings only) ?>
            <?php if ($b['status'] === 'pending'): ?>
            <div class="mt-2">
                <form method="POST" class="d-inline"
                      onsubmit="var r = prompt('Reason (optional):'); if (r === null) return false; this.querySelector('[name=cancel_notes]').value = r || ''; return true;">
                    <?= csrfField() ?>
                    <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                    <input type="hidden" name="cancel_notes" value="">
                    <button type="submit" name="cancel_booking" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-times me-1"></i>Cancel Booking
                    </button>
                </form>
            </div>
            <?php endif; ?>
            <div class="mt-2">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                    <input type="hidden" name="report_booking" value="1">
                    <div class="input-group input-group-sm">
                        <input type="text" name="report_reason" class="form-control"
                               placeholder="Report hospital (reason)" required minlength="5">
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="fas fa-flag me-1"></i>Report Hospital
                        </button>
                    </div>
                </form>
            </div>

        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>