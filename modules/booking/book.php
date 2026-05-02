<?php
// booking page — donor selects units and time slot (or confirms emergency arrival)
// blocks booking if donor is unavailable (weight <45kg) or ineligible (90-day cooldown)
$pageTitle = 'Book Donation';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

// load donor profile
$s = $db->prepare("SELECT * FROM donor_profiles WHERE user_id = :u");
$s->execute([':u' => $uid]);
$donor = $s->fetch();

if (!$donor) {
    setFlash('error', 'Complete profile first.');
    redirect('/modules/donor/profile.php');
}

// ---- BLOCK: 90-day cooldown ----
if (!$donor['is_eligible']) {
    setFlash('warning', 'You are currently ineligible. Next eligible: '
        . e($donor['next_eligible_date'] ?? 'unknown'));
    redirect('/modules/donor/dashboard.php');
}

// ---- BLOCK: weight below 45kg (unavailable) ----
if (!$donor['is_available']) {
    $reason = ($donor['weight_kg'] && $donor['weight_kg'] < 45)
        ? 'Your weight (' . $donor['weight_kg'] . ' kg) is below the 45 kg minimum.'
        : 'You are currently marked as unavailable.';
    setFlash('error', 'You cannot book a donation. ' . $reason
        . ' Update your profile to become eligible.');
    redirect('/modules/donor/dashboard.php');
}

// load the blood request
$rid = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$s = $db->prepare(
    "SELECT br.*, hp.hospital_name, hp.id AS hp_id,
            hp.address AS hospital_address, hp.phone AS hospital_phone,
            hp.email AS hospital_email, hp.verification_status,
            a.name AS area_name, c.name AS city_name, co.name AS country_name
     FROM blood_requests br
     JOIN hospital_profiles hp ON br.hospital_id = hp.id
     LEFT JOIN areas a ON br.area_id = a.id
     LEFT JOIN cities c ON a.city_id = c.id
     LEFT JOIN countries co ON c.country_id = co.id
     WHERE br.id = :r AND br.country_id = :c AND br.status = 'open'"
);
$s->execute([':r' => $rid, ':c' => $cid]);
$req = $s->fetch();

if (!$req) {
    setFlash('error', 'Request not found.');
    redirect('/modules/matching/requests.php');
}

// blood type compatibility check
$canDonateTo = [];
foreach (BLOOD_COMPATIBILITY as $r => $ds) {
    if (in_array($donor['blood_type'], $ds)) $canDonateTo[] = $r;
}
if (!in_array($req['blood_type'], $canDonateTo)) {
    setFlash('error', 'Incompatible.');
    redirect('/modules/matching/requests.php');
}

// already booked this request?
$s = $db->prepare(
    "SELECT id FROM bookings
     WHERE blood_request_id = :r AND donor_id = :d AND status NOT IN('cancelled','rejected')"
);
$s->execute([':r' => $rid, ':d' => $donor['id']]);
if ($s->fetch()) {
    setFlash('info', 'Already booked.');
    redirect('/modules/booking/my_bookings.php');
}

// has another active booking?
$s = $db->prepare(
    "SELECT COUNT(*) FROM bookings WHERE donor_id = :d AND status IN('pending','confirmed')"
);
$s->execute([':d' => $donor['id']]);
$hasActive = (int)$s->fetchColumn() > 0;

// cancel cooldown: 2+ cancellations within 2 hours → 30min wait
$s = $db->prepare(
    "SELECT MAX(updated_at) AS last_cancel, COUNT(*) AS cancel_count
     FROM bookings
     WHERE donor_id = :d AND blood_request_id = :r
       AND status = 'cancelled' AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)"
);
$s->execute([':d' => $donor['id'], ':r' => $rid]);
$cancelInfo = $s->fetch();
$cancelCooldown = false;
$cooldownUntil  = null;
if ($cancelInfo && (int)$cancelInfo['cancel_count'] >= 2 && $cancelInfo['last_cancel']) {
    $cooldownUntil = date('Y-m-d H:i:s', strtotime($cancelInfo['last_cancel']) + 1800);
    if ($cooldownUntil > date('Y-m-d H:i:s')) $cancelCooldown = true;
}

// available time slots (for low/medium urgency)
$isEmergency = in_array($req['urgency'], ['high', 'critical']);
$timeSlots   = [];
if (!$isEmergency) {
    $slots = $db->prepare(
        "SELECT * FROM time_slots
         WHERE hospital_id = :h AND slot_date >= CURDATE()
           AND is_active = 1 AND booked_count < max_donors
         ORDER BY slot_date, start_time"
    );
    $slots->execute([':h' => $req['hp_id']]);
    $timeSlots = $slots->fetchAll();
}

$errors = [];

// ---- handle booking submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } elseif ($hasActive) {
        $errors[] = 'You have an active booking. Complete or cancel it first.';
    } elseif (!$donor['is_eligible']) {
        $errors[] = 'You are ineligible.';
    } elseif (!$donor['is_available']) {
        $errors[] = 'You are unavailable. Check your weight and profile.';
    } elseif ($cancelCooldown) {
        $errors[] = "Frequent cancellation detected. Please wait until $cooldownUntil.";
    } else {
        $units = max(1, (int)($_POST['units'] ?? 1));
        $notes = trim($_POST['notes'] ?? '');
        $rem   = $req['units_needed'] - $req['units_fulfilled'];

        if ($units > $rem) $errors[] = "Only $rem unit(s) needed.";

        $sd     = null;
        $slotId = null;

        if ($isEmergency) {
            $sd = date('Y-m-d');
        } else {
            $slotId = (int)($_POST['time_slot_id'] ?? 0);
            if ($slotId < 1) {
                $errors[] = 'Please select a time slot.';
            } else {
                $sv = $db->prepare(
                    "SELECT * FROM time_slots
                     WHERE id = :s AND hospital_id = :h
                       AND is_active = 1 AND booked_count < max_donors"
                );
                $sv->execute([':s' => $slotId, ':h' => $req['hp_id']]);
                $slot = $sv->fetch();

                if (!$slot) {
                    $errors[] = 'Time slot unavailable.';
                } else {
                    $sd = $slot['slot_date'];
                }
            }
        }

        if (empty($errors)) {
            $db->beginTransaction();
            try {
                // create the booking
                $db->prepare(
                    "INSERT INTO bookings
                     (blood_request_id, donor_id, hospital_id, country_id,
                      time_slot_id, units, scheduled_date, notes)
                     VALUES (:r, :d, :h, :c, :ts, :u, :s, :n)"
                )->execute([
                    ':r'  => $rid,         ':d'  => $donor['id'],
                    ':h'  => $req['hp_id'], ':c'  => $cid,
                    ':ts' => $slotId,       ':u'  => $units,
                    ':s'  => $sd,           ':n'  => $notes
                ]);

                // increment slot booked count
                if ($slotId) {
                    $db->prepare(
                        "UPDATE time_slots SET booked_count = booked_count + 1 WHERE id = :s"
                    )->execute([':s' => $slotId]);
                }

                // mark donor unavailable while booking is active
                $db->prepare(
                    "UPDATE donor_profiles SET is_available = 0 WHERE id = :d"
                )->execute([':d' => $donor['id']]);

                $db->commit();
                logAction('booking_created', "Req#$rid {$units}u");

                // notify hospital
                $s = $db->prepare("SELECT user_id FROM hospital_profiles WHERE id = :h");
                $s->execute([':h' => $req['hp_id']]);
                $hu = $s->fetch();

                if ($hu) {
                    createNotification(
                        $hu['user_id'],
                        'New Booking',
                        currentUser()['name'] . " booked {$donor['blood_type']} ($units unit(s))"
                            . ($isEmergency ? ' [ASAP/Emergency]' : ''),
                        'info',
                        '/modules/booking/hospital_bookings.php'
                    );
                }

                setFlash('success', 'Booking submitted!');
                redirect('/modules/booking/my_bookings.php');
            } catch (PDOException $ex) {
                $db->rollBack();
                $errors[] = 'Booking failed.';
            }
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4">
    <i class="fas fa-calendar-plus me-2 text-danger"></i>Book Donation
</h2>

<?php if ($hasActive): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        You have an active booking. Complete or cancel it first.
    </div>
<?php endif; ?>

<?php if ($cancelCooldown): ?>
    <div class="alert alert-danger">
        <i class="fas fa-ban me-2"></i>
        You have cancelled this booking multiple times. Please wait until
        <strong><?= e($cooldownUntil) ?></strong> before rebooking.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <!-- left column: hospital details -->
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Hospital & Request Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th>Hospital</th>
                        <td>
                            <strong><?= e($req['hospital_name']) ?></strong>
                            <?php if ($req['verification_status'] === 'verified'): ?>
                                <i class="fas fa-check-circle text-success ms-1"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($req['hospital_address']): ?>
                        <tr>
                            <th>Address</th>
                            <td>
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?= e($req['hospital_address']) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Area</th>
                        <td>
                            <?= e($req['area_name'] ?? '-') ?>
                            <?php if ($req['city_name']): ?>, <?= e($req['city_name']) ?><?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($req['hospital_phone']): ?>
                        <tr>
                            <th>Phone</th>
                            <td>
                                <i class="fas fa-phone me-1"></i>
                                <?= e($req['hospital_phone']) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr><td colspan="2"><hr class="my-1"></td></tr>
                    <tr>
                        <th>Blood Needed</th>
                        <td>
                            <span class="blood-type-badge blood-type-badge-sm">
                                <?= e($req['blood_type']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Remaining</th>
                        <td><?= e($req['units_needed'] - $req['units_fulfilled']) ?> of <?= e($req['units_needed']) ?></td>
                    </tr>
                    <tr>
                        <th>Urgency</th>
                        <td>
                            <span class="badge badge-urgency-<?= e($req['urgency']) ?>">
                                <?= e(ucfirst($req['urgency'])) ?>
                            </span>
                            <?php if ($isEmergency): ?>
                                <span class="badge bg-danger ms-1">Emergency — ASAP</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Deadline</th>
                        <td><?= e($req['deadline'] ?? 'None') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- right column: booking form -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Confirm Booking</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <strong>Your Blood:</strong> <?= e($donor['blood_type']) ?>
                    — Compatible with <?= e($req['blood_type']) ?>
                </div>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="request_id" value="<?= $rid ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Units to Donate *</label>
                        <input type="number" class="form-control" name="units"
                               value="1" min="1"
                               max="<?= $req['units_needed'] - $req['units_fulfilled'] ?>"
                               required>
                    </div>

                    <?php if ($isEmergency): ?>
                        <div class="alert alert-danger mb-3">
                            <i class="fas fa-bolt me-2"></i>
                            <strong>Emergency Request:</strong> This is an urgent blood request.
                            You must confirm you can arrive at the hospital within 1–2 hours.
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox"
                                   id="arrivalConfirm" required>
                            <label class="form-check-label fw-bold text-danger"
                                   for="arrivalConfirm">
                                I confirm I can arrive at the hospital within 1–2 hours
                            </label>
                        </div>
                    <?php elseif (!empty($timeSlots)): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Date & Time Slot *</label>
                            <select class="form-select" name="time_slot_id" required>
                                <option value="">— Select a time slot —</option>
                                <?php foreach ($timeSlots as $ts): ?>
                                    <option value="<?= $ts['id'] ?>">
                                        <?= e($ts['slot_date']) ?> |
                                        <?= e(substr($ts['start_time'], 0, 5)) ?> –
                                        <?= e(substr($ts['end_time'], 0, 5)) ?>
                                        (<?= $ts['max_donors'] - $ts['booked_count'] ?> spot<?= ($ts['max_donors'] - $ts['booked_count']) > 1 ? 's' : '' ?> left)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            No time slots available yet. The hospital hasn't set available times.
                            Please check back later or contact the hospital.
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"
                                  placeholder="Any special requirements..."></textarea>
                    </div>

                    <button type="submit" name="confirm_booking"
                            class="btn btn-blood w-100"
                            <?= ($hasActive || !$donor['is_eligible'] || $cancelCooldown
                                || (!$isEmergency && empty($timeSlots)))
                                ? 'disabled' : '' ?>>
                        <i class="fas fa-check me-2"></i>
                        <?= $isEmergency ? 'Book ASAP' : 'Submit Booking' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>