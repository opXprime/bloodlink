<?php
// donor dashboard — stats, eligibility status, availability management
// Ineligible shown when weight <45kg OR in 90-day cooldown
$pageTitle = 'Donor Dashboard';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

$stmt = $db->prepare(
    "SELECT dp.*, a.name AS area_name
     FROM donor_profiles dp
     LEFT JOIN areas a ON dp.area_id = a.id
     WHERE dp.user_id = :u"
);
$stmt->execute([':u' => $uid]);
$profile = $stmt->fetch();
$did = $profile['id'] ?? 0;

// ---- auto-check 90-day eligibility on page load ----
if ($profile && $profile['last_donation_date'] && !$profile['is_eligible']) {
    if (isDonorEligible($profile['last_donation_date'])) {
        $db->prepare(
            "UPDATE donor_profiles SET is_eligible = 1, next_eligible_date = NULL WHERE id = :id"
        )->execute([':id' => $did]);
        $profile['is_eligible']        = 1;
        $profile['next_eligible_date'] = null;
    }
}

// ---- weight check ----
$weightVal     = $profile['weight_kg'] ?? null;
$isUnderweight = ($weightVal && $weightVal < 45);

// auto-mark unavailable if underweight
if ($profile && $isUnderweight && $profile['is_available']) {
    $db->prepare("UPDATE donor_profiles SET is_available = 0 WHERE id = :id")
        ->execute([':id' => $did]);
    $profile['is_available'] = 0;
}

// ---- combined eligibility: truly eligible only if BOTH weight OK AND 90-day passed ----
$isTrulyEligible = $profile && $profile['is_eligible'] && !$isUnderweight;

// ---- load stats ----
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM bookings b
     JOIN blood_requests br ON b.blood_request_id = br.id
     WHERE b.donor_id = :d AND br.country_id = :c"
);
$stmt->execute([':d' => $did, ':c' => $cid]);
$totalBookings = $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(dh.units), 0)
     FROM donation_history dh
     JOIN hospital_profiles hp ON dh.hospital_id = hp.id
     JOIN users u ON hp.user_id = u.id
     WHERE dh.donor_id = :d AND u.country_id = :c"
);
$stmt->execute([':d' => $did, ':c' => $cid]);
$totalDonated = $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM blood_requests WHERE country_id = :c AND status = 'open'"
);
$stmt->execute([':c' => $cid]);
$openReqs = $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT b.*, br.blood_type, br.urgency, hp.hospital_name
     FROM bookings b
     JOIN blood_requests br ON b.blood_request_id = br.id
     JOIN hospital_profiles hp ON br.hospital_id = hp.id
     WHERE b.donor_id = :d AND br.country_id = :c
     ORDER BY b.created_at DESC LIMIT 5"
);
$stmt->execute([':d' => $did, ':c' => $cid]);
$recent = $stmt->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4">
    <i class="fas fa-tachometer-alt me-2 text-danger"></i>Donor Dashboard
</h2>

<?php if (!$profile): ?>
    <div class="alert alert-warning">
        Please <a href="profile.php">complete your profile</a>.
    </div>
<?php endif; ?>

<!-- ===== 90-DAY COOLDOWN WARNING ===== -->
<?php if ($profile && !$profile['is_eligible']): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>You are currently ineligible to donate.</strong>
        For your safety, a 12-week (90 day) waiting period is required between donations.
        <?php if ($profile['next_eligible_date']): ?>
            <br>Your next eligible date: <strong><?= e($profile['next_eligible_date']) ?></strong>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===== UNDERWEIGHT WARNING ===== -->
<?php if ($isUnderweight): ?>
    <div class="alert alert-danger">
        <i class="fas fa-weight me-2"></i>
        <strong>You are ineligible to donate due to weight.</strong>
        Your weight (<?= e($weightVal) ?> kg) is below the 45 kg minimum.
        You have been automatically marked as unavailable and
        <strong>cannot be set to Available</strong> until your weight meets the requirement.
        <br>
        <small class="mt-2 d-inline-block">
            <i class="fas fa-info-circle me-1"></i>
            Update your weight in <a href="profile.php" class="alert-link">your profile</a>
            to 45 kg or above to regain eligibility.
        </small>
    </div>
<?php endif; ?>

<!-- ===== STAT CARDS ===== -->
<div class="row g-4 mb-4">
    <div class="col-6 col-md-3">
        <div class="card">
            <div class="card-body dash-stat">
                <i class="fas fa-tint text-danger fa-2x"></i>
                <div class="number"><?= e($profile['blood_type'] ?? 'N/A') ?></div>
                <div class="label">Blood Type</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card">
            <div class="card-body dash-stat">
                <i class="fas fa-hand-holding-heart text-danger fa-2x"></i>
                <div class="number"><?= $totalDonated ?></div>
                <div class="label">Units Donated</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card">
            <div class="card-body dash-stat">
                <i class="fas fa-calendar-check text-danger fa-2x"></i>
                <div class="number"><?= $totalBookings ?></div>
                <div class="label">My Bookings</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= APP_URL ?>/modules/matching/requests.php" class="text-decoration-none">
            <div class="card <?= $openReqs > 0 ? 'border-danger' : '' ?>">
                <div class="card-body dash-stat">
                    <i class="fas fa-bullseye text-danger fa-2x"></i>
                    <div class="number text-danger"><?= $openReqs ?></div>
                    <div class="label">Open Requests</div>
                    <?php if ($openReqs > 0): ?>
                        <small class="text-danger fw-bold">Click to view</small>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- ===== AVAILABILITY + ELIGIBILITY ===== -->
<?php if ($profile): ?>
    <div class="row g-4 mb-4">
        <!-- Availability -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="mb-1">Availability</h6>
                        <?php if ($profile['is_available']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-circle me-1"></i>Available
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger">
                                <i class="fas fa-times-circle me-1"></i>Unavailable
                            </span>
                        <?php endif; ?>

                        <?php if ($isUnderweight): ?>
                            <br><small class="text-danger mt-1 d-inline-block">
                                <i class="fas fa-lock me-1"></i>Locked due to weight below 45 kg
                            </small>
                        <?php endif; ?>
                    </div>

                    <?php if ($isUnderweight): ?>
                        <button type="button" class="btn btn-sm btn-secondary"
                                disabled title="Update your weight to 45 kg+ in your profile">
                            <i class="fas fa-lock me-1"></i>Set Available
                        </button>
                    <?php else: ?>
                        <form method="POST" action="toggle_availability.php">
                            <?= csrfField() ?>
                            <button class="btn btn-sm <?= $profile['is_available'] ? 'btn-outline-secondary' : 'btn-success' ?>">
                                <?= $profile['is_available'] ? 'Set Unavailable' : 'Set Available' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Eligibility — red when EITHER underweight OR 90-day cooldown -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-1">Eligibility</h6>
                    <?php if ($isTrulyEligible): ?>
                        <span class="badge bg-success">
                            <i class="fas fa-check-circle me-1"></i>Eligible
                        </span>
                        <?php if (!$profile['is_available']): ?>
                            <br><small class="text-muted mt-1 d-inline-block">
                                <i class="fas fa-info-circle me-1"></i>
                                You are eligible — remember to set yourself Available to be matched.
                            </small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-danger">
                            <i class="fas fa-times-circle me-1"></i>Ineligible
                        </span>
                        <?php if (!$profile['is_eligible'] && $profile['next_eligible_date']): ?>
                            <br><small class="text-muted mt-1 d-inline-block">
                                90-day cooldown — eligible again on <strong><?= e($profile['next_eligible_date']) ?></strong>
                            </small>
                        <?php endif; ?>
                        <?php if ($isUnderweight): ?>
                            <br><small class="text-danger mt-1 d-inline-block">
                                <i class="fas fa-weight me-1"></i>Weight below 45 kg minimum
                            </small>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($profile['last_donation_date']): ?>
                        <br><small class="text-muted">
                            Last donated: <?= e($profile['last_donation_date']) ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ===== QUICK NAV CARDS ===== -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <a href="profile.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-user-edit fa-2x text-danger mb-2"></i>
                <h6>Edit Profile</h6>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= APP_URL ?>/modules/matching/requests.php"
           class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-search fa-2x text-danger mb-2"></i>
                <h6>Find Requests</h6>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="history.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-history fa-2x text-danger mb-2"></i>
                <h6>History</h6>
            </div>
        </a>
    </div>

    <?php
    $ur = $db->prepare(
        "SELECT COUNT(*) FROM contact_messages
         WHERE user_id = :u AND admin_reply IS NOT NULL AND is_read = 0"
    );
    $ur->execute([':u' => $uid]);
    $unreadReplies = (int)$ur->fetchColumn();
    ?>
    <div class="col-md-3">
        <a href="<?= APP_URL ?>/contact.php"
           class="card text-decoration-none h-100 position-relative">
            <div class="card-body text-center p-4">
                <i class="fas fa-envelope fa-2x text-danger mb-2"></i>
                <h6>Contact Us</h6>
            </div>
            <?php if ($unreadReplies > 0): ?>
                <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-warning text-dark"
                      style="font-size:.7em">
                    <i class="fas fa-exclamation me-1"></i><?= $unreadReplies ?>
                </span>
            <?php endif; ?>
        </a>
    </div>
</div>

<!-- ===== RECENT BOOKINGS ===== -->
<?php if ($recent): ?>
    <div class="card mb-4">
        <div class="card-header bg-white"><h5 class="mb-0">Recent Bookings</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Hospital</th>
                            <th>Blood</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $b): ?>
                            <tr>
                                <td><?= e($b['hospital_name']) ?></td>
                                <td>
                                    <span class="blood-type-badge blood-type-badge-sm">
                                        <?= e($b['blood_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-urgency-<?= e($b['urgency']) ?>">
                                        <?= e(ucfirst($b['urgency'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-status-<?= e($b['status']) ?>">
                                        <?= e(ucfirst($b['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e($b['scheduled_date'] ?? date('Y-m-d', strtotime($b['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ===== DONATION GUIDELINES ===== -->
<div class="card border-info">
    <div class="card-header bg-info bg-opacity-10">
        <h5 class="mb-0 text-info">
            <i class="fas fa-book-medical me-2"></i>Blood Donation Guidelines
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6 class="fw-bold">Before Donation:</h6>
                <p class="text-muted mb-1">• Get a good night's sleep (at least 7 hours)</p>
                <p class="text-muted mb-1">• Eat a healthy meal 2-3 hours before</p>
                <p class="text-muted mb-1">• Drink plenty of water (at least 500ml)</p>
                <p class="text-muted mb-1">• Avoid alcohol for at least 24 hours before</p>
                <p class="text-muted mb-1">• Wear comfortable clothing with sleeves that roll up</p>
                <p class="text-muted mb-1">• Bring a valid ID and your booking confirmation</p>
            </div>
            <div class="col-md-6">
                <h6 class="fw-bold">After Donation:</h6>
                <p class="text-muted mb-1">• Rest for at least 10-15 minutes at the donation site</p>
                <p class="text-muted mb-1">• Drink extra fluids for the next 24-48 hours</p>
                <p class="text-muted mb-1">• Avoid heavy lifting or strenuous exercise for 24 hours</p>
                <p class="text-muted mb-1">• Eat iron-rich foods (spinach, red meat, beans)</p>
                <p class="text-muted mb-1">• Keep the bandage on for at least 4 hours</p>
                <p class="text-muted mb-1">• Contact the hospital if you feel unwell</p>
            </div>
        </div>
        <div class="alert alert-warning mt-3 mb-0">
            <i class="fas fa-info-circle me-2"></i>
            You must wait <strong>12 weeks (90 days)</strong> between donations.
            The system will automatically manage your eligibility.
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>