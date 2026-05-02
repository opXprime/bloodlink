<?php
// Hospital profile editor — name, address, licence, verification document, location
// Also handles account self-deletion

$pageTitle = 'Hospital Profile';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('hospital');

$db  = Database::getInstance();
$uid = currentUserId();

// Load hospital profile with location data
$s = $db->prepare(
    "SELECT hp.*, u.country_id, u.city_id, u.area_id, a.name AS area_name
     FROM hospital_profiles hp
     JOIN users u ON hp.user_id = u.id
     LEFT JOIN areas a ON u.area_id = a.id
     WHERE hp.user_id = :u"
);
$s->execute([':u' => $uid]);
$hospital = $s->fetch();

$errors = [];

// ---- Self-delete account ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account']) && verifyCSRF()) {
    logAction('account_self_deleted', "hospital user_id=$uid");
    $db->prepare("DELETE FROM hospital_profiles WHERE user_id = :u")->execute([':u' => $uid]);
    $db->prepare("DELETE FROM users WHERE id = :u")->execute([':u' => $uid]);
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// ---- Save profile updates ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $hn   = trim($_POST['hospital_name'] ?? '');
        $ln   = trim($_POST['license_number'] ?? '');
        $ph   = trim($_POST['phone'] ?? '');
        $he   = trim($_POST['hospital_email'] ?? '');
        $addr = trim($_POST['address'] ?? '');
        $countryId = (int)($_POST['country_id'] ?? 0);
        $cityId    = (int)($_POST['city_id'] ?? 0);
        $areaId    = (int)($_POST['area_id'] ?? 0);

        if (strlen($hn) < 2) $errors[] = 'Hospital name required.';
        if (strlen($ln) < 2) $errors[] = 'License number required.';
        if (strlen($ph) < 5) $errors[] = 'Phone required.';

        // Handle verification document upload (PDF only, max 5MB)
        $docPath = $hospital['verification_doc'] ?? null;
        if (!empty($_FILES['verification_doc']['name'])) {
            $f = $_FILES['verification_doc'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload error.';
            } elseif ($f['size'] > MAX_UPLOAD_SIZE) {
                $errors[] = 'Max 5MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                if ($mime !== 'application/pdf') {
                    $errors[] = 'PDF only.';
                } else {
                    $fn = 'hospital_' . $uid . '_' . time() . '.pdf';
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $fn)) {
                        $docPath = $fn;
                    } else {
                        $errors[] = 'Save failed.';
                    }
                }
            }
        }

        if (empty($errors)) {
            // Update hospital profile
            $db->prepare(
                "UPDATE hospital_profiles
                 SET hospital_name = :hn, license_number = :ln, phone = :ph, email = :he,
                     address = :addr, area_id = :a, verification_doc = :doc
                 WHERE user_id = :u"
            )->execute([
                ':hn' => $hn, ':ln' => $ln, ':ph' => $ph, ':he' => $he,
                ':addr' => $addr, ':a' => $areaId, ':doc' => $docPath, ':u' => $uid
            ]);

            // Update user location
            $db->prepare("UPDATE users SET country_id = :co, city_id = :ci, area_id = :a WHERE id = :u")
               ->execute([':co' => $countryId, ':ci' => $cityId, ':a' => $areaId, ':u' => $uid]);

            $_SESSION['user']['country_id'] = $countryId;
            logAction('hospital_profile_update', '');
            setFlash('success', 'Profile updated!');
            redirect('/modules/hospital/profile.php');
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-hospital-alt me-2 text-danger"></i>Hospital Profile</h2>

<?php // Validation errors ?>
<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php // Verification status warning (unverified hospitals) ?>
<?php if ($hospital && $hospital['verification_status'] !== 'verified'): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i><strong>Verification Required</strong><br>
    To get verified, you must complete all of the following:
    <ul class="mb-0 mt-2">
        <li <?= !empty($hospital['address']) ? 'class="text-success"' : 'class="text-danger fw-bold"' ?>>
            <i class="fas fa-<?= !empty($hospital['address']) ? 'check' : 'times' ?> me-1"></i>Fill in your hospital address
        </li>
        <li <?= !empty($hospital['verification_doc']) ? 'class="text-success"' : 'class="text-danger fw-bold"' ?>>
            <i class="fas fa-<?= !empty($hospital['verification_doc']) ? 'check' : 'times' ?> me-1"></i>Upload hospital registration proof (PDF)
        </li>
        <li <?= !empty($hospital['license_number']) && strlen($hospital['license_number']) > 1 ? 'class="text-success"' : 'class="text-danger fw-bold"' ?>>
            <i class="fas fa-<?= !empty($hospital['license_number']) && strlen($hospital['license_number']) > 1 ? 'check' : 'times' ?> me-1"></i>Provide license number
        </li>
    </ul>
    <?php if ($hospital['verification_status'] === 'rejected' && $hospital['verification_notes']): ?>
        <hr><strong>Rejection notes:</strong> <?= e($hospital['verification_notes']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Profile edit form -->
<div class="card mb-4">
<div class="card-body">
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">Hospital Name *</label>
                <input type="text" class="form-control" name="hospital_name"
                       value="<?= e($hospital['hospital_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">License Number *</label>
                <input type="text" class="form-control" name="license_number"
                       value="<?= e($hospital['license_number'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Phone *</label>
                <input type="tel" class="form-control" name="phone"
                       value="<?= e($hospital['phone'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Contact Email</label>
                <input type="email" class="form-control" name="hospital_email"
                       value="<?= e($hospital['email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Full Address *</label>
                <input type="text" class="form-control" name="address"
                       value="<?= e($hospital['address'] ?? '') ?>" placeholder="Street, Ward, City">
            </div>
        </div>

        <!-- Verification document upload -->
        <div class="mt-3">
            <label class="form-label fw-bold">Registration Proof (PDF, max 5MB) *</label>
            <input type="file" class="form-control" name="verification_doc" id="verification_doc" accept="application/pdf">
            <?php if (!empty($hospital['verification_doc'])): ?>
            <div class="mt-2">
                <i class="fas fa-file-pdf text-danger me-1"></i>
                <a href="<?= APP_URL ?>/public/uploads/<?= e($hospital['verification_doc']) ?>" target="_blank">
                    <?= e($hospital['verification_doc']) ?>
                </a>
                <span class="badge bg-success">Uploaded</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Location picker -->
        <hr>
        <h6 class="fw-bold mb-3"><i class="fas fa-map-marker-alt me-2"></i>Location</h6>
        <?php
        $selectedCountryId = $hospital['country_id'] ?? null;
        $selectedCityId    = $hospital['city_id'] ?? null;
        $selectedAreaId    = $hospital['area_id'] ?? null;
        $selectedAreaName  = $hospital['area_name'] ?? null;
        $locationRequired  = true;
        require APP_ROOT . '/includes/location_picker.php';
        ?>

        <button type="submit" name="save_profile" class="btn btn-blood w-100 mt-4">
            <i class="fas fa-save me-2"></i>Save Profile
        </button>
    </form>
</div>
</div>

<!-- ========== DELETE ACCOUNT ========== -->
<div class="card border-danger">
    <div class="card-header bg-danger bg-opacity-10">
        <h5 class="mb-0 text-danger"><i class="fas fa-trash-alt me-2"></i>Delete Account</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">This will permanently delete your hospital account, all requests, bookings, and data. This action cannot be undone.</p>
        <form method="POST"
              onsubmit="if (!this.querySelector('#confirmCheck').checked) { alert('Please tick the confirmation checkbox.'); return false; } return confirm('Are you sure? This cannot be undone.');">
            <?= csrfField() ?>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="confirmCheck">
                <label class="form-check-label text-danger fw-bold" for="confirmCheck">
                    I confirm I want to permanently delete my account
                </label>
            </div>
            <button type="submit" name="delete_account" class="btn btn-danger">
                <i class="fas fa-trash me-1"></i>Delete My Account
            </button>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>