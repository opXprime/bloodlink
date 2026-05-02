<?php
// Donor browses open blood requests, filtered by compatibility and distance

$pageTitle = 'Find Blood Requests';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

// ---- Load donor profile with location data ----
$s = $db->prepare(
    "SELECT dp.*, a.centroid_lat, a.centroid_lon, a.name AS area_name, a.city_id AS donor_city_id
     FROM donor_profiles dp
     LEFT JOIN areas a ON dp.area_id = a.id
     WHERE dp.user_id = :u"
);
$s->execute([':u' => $uid]);
$donor = $s->fetch();

if (!$donor) {
    setFlash('warning', 'Complete your profile first.');
    redirect('/modules/donor/profile.php');
}

$donorBlood  = $donor['blood_type'];
$dLat        = $donor['centroid_lat'];
$dLon        = $donor['centroid_lon'];
$donorAreaId = $donor['area_id'];

// Determine which blood types this donor can donate to
$canDonateTo = [];
foreach (BLOOD_COMPATIBILITY as $recip => $donors) {
    if (in_array($donorBlood, $donors)) $canDonateTo[] = $recip;
}

// Check if donor already has an active booking
$s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE donor_id = :d AND status IN ('pending','confirmed')");
$s->execute([':d' => $donor['id']]);
$hasActive = (int)$s->fetchColumn() > 0;

// ---- Build query for open requests ----
$filter    = $_GET['filter'] ?? 'compatible';
$urgFilter = $_GET['urgency'] ?? '';

$sql = "SELECT br.*, hp.hospital_name, hp.address AS hospital_address, hp.verification_status,
            ra.centroid_lat AS req_lat, ra.centroid_lon AS req_lon, ra.name AS req_area, ra.id AS req_area_id
        FROM blood_requests br
        JOIN hospital_profiles hp ON br.hospital_id = hp.id
        LEFT JOIN areas ra ON br.area_id = ra.id
        WHERE br.country_id = :cid AND br.status = 'open'";
$params = [':cid' => $cid];

// Filter by compatible blood types (unless "all" selected)
if ($filter !== 'all' && $canDonateTo) {
    $phs = [];
    foreach ($canDonateTo as $i => $bt) {
        $k = ':bt' . $i;
        $phs[] = $k;
        $params[$k] = $bt;
    }
    $sql .= " AND br.blood_type IN (" . implode(',', $phs) . ")";
}

// Filter by urgency level if selected
if ($urgFilter && in_array($urgFilter, ['low', 'medium', 'high', 'critical'])) {
    $sql .= " AND br.urgency = :urg";
    $params[':urg'] = $urgFilter;
}

$sql .= " ORDER BY FIELD(br.urgency, 'critical', 'high', 'medium', 'low'), br.created_at DESC";
$s = $db->prepare($sql);
$s->execute($params);
$rawRequests = $s->fetchAll();

// ---- Calculate distance and check booking status for each request ----
foreach ($rawRequests as &$r) {
    // Haversine distance from donor to request area
    $r['distance'] = null;
    if ($dLat && $dLon && $r['req_lat'] && $r['req_lon']) {
        $r['distance'] = haversineDistance((float)$dLat, (float)$dLon, (float)$r['req_lat'], (float)$r['req_lon']);
    }
    $r['display_distance'] = formatDistance($r['distance'], $donorAreaId, $r['req_area_id'] ?? null);
    $r['is_compatible'] = in_array($r['blood_type'], $canDonateTo);

    // Check if donor already booked this request
    $bs = $db->prepare(
        "SELECT COUNT(*) FROM bookings
         WHERE blood_request_id = :r AND donor_id = :d AND status NOT IN ('cancelled','rejected')"
    );
    $bs->execute([':r' => $r['id'], ':d' => $donor['id']]);
    $r['already_booked'] = (int)$bs->fetchColumn() > 0;
}
unset($r);

// ---- Sort: urgency first, then distance ----
usort($rawRequests, function($a, $b) {
    $uo = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    $uA = $uo[$a['urgency']] ?? 4;
    $uB = $uo[$b['urgency']] ?? 4;
    if ($uA !== $uB) return $uA <=> $uB;

    if ($a['distance'] === null && $b['distance'] === null) return 0;
    if ($a['distance'] === null) return 1;
    if ($b['distance'] === null) return -1;
    return $a['distance'] <=> $b['distance'];
});

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-search me-2 text-danger"></i>Find Blood Requests</h2>

<!-- Donor info + filters -->
<div class="card mb-4">
<div class="card-body">
    <div class="row align-items-center">
        <div class="col-md-3">
            <strong>Your Blood:</strong>
            <span class="blood-type-badge blood-type-badge-sm"><?= e($donorBlood) ?></span>
        </div>
        <div class="col-md-3">
            <strong>Can Donate To:</strong> <?= e(implode(', ', $canDonateTo)) ?>
        </div>
        <div class="col-md-2">
            <strong>Area:</strong> <?= e($donor['area_name'] ?? '-') ?>
        </div>
        <div class="col-md-4">
            <?php if ($hasActive): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Active booking exists</span>
            <?php endif; ?>
            <?php if (!$donor['is_eligible']): ?>
                <span class="badge bg-danger">
                    <i class="fas fa-ban me-1"></i>Ineligible
                    <?php if ($donor['next_eligible_date']): ?> until <?= e($donor['next_eligible_date']) ?><?php endif; ?>
                </span>
            <?php endif; ?>

            <!-- Filter form -->
            <form method="GET" class="d-flex gap-2 mt-1">
                <select class="form-select form-select-sm" name="filter">
                    <option value="compatible" <?= $filter === 'compatible' ? 'selected' : '' ?>>Compatible Only</option>
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Types</option>
                </select>
                <select class="form-select form-select-sm" name="urgency">
                    <option value="">All Urgencies</option>
                    <?php foreach (['critical', 'high', 'medium', 'low'] as $u): ?>
                    <option value="<?= $u ?>" <?= $urgFilter === $u ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-blood">Filter</button>
            </form>
        </div>
    </div>
</div>
</div>

<!-- Request listing -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">Open Requests (<?= count($rawRequests) ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($rawRequests)): ?>
            <p class="text-muted">No matching requests in your country.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Hospital</th>
                        <th>Area</th>
                        <th>Blood</th>
                        <th>Units Left</th>
                        <th>Urgency</th>
                        <th>Distance</th>
                        <th>Deadline</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rawRequests as $r): ?>
                    <tr class="<?= !$r['is_compatible'] ? 'table-light' : '' ?>">
                        <!-- Hospital name + verified badge -->
                        <td>
                            <strong><?= e($r['hospital_name']) ?></strong>
                            <?php if ($r['verification_status'] === 'verified'): ?>
                                <i class="fas fa-check-circle text-success ms-1" title="Verified Hospital"></i>
                            <?php endif; ?>
                            <?php if ($r['hospital_address']): ?>
                                <br><small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= e($r['hospital_address']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['req_area'] ?? '-') ?></td>
                        <td><span class="blood-type-badge blood-type-badge-sm"><?= e($r['blood_type']) ?></span></td>
                        <td><?= e($r['units_needed'] - $r['units_fulfilled']) ?>/<?= e($r['units_needed']) ?></td>
                        <td><span class="badge badge-urgency-<?= e($r['urgency']) ?>"><?= e(ucfirst($r['urgency'])) ?></span></td>
                        <td><?= e($r['display_distance']) ?></td>
                        <td><?= e($r['deadline'] ?? '-') ?></td>

                        <!-- Action column — shows appropriate button based on eligibility -->
                        <td>
                            <?php if (!$r['is_compatible']): ?>
                                <span class="text-muted small">Incompatible</span>
                            <?php elseif ($r['already_booked']): ?>
                                <span class="badge bg-info">Booked</span>
                            <?php elseif ($hasActive): ?>
                                <span class="badge bg-secondary" title="Complete current booking first">Busy</span>
                            <?php elseif (!$donor['is_eligible']): ?>
                                <span class="badge bg-warning text-dark">Ineligible</span>
                            <?php else: ?>
                                <a href="<?= APP_URL ?>/modules/booking/book.php?request_id=<?= $r['id'] ?>" class="btn btn-sm btn-blood">
                                    <i class="fas fa-hand-holding-heart me-1"></i>Book
                                </a>
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

<?php require_once APP_ROOT . '/includes/footer.php'; ?>