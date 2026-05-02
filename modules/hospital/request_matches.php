<?php
// matched donors view — shows scored/ranked donors for a blood request
// donors are ranked by composite score with urgency-adaptive distance labels
$pageTitle = 'Request Matches';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('hospital');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

// ---- load hospital profile ----
$s = $db->prepare("SELECT * FROM hospital_profiles WHERE user_id = :u");
$s->execute([':u' => $uid]);
$hospital = $s->fetch();
$hid = $hospital['id'] ?? 0;

// ---- load the blood request ----
$rid = (int)($_GET['id'] ?? 0);
$s = $db->prepare(
    "SELECT br.*, a.name AS area_name, a.id AS req_area_id
     FROM blood_requests br
     LEFT JOIN areas a ON br.area_id = a.id
     WHERE br.id = :r AND br.hospital_id = :h AND br.country_id = :c"
);
$s->execute([':r' => $rid, ':h' => $hid, ':c' => $cid]);
$request = $s->fetch();

if (!$request) {
    setFlash('error', 'Not found.');
    redirect('/modules/hospital/requests.php');
}

// ---- handle notify action ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_top']) && verifyCSRF()) {
    $topN     = (int)($_POST['top_n'] ?? 10);
    $notified = notifyTopMatches($rid, $topN);
    logAction('notify_top_matches', "Request#$rid notified $notified");
    setFlash('success', "Notified $notified donors!");
    redirect("/modules/hospital/request_matches.php?id=$rid");
}

$isFulfilled    = in_array($request['status'], ['fulfilled', 'closed']);
$unitsRemaining = $request['units_needed'] - $request['units_fulfilled'];
$reqAreaId      = $request['req_area_id'] ?? null;

// get urgency-based radius thresholds for distance labelling
// e.g. medium = primary 15km, expanded 30km
$radii = getUrgencyRadius($request['urgency']);

// ---- for fulfilled/closed: show completion history ----
$completions = [];
if ($isFulfilled) {
    $s = $db->prepare(
        "SELECT dh.*, u.name AS donor_name, dp.blood_type AS donor_blood
         FROM donation_history dh
         JOIN donor_profiles dp ON dh.donor_id = dp.id
         JOIN users u ON dp.user_id = u.id
         WHERE dh.hospital_id = :h
           AND dh.booking_id IN (SELECT id FROM bookings WHERE blood_request_id = :r)
         ORDER BY dh.donation_date DESC"
    );
    $s->execute([':h' => $hid, ':r' => $rid]);
    $completions = $s->fetchAll();
}

// ---- for open: get matched donors ranked by composite score ----
$matches = [];
if (!$isFulfilled) {
    $matches = findMatchedDonors($rid, 30);
}

require_once APP_ROOT . '/includes/header.php';
?>

<!-- ========== PAGE HEADER ========== -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1">
            <i class="fas fa-bullseye me-2 text-danger"></i>
            <?= $isFulfilled ? 'Request Summary' : 'Matched Donors' ?>
        </h2>
        <p class="text-muted mb-0">
            Request #<?= $rid ?> —
            <span class="blood-type-badge blood-type-badge-sm"><?= e($request['blood_type']) ?></span>
            <span class="badge badge-urgency-<?= e($request['urgency']) ?> ms-2">
                <?= e(ucfirst($request['urgency'])) ?>
            </span>
            <span class="badge badge-status-<?= e($request['status']) ?> ms-1">
                <?= e(ucfirst($request['status'])) ?>
            </span>
        </p>
    </div>
    <a href="<?= APP_URL ?>/modules/hospital/requests.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>


<?php if ($isFulfilled): ?>
<!-- ========== FULFILLED / CLOSED VIEW ========== -->

    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        <strong>This request has been <?= e($request['status']) ?>.</strong>
        <?= e($request['units_fulfilled']) ?> of <?= e($request['units_needed']) ?> unit(s) fulfilled.
    </div>

    <?php if (!empty($completions)): ?>
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Donation Records</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Donor</th>
                                <th>Blood Type</th>
                                <th>Units</th>
                                <th>Donation Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completions as $c): ?>
                                <tr>
                                    <td><strong><?= e($c['donor_name']) ?></strong></td>
                                    <td>
                                        <span class="blood-type-badge blood-type-badge-sm">
                                            <?= e($c['donor_blood']) ?>
                                        </span>
                                    </td>
                                    <td><?= e($c['units']) ?></td>
                                    <td><?= e($c['donation_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-muted">
                No donation records found for this request.
            </div>
        </div>
    <?php endif; ?>


<?php else: ?>
<!-- ========== OPEN REQUEST VIEW ========== -->

    <!-- notify matched donors -->
    <?php if (count($matches) > 0): ?>
        <div class="card mb-4 border-warning">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="mb-1">
                        <i class="fas fa-bell text-warning me-2"></i>Notify Matched Donors
                    </h5>
                    <p class="text-muted mb-0 small">
                        Send notifications to top-ranked compatible donors.
                    </p>
                </div>
                <form method="POST" class="d-flex gap-2">
                    <?= csrfField() ?>
                    <?php
                    // auto-select notify count based on urgency
                    $autoN = match ($request['urgency']) {
                        'critical' => 20,
                        'high'     => 10,
                        default    => 5,
                    };
                    ?>
                    <select name="top_n" class="form-select form-select-sm" style="width:100px">
                        <option value="5"  <?= $autoN === 5  ? 'selected' : '' ?>>Top 5</option>
                        <option value="10" <?= $autoN === 10 ? 'selected' : '' ?>>Top 10</option>
                        <option value="15"                                      >Top 15</option>
                        <option value="20" <?= $autoN === 20 ? 'selected' : '' ?>>Top 20</option>
                    </select>
                    <button type="submit" name="notify_top" class="btn btn-warning fw-bold">
                        <i class="fas fa-paper-plane me-1"></i>Notify
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- matched donors table -->
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Eligible Donors (<?= count($matches) ?>)</h5>
            <div class="d-flex align-items-center gap-3">
                <!-- distance legend with actual urgency-adaptive km thresholds -->
                <small class="text-muted">
                    <span class="text-success">●</span> Within <?= $radii['primary'] ?> km
                    <span class="text-warning ms-2">●</span> Extended <?= $radii['expanded'] ?> km
                    <span class="text-danger ms-2">●</span> Far (&gt;<?= $radii['expanded'] ?> km)
                </small>
                <input type="text" id="dSearch"
                       class="form-control form-control-sm"
                       placeholder="Search..." style="width:180px"
                       oninput="document.querySelectorAll('.dr').forEach(r => {
                           r.style.display = r.dataset.n.includes(this.value.toLowerCase()) ? '' : 'none';
                       })">
            </div>
        </div>

        <div class="card-body">
            <?php if (empty($matches)): ?>
                <p class="text-muted">
                    No eligible compatible donors found. Try notifying after more donors register.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Donor</th>
                                <th>Blood</th>
                                <th>Distance</th>
                                <th>Last Donation</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($matches as $i => $m):
                            $d           = $m['donor'];
                            $distDisplay = formatDistance($m['distance'], $d['area_id'] ?? null, $reqAreaId);

                            // ---- distance category using urgency-adaptive thresholds ----
                            // within_radius: donor is within primary radius (e.g. 15km for medium)
                            // extended:      donor is beyond primary but within expanded (e.g. 15-30km)
                            // far:           donor is beyond expanded radius (e.g. 30+ km)
                            $isWithin   = $m['within_radius'];
                            $isExtended = (
                                !$isWithin
                                && $m['distance'] !== null
                                && $m['distance'] <= $radii['expanded']
                            );
                            $isFar = (
                                $m['distance'] !== null
                                && $m['distance'] > $radii['expanded']
                            );
                        ?>
                            <tr class="dr <?= $isFar ? 'table-light' : '' ?>"
                                data-n="<?= e(strtolower($d['donor_name'] ?? '')) ?>">

                                <!-- rank number (determined by composite score) -->
                                <td><strong>#<?= $i + 1 ?></strong></td>

                                <!-- donor name and area -->
                                <td>
                                    <strong><?= e($d['donor_name'] ?? 'Unknown') ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?= e($d['area_name'] ?? '-') ?>
                                    </small>
                                </td>

                                <!-- blood type badge -->
                                <td>
                                    <span class="blood-type-badge blood-type-badge-sm">
                                        <?= e($d['blood_type']) ?>
                                    </span>
                                </td>

                                <!-- distance with colour-coded urgency-adaptive indicator -->
                                <td>
                                    <?php if ($isWithin): ?>
                                        <span class="text-success fw-bold">
                                            <i class="fas fa-check-circle me-1"></i>
                                            <?= e($distDisplay) ?>
                                        </span>
                                    <?php elseif ($isExtended): ?>
                                        <span class="text-warning">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <?= e($distDisplay) ?>
                                        </span>
                                    <?php elseif ($isFar): ?>
                                        <span class="text-danger">
                                            <i class="fas fa-map-marker-alt me-1"></i>
                                            <?= e($distDisplay) ?>
                                        </span>
                                    <?php else: ?>
                                        <?= e($distDisplay) ?>
                                    <?php endif; ?>
                                </td>

                                <!-- last donation date or first-timer -->
                                <td>
                                    <?php if ($d['last_donation_date']): ?>
                                        <?= e($d['last_donation_date']) ?>
                                    <?php else: ?>
                                        <span class="text-success">First-timer</span>
                                    <?php endif; ?>
                                </td>

                                <!-- booking status -->
                                <td>
                                    <?php if ($m['already_booked_this']): ?>
                                        <span class="badge bg-info">Already Booked</span>
                                    <?php elseif ($m['has_active_booking']): ?>
                                        <span class="badge bg-secondary">Has Active Booking</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>