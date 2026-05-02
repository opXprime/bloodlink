<?php
// hospital dashboard — request stats, verification status
// stat cards are clickable — open requests leads to requests page,
// pending bookings leads to bookings page
$pageTitle = 'Hospital Dashboard';
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

// ---- count open requests ----
$s = $db->prepare(
    "SELECT COUNT(*) FROM blood_requests
     WHERE hospital_id = :h AND country_id = :c AND status = 'open'"
);
$s->execute([':h' => $hid, ':c' => $cid]);
$openReqs = $s->fetchColumn();

// ---- count pending bookings ----
$s = $db->prepare(
    "SELECT COUNT(*) FROM bookings
     WHERE hospital_id = :h AND country_id = :c AND status = 'pending'"
);
$s->execute([':h' => $hid, ':c' => $cid]);
$pendingBook = $s->fetchColumn();

// ---- total units received ----
$s = $db->prepare(
    "SELECT COALESCE(SUM(units), 0) FROM donation_history WHERE hospital_id = :h"
);
$s->execute([':h' => $hid]);
$totalRec = $s->fetchColumn();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4">
    <i class="fas fa-hospital me-2 text-danger"></i>Hospital Dashboard
</h2>

<!-- ===== VERIFICATION STATUS BANNER ===== -->
<?php if ($hospital): ?>
    <div class="alert alert-<?= $hospital['verification_status'] === 'verified'
        ? 'success'
        : ($hospital['verification_status'] === 'rejected' ? 'danger' : 'warning') ?>">
        <strong>Verification: <?= e(ucfirst($hospital['verification_status'])) ?></strong>
        <?php if ($hospital['verification_status'] === 'pending'): ?>
            — Under review. <a href="profile.php">Complete your profile</a> to speed up verification.
        <?php elseif ($hospital['verification_status'] === 'rejected'): ?>
            — <?= e($hospital['verification_notes'] ?? 'Contact admin') ?>
            <a href="profile.php">Update profile</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===== CLICKABLE STAT CARDS ===== -->
<div class="row g-4 mb-4">
    <!-- open requests — clicks through to requests page (shows only list, not form) -->
    <div class="col-6 col-md-4">
        <a href="requests.php" class="text-decoration-none">
            <div class="card h-100 <?= $openReqs > 0 ? 'border-danger' : '' ?>"
                 style="cursor:pointer; transition: transform 0.15s;"
                 onmouseover="this.style.transform='translateY(-3px)'"
                 onmouseout="this.style.transform='none'">
                <div class="card-body dash-stat">
                    <i class="fas fa-clipboard-list text-danger fa-2x"></i>
                    <div class="number"><?= $openReqs ?></div>
                    <div class="label">Open Requests</div>
                    <small class="text-muted">Click to view</small>
                </div>
            </div>
        </a>
    </div>

    <!-- pending bookings — clicks through to bookings page -->
    <div class="col-6 col-md-4">
        <a href="<?= APP_URL ?>/modules/booking/hospital_bookings.php" class="text-decoration-none">
            <div class="card h-100 <?= $pendingBook > 0 ? 'border-warning' : '' ?>"
                 style="cursor:pointer; transition: transform 0.15s;"
                 onmouseover="this.style.transform='translateY(-3px)'"
                 onmouseout="this.style.transform='none'">
                <div class="card-body dash-stat">
                    <i class="fas fa-clock text-danger fa-2x"></i>
                    <div class="number"><?= $pendingBook ?></div>
                    <div class="label">Pending Bookings</div>
                    <small class="text-muted">Click to manage</small>
                </div>
            </div>
        </a>
    </div>

    <!-- units received — not clickable (just a stat) -->
    <div class="col-6 col-md-4">
        <div class="card h-100">
            <div class="card-body dash-stat">
                <i class="fas fa-tint text-danger fa-2x"></i>
                <div class="number"><?= $totalRec ?></div>
                <div class="label">Units Received</div>
            </div>
        </div>
    </div>
</div>

<!-- ===== QUICK NAV CARDS ===== -->
<div class="row g-4 mb-4">
    <div class="col-md-4 col-6">
        <a href="profile.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-hospital-alt fa-2x text-danger mb-2"></i>
                <h6><?= $hospital['verification_status'] === 'verified' ? 'Profile' : 'Complete Verification' ?></h6>
            </div>
        </a>
    </div>

    <div class="col-md-4 col-6">
        <a href="requests.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-plus-circle fa-2x text-danger mb-2"></i>
                <h6>Requests</h6>
            </div>
        </a>
    </div>

    <div class="col-md-4 col-6">
        <a href="<?= APP_URL ?>/modules/booking/hospital_bookings.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-calendar-check fa-2x text-danger mb-2"></i>
                <h6>Bookings</h6>
            </div>
        </a>
    </div>

    <div class="col-md-4 col-6">
        <a href="campaigns.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-bullhorn fa-2x text-danger mb-2"></i>
                <h6>Campaigns</h6>
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
    <div class="col-md-4 col-6">
        <a href="<?= APP_URL ?>/contact.php" class="card text-decoration-none h-100 position-relative">
            <div class="card-body text-center p-4">
                <i class="fas fa-envelope fa-2x text-danger mb-2"></i>
                <h6>Contact Website</h6>
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

<?php require_once APP_ROOT . '/includes/footer.php'; ?>