<?php
// donor profile editor — blood type, phone, weight, location
// DOB is displayed but CANNOT be changed (set during registration only)
// weight below 45kg automatically marks donor as unavailable
$pageTitle = 'My Profile';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

$db  = Database::getInstance();
$uid = currentUserId();

// load current profile with location info
$stmt = $db->prepare(
    "SELECT dp.*, u.country_id, u.city_id, u.area_id, a.name AS area_name
     FROM donor_profiles dp
     JOIN users u ON dp.user_id = u.id
     LEFT JOIN areas a ON u.area_id = a.id
     WHERE dp.user_id = :u"
);
$stmt->execute([':u' => $uid]);
$profile = $stmt->fetch();
$errors = [];

// ---- delete account (self-delete) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account']) && verifyCSRF()) {
    logAction('account_self_deleted', "donor user_id=$uid");
    $db->prepare("DELETE FROM donor_profiles WHERE user_id = :u")->execute([':u' => $uid]);
    $db->prepare("DELETE FROM users WHERE id = :u")->execute([':u' => $uid]);
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// ---- save profile (NO date_of_birth — it's unchangeable) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $bt          = $_POST['blood_type'] ?? '';
        $phone       = trim($_POST['phone'] ?? '');
        $donorWeight = floatval($_POST['weight_kg'] ?? 0);
        $countryId   = (int)($_POST['country_id'] ?? 0);
        $cityId      = (int)($_POST['city_id'] ?? 0);
        $areaId      = (int)($_POST['area_id'] ?? 0);

        if (!validateBloodType($bt)) $errors[] = 'Invalid blood type.';
        if ($areaId < 1) $errors[] = 'Select an area.';

        // ===== WEIGHT VALIDATION =====
        // weight below 45kg saves but marks donor unavailable
        $markUnavailable = false;
        $weightWarning   = '';

        if ($donorWeight > 0 && $donorWeight < 45) {
            $markUnavailable = true;
            $weightWarning = 'Your weight is below the 45 kg minimum. You have been marked as unavailable for donation until your weight meets the requirement.';
        }

        if (empty($errors)) {
            // update donor profile — NOTE: date_of_birth is NOT updated
            $db->prepare(
                "UPDATE donor_profiles
                 SET blood_type = :bt, phone = :ph, weight_kg = :wt, area_id = :a
                 WHERE user_id = :u"
            )->execute([
                ':bt' => $bt,
                ':ph' => $phone,
                ':wt' => $donorWeight > 0 ? $donorWeight : null,
                ':a'  => $areaId,
                ':u'  => $uid
            ]);

            // update user location
            $db->prepare(
                "UPDATE users SET country_id = :co, city_id = :ci, area_id = :a WHERE id = :u"
            )->execute([
                ':co' => $countryId,
                ':ci' => $cityId,
                ':a'  => $areaId,
                ':u'  => $uid
            ]);

            $_SESSION['user']['country_id'] = $countryId;

            // mark unavailable if weight below threshold
            if ($markUnavailable) {
                $db->prepare(
                    "UPDATE donor_profiles SET is_available = 0 WHERE user_id = :u"
                )->execute([':u' => $uid]);
                logAction('donor_profile_update', 'weight below 45kg — marked unavailable');
                setFlash('warning', $weightWarning);
            } else {
                logAction('donor_profile_update', '');
                setFlash('success', 'Profile updated!');
            }

            redirect('/modules/donor/profile.php');
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4">
    <i class="fas fa-user-edit me-2 text-danger"></i>My Profile
</h2>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ===== PERSONAL INFO SUMMARY CARD ===== -->
<?php if ($profile): ?>
    <?php
    $dobDisplay    = $profile['date_of_birth'] ?? null;
    $ageDisplay    = null;
    if ($dobDisplay) {
        $ageDisplay = (new DateTime($dobDisplay))->diff(new DateTime())->y;
    }
    $weightVal     = $profile['weight_kg'] ?? null;
    $isUnderweight = ($weightVal && $weightVal < 45);
    ?>
    <div class="card mb-4 border-info">
        <div class="card-header bg-info bg-opacity-10">
            <h6 class="mb-0 text-info">
                <i class="fas fa-id-card me-2"></i>Personal Information
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <strong>Blood Type:</strong><br>
                    <span class="blood-type-badge"><?= e($profile['blood_type'] ?? 'N/A') ?></span>
                </div>
                <div class="col-md-3">
                    <strong>Date of Birth:</strong><br>
                    <?= e($dobDisplay ?? 'Not set') ?>
                    <?php if ($ageDisplay): ?>
                        <small class="text-muted">(<?= $ageDisplay ?> years old)</small>
                    <?php endif; ?>
                    <br><small class="text-muted fst-italic">
                        <i class="fas fa-lock me-1"></i>Cannot be changed
                    </small>
                </div>
                <div class="col-md-3">
                    <strong>Weight:</strong><br>
                    <?php if ($weightVal): ?>
                        <?= e($weightVal) ?> kg
                        <?php if ($isUnderweight): ?>
                            <br><span class="badge bg-danger">
                                <i class="fas fa-exclamation-triangle me-1"></i>Below 45 kg minimum
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        Not set
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <strong>Phone:</strong><br>
                    <?= e($profile['phone'] ?? 'Not set') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- underweight warning banner -->
    <?php if ($isUnderweight): ?>
        <div class="alert alert-warning">
            <i class="fas fa-weight me-2"></i>
            <strong>Weight below requirement.</strong>
            Your weight (<?= e($weightVal) ?> kg) is below the 45 kg minimum for blood donation.
            You are currently marked as <strong>unavailable</strong>.
            Update your weight to 45 kg or above to become eligible again.
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ===== EDIT FORM (DOB field is NOT editable) ===== -->
<div class="card mb-4">
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="row g-3">
                <!-- blood type -->
                <div class="col-md-4">
                    <label class="form-label fw-bold">Blood Type *</label>
                    <select class="form-select" name="blood_type" required>
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                            <option value="<?= $bt ?>"
                                <?= ($profile['blood_type'] ?? '') === $bt ? 'selected' : '' ?>>
                                <?= $bt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- phone -->
                <div class="col-md-4">
                    <label class="form-label fw-bold">Phone</label>
                    <input type="tel" class="form-control" name="phone"
                           value="<?= e($profile['phone'] ?? '') ?>">
                </div>

                <!-- weight with real-time warning -->
                <div class="col-md-4">
                    <label class="form-label fw-bold">Weight (kg)</label>
                    <input type="number" class="form-control" name="weight_kg"
                           id="weightInput"
                           value="<?= e($profile['weight_kg'] ?? '') ?>"
                           min="30" max="200" step="0.5"
                           oninput="checkWeight(this.value)">
                    <small id="weightHint" class="text-muted">Minimum 45 kg for eligibility</small>
                </div>
            </div>

            <!-- NOTE: No date_of_birth field — it cannot be changed -->

            <hr>
            <h6 class="fw-bold mb-3">
                <i class="fas fa-map-marker-alt me-2"></i>Location
            </h6>
            <?php
            $selectedCountryId = $profile['country_id'] ?? null;
            $selectedCityId    = $profile['city_id'] ?? null;
            $selectedAreaId    = $profile['area_id'] ?? null;
            $selectedAreaName  = $profile['area_name'] ?? null;
            $locationRequired  = true;
            require APP_ROOT . '/includes/location_picker.php';
            ?>

            <button type="submit" name="save_profile" class="btn btn-blood w-100 mt-4">
                <i class="fas fa-save me-2"></i>Save Profile
            </button>
        </form>
    </div>
</div>

<!-- ===== DELETE ACCOUNT ===== -->
<div class="card border-danger">
    <div class="card-header bg-danger bg-opacity-10">
        <h5 class="mb-0 text-danger">
            <i class="fas fa-trash-alt me-2"></i>Delete Account
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted">
            This will permanently delete your donor account and all associated data.
            This action cannot be undone.
        </p>
        <form method="POST" onsubmit="if(!this.querySelector('#confirmCheck').checked){alert('Please tick the confirmation checkbox.');return false;}return confirm('Are you sure? This cannot be undone.');">
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

<!-- weight warning script -->
<script>
function checkWeight(val) {
    var hint = document.getElementById('weightHint');
    var input = document.getElementById('weightInput');
    if (val && parseFloat(val) < 45) {
        hint.textContent = '⚠ Below 45 kg — you will be marked unavailable';
        hint.className = 'text-danger fw-bold';
        input.classList.add('border-danger');
    } else {
        hint.textContent = 'Minimum 45 kg for eligibility';
        hint.className = 'text-muted';
        input.classList.remove('border-danger');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    var w = document.getElementById('weightInput');
    if (w && w.value) checkWeight(w.value);
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>