<?php
// registration — handles both donor and hospital account creation
// includes server-side age (18+) and weight (45kg+) validation
$pageTitle = 'Register';
require_once __DIR__ . '/../../includes/bootstrap.php';
if (isLoggedIn()) redirect('/index.php');

$db     = Database::getInstance();
$errors = [];
$old    = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $role      = $_POST['role'] ?? '';
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';
        $secQ      = trim($_POST['security_question'] ?? '');
        $secA      = trim($_POST['security_answer'] ?? '');
        $countryId = (int)($_POST['country_id'] ?? 0);
        $cityId    = (int)($_POST['city_id'] ?? 0);
        $areaId    = (int)($_POST['area_id'] ?? 0);
        $terms     = isset($_POST['agree_terms']);

        // ---- basic validation ----
        if (!in_array($role, ['donor', 'hospital'])) $errors[] = 'Select a valid role.';
        if (!validateEmail($email)) {
            $errors[] = 'Invalid email format.';
        } elseif (substr_compare(strtolower($email), '@gmail.com', -10) !== 0) {
            $errors[] = 'Email must be a Gmail address, e.g. eg@gmail.com.';
        }
        $pwErrors = validatePassword($password);
        if ($pwErrors) $errors = array_merge($errors, $pwErrors);
        if ($password !== $confirmPw) $errors[] = 'Passwords do not match.';
        if (strlen($secQ) < 3)       $errors[] = 'Security question is required.';
        if (strlen($secA) < 1)       $errors[] = 'Security answer is required.';
        if ($countryId < 1)          $errors[] = 'Select a country.';
        if ($cityId < 1)             $errors[] = 'Select a city.';
        if ($areaId < 1)             $errors[] = 'Select an area from the suggestions.';
        if (!$terms)                 $errors[] = 'You must agree to the Terms & Conditions.';

        // ---- donor validation ----
        if ($role === 'donor') {
            $name        = trim($_POST['name'] ?? '');
            $donorPhone  = trim($_POST['donor_phone'] ?? '');
            $donorBlood  = $_POST['donor_blood_type'] ?? '';
            $donorDob    = trim($_POST['donor_dob'] ?? '');
            $donorWeight = floatval($_POST['donor_weight'] ?? 0);

            if (strlen($name) < 2) $errors[] = 'Full name is required.';
            if (!preg_match('/^[0-9]{10}$/', $donorPhone)) $errors[] = 'Phone number must be exactly 10 digits.';
            if (!validateBloodType($donorBlood)) $errors[] = 'Select a blood type.';

            // age check
            if (empty($donorDob)) {
                $errors[] = 'Date of birth is required.';
            } else {
                try {
                    $dob = new DateTime($donorDob);
                    $now = new DateTime('today');
                    $age = (int)$now->diff($dob)->y;

                    if ($dob > $now) {
                        $errors[] = 'Date of birth cannot be in the future.';
                    } elseif ($age < 18) {
                        $errors[] = 'You must be at least 18 years old to register. You are ' . $age . ' years old.';
                    } elseif ($age > 65) {
                        $errors[] = 'Donors must be 65 years old or younger.';
                    }
                } catch (Exception $ex) {
                    $errors[] = 'Invalid date of birth format.';
                }
            }

            // weight check
            if ($donorWeight <= 0) {
                $errors[] = 'Weight is required.';
            } elseif ($donorWeight < 45) {
                $errors[] = 'You must weigh at least 45 kg to register. You entered ' . $donorWeight . ' kg.';
            }

            // unique phone check
            if (empty($errors)) {
                $normPhone = preg_replace('/[\s\-]/', '', $donorPhone);
                $s = $db->prepare(
                    "SELECT id FROM donor_profiles
                     WHERE REPLACE(REPLACE(phone,' ',''),'-','') = :p"
                );
                $s->execute([':p' => $normPhone]);
                if ($s->fetch()) $errors[] = 'This phone number is already registered by another donor.';
            }
        }

        // ---- hospital validation ----
        if ($role === 'hospital') {
            $hospitalName = trim($_POST['hospital_name'] ?? '');
            $license      = trim($_POST['license_number'] ?? '');
            $hPhone       = trim($_POST['hospital_phone'] ?? '');
            $name         = $hospitalName;

            if (strlen($hospitalName) < 2) $errors[] = 'Hospital name is required.';
            if (!preg_match('/^[0-9]{10}$/', $hPhone)) $errors[] = 'Phone number must be exactly 10 digits.';

            if (empty($errors)) {
                $normPhone = preg_replace('/[\s\-]/', '', $hPhone);
                $s = $db->prepare(
                    "SELECT id FROM hospital_profiles
                     WHERE REPLACE(REPLACE(phone,' ',''),'-','') = :p"
                );
                $s->execute([':p' => $normPhone]);
                if ($s->fetch()) $errors[] = 'This phone number is already registered by another hospital.';
            }

            // unique license number check
            if (empty($errors)) {
                $s = $db->prepare(
                    "SELECT id FROM hospital_profiles
                     WHERE license_number = :ln"
                );
                $s->execute([':ln' => $license]);
                if ($s->fetch()) $errors[] = 'This license number is already registered by another hospital.';
            }
        }

        // duplicate email
        if (empty($errors)) {
            $s = $db->prepare("SELECT id FROM users WHERE email = :e");
            $s->execute([':e' => $email]);
            if ($s->fetch()) $errors[] = 'This email is already registered.';
        }

        // admin-blocked email
        if (empty($errors)) {
            $delCheck = $db->prepare(
                "SELECT id FROM system_logs
                 WHERE action = 'account_deleted_by_admin' AND details LIKE :e LIMIT 1"
            );
            $delCheck->execute([':e' => $email . '|%']);
            if ($delCheck->fetch()) $errors[] = 'This email has been blocked.';
        }

        // location hierarchy
        if (empty($errors) && $areaId > 0) {
            $s = $db->prepare(
                "SELECT a.id FROM areas a
                 JOIN cities c ON a.city_id = c.id
                 WHERE a.id = :a AND c.id = :ci AND c.country_id = :co AND a.is_active = 1"
            );
            $s->execute([':a' => $areaId, ':ci' => $cityId, ':co' => $countryId]);
            if (!$s->fetch()) $errors[] = 'Invalid location. Select an area from suggestions.';
        }

        // insert into database
        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $hashedPw   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $hashedSecA = password_hash(strtolower($secA), PASSWORD_BCRYPT, ['cost' => 12]);

                $s = $db->prepare(
                    "INSERT INTO users (name, email, password_hash, security_question,
                     security_answer_hash, role, country_id, city_id, area_id)
                     VALUES (:n, :e, :p, :sq, :sa, :r, :co, :ci, :ar)"
                );
                $s->execute([
                    ':n'  => $name,       ':e'  => $email,
                    ':p'  => $hashedPw,   ':sq' => $secQ,
                    ':sa' => $hashedSecA, ':r'  => $role,
                    ':co' => $countryId,  ':ci' => $cityId,
                    ':ar' => $areaId
                ]);
                $userId = $db->lastInsertId();

                if ($role === 'donor') {
                    $db->prepare(
                        "INSERT INTO donor_profiles (user_id, blood_type, phone, date_of_birth, weight_kg, area_id)
                         VALUES (:u, :bt, :ph, :dob, :wt, :a)"
                    )->execute([
                        ':u'   => $userId,     ':bt' => $donorBlood,
                        ':ph'  => $donorPhone, ':dob' => $donorDob,
                        ':wt'  => $donorWeight, ':a' => $areaId
                    ]);
                } elseif ($role === 'hospital') {
                    $db->prepare(
                        "INSERT INTO hospital_profiles (user_id, hospital_name, license_number, phone, area_id)
                         VALUES (:u, :hn, :ln, :ph, :a)"
                    )->execute([
                        ':u'  => $userId,    ':hn' => $hospitalName,
                        ':ln' => $license,   ':ph' => $hPhone,
                        ':a'  => $areaId
                    ]);
                }

                $db->commit();
                logAction('user_registered', "$email as $role");
                setFlash('success', 'Registration successful! Please log in.');
                redirect('/modules/auth/login.php');
            } catch (PDOException $ex) {
                $db->rollBack();
                $errors[] = 'Registration failed. Try again.';
            }
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<!-- transparent eye icon overlay style -->
<style>
/* hide browser built-in password reveal icon */
input[type="password"]::-ms-reveal,
input[type="password"]::-webkit-credentials-auto-fill-button { display: none; }
input::-ms-reveal { display: none; }
.pw-wrapper {
    position: relative;
}
.pw-wrapper input[type="password"],
.pw-wrapper input[type="text"] {
    padding-right: 2.5rem;
}
.pw-toggle {
    position: absolute;
    top: 50%;
    right: 0.75rem;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    padding: 0;
    color: #6c757d;
    cursor: pointer;
    z-index: 10;
    transition: color 0.15s;
}
.pw-toggle:hover,
.pw-toggle:focus {
    color: #c0392b;
    outline: none;
}
.field-error-msg {
    font-size: 0.875rem;
    color: #dc3545;
    margin-top: 0.25rem;
    display: none;
}
.field-error-msg.show {
    display: block;
}
input.is-invalid {
    border-color: #dc3545 !important;
}
</style>

<div class="auth-container" style="max-width:620px">
<div class="card auth-card shadow">
    <div class="card-header">
        <h4 class="mb-0"><i class="fas fa-user-plus me-2"></i>Create Account</h4>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= e($e) ?></li>
                    <?php endforeach; ?>
                </ul>
                <hr class="my-2">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Please re-enter your password and security answer — browsers do not preserve these fields for security.
                </small>
            </div>
        <?php endif; ?>

        <form method="POST" id="regForm">
            <?= csrfField() ?>

            <!-- ===== ROLE SELECTION ===== -->
            <div class="mb-3">
                <label class="form-label fw-bold">Register As *</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="role" id="role_donor"
                               value="donor"
                               <?= ($old['role'] ?? '') === 'donor' || !isset($old['role']) ? 'checked' : '' ?>
                               onchange="toggleRole()">
                        <label class="form-check-label" for="role_donor">Donor</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="role" id="role_hospital"
                               value="hospital"
                               <?= ($old['role'] ?? '') === 'hospital' ? 'checked' : '' ?>
                               onchange="toggleRole()">
                        <label class="form-check-label" for="role_hospital">Hospital</label>
                    </div>
                </div>
            </div>

            <!-- ===== DONOR FIELDS ===== -->
            <div id="donorFields">
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" class="form-control" name="name"
                           value="<?= e($old['name'] ?? '') ?>">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Phone Number *</label>
                        <input type="tel" class="form-control" name="donor_phone" id="donor_phone"
                               value="<?= e($old['donor_phone'] ?? '') ?>"
                               placeholder="9800000000"
                               maxlength="10" inputmode="numeric" required oninput="validatePhone('donor_phone')">
                        <div id="donor_phone-error" class="field-error-msg">
                            <i class="fas fa-times-circle me-1"></i>Phone number must be 10 digits.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Blood Type *</label>
                        <select class="form-select" name="donor_blood_type" required>
                            <option value="" disabled <?= empty($old['donor_blood_type']) ? 'selected' : '' ?>>
                                -- Select Blood Type --
                            </option>
                            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                                <option value="<?= $bt ?>"
                                    <?= ($old['donor_blood_type'] ?? '') === $bt ? 'selected' : '' ?>>
                                    <?= $bt ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Date of Birth *</label>
                        <input type="date" class="form-control" name="donor_dob" id="donor_dob"
                               value="<?= e($old['donor_dob'] ?? '') ?>"
                               max="<?= date('Y-m-d', strtotime('-18 years')) ?>" required
                               oninput="validateDob()" onchange="validateDob()">
                        <div id="donor_dob-error" class="field-error-msg">
                            <i class="fas fa-times-circle me-1"></i>Date of birth is required and must be 18-65 years old.
                        </div>
                        <small class="text-muted">
                            <i class="fas fa-lock me-1"></i>Must be 18 or older — this cannot be changed later
                        </small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Weight (kg) *</label>
                        <input type="number" class="form-control" name="donor_weight" id="donor_weight"
                               value="<?= e($old['donor_weight'] ?? '') ?>"
                               min="45" max="200" step="0.5" placeholder="e.g. 55" required
                               oninput="validateWeight()">
                        <div id="donor_weight-error" class="field-error-msg">
                            <i class="fas fa-times-circle me-1"></i>Weight must be at least 45 kg.
                        </div>
                        <small class="text-muted">Minimum 45 kg to be eligible</small>
                    </div>
                </div>
            </div>

            <!-- ===== HOSPITAL FIELDS ===== -->
            <div id="hospitalFields" style="display:none">
                <div class="mb-3">
                    <label class="form-label">Hospital Name *</label>
                    <input type="text" class="form-control" name="hospital_name" id="hospital_name"
                           value="<?= e($old['hospital_name'] ?? '') ?>" required oninput="validateHospitalName()">
                    <div id="hospital_name-error" class="field-error-msg">
                        <i class="fas fa-times-circle me-1"></i>Hospital name is required.
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">License Number *</label>
                        <input type="text" class="form-control" name="license_number" id="license_number"
                               value="<?= e($old['license_number'] ?? '') ?>"
                               placeholder="Enter your unique license number (hospital)"
                               required oninput="validateLicenseNumber()">
                        <div id="license_number-error" class="field-error-msg">
                            <i class="fas fa-times-circle me-1"></i>License number is required.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Phone *</label>
                        <input type="tel" class="form-control" name="hospital_phone" id="hospital_phone"
                               value="<?= e($old['hospital_phone'] ?? '') ?>"
                               placeholder="9800000000"
                               maxlength="10" inputmode="numeric" required oninput="validatePhone('hospital_phone')">
                        <div id="hospital_phone-error" class="field-error-msg">
                            <i class="fas fa-times-circle me-1"></i>Phone number must be 10 digits.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== SHARED FIELDS ===== -->
            <div class="mb-3">
                <label class="form-label">Email Address *</label>
                <input type="email" class="form-control" name="email" id="email"
                       value="<?= e($old['email'] ?? '') ?>" placeholder="eg@gmail.com" required
                       oninput="validateEmail()">
                <div id="email-error" class="field-error-msg">
                    <i class="fas fa-times-circle me-1"></i>Email must be a valid Gmail address like eg@gmail.com.
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Password *</label>
                    <div class="pw-wrapper">
                        <input type="password" class="form-control" name="password" id="password"
                               required minlength="8" oninput="checkPwStrength(this.value)">
                        <button type="button" class="pw-toggle"
                                onclick="togglePw('password', this)"
                                aria-label="Show/hide password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="pw-strength" class="mt-1" style="height:4px;border-radius:2px;background:#eee">
                        <div id="pw-bar" style="height:100%;width:0;border-radius:2px;transition:all .3s"></div>
                    </div>
                    <small class="text-muted" id="pw-hint">Min 8 chars, 1 uppercase, 1 lowercase</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Password *</label>
                    <div class="pw-wrapper">
                        <input type="password" class="form-control" name="confirm_password"
                               id="confirm_password" required oninput="validatePasswordMatch()">
                        <button type="button" class="pw-toggle"
                                onclick="togglePw('confirm_password', this)"
                                aria-label="Show/hide password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="pw-match-error" class="field-error-msg">
                        <i class="fas fa-times-circle me-1"></i>Passwords do not match.
                    </div>
                </div>
            </div>

            <!-- ===== SECURITY QUESTION ===== -->
            <hr>
            <h6 class="fw-bold mb-3">
                <i class="fas fa-shield-alt me-2"></i>Security Question
            </h6>
            <div class="mb-3">
                <label class="form-label">Your Question *</label>
                <input type="text" class="form-control" name="security_question"
                       placeholder="e.g., What is your favourite game?"
                       value="<?= e($old['security_question'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Your Answer *</label>
                <input type="text" class="form-control" name="security_answer"
                       placeholder="Case-insensitive" required>
            </div>

            <!-- ===== LOCATION ===== -->
            <hr>
            <h6 class="fw-bold mb-3">
                <i class="fas fa-map-marker-alt me-2"></i>Location
            </h6>
            <?php
            $selectedCountryId = $old['country_id'] ?? null;
            $selectedCityId    = $old['city_id'] ?? null;
            $selectedAreaId    = $old['area_id'] ?? null;
            $selectedAreaName  = $old['area_name'] ?? null;
            $locationRequired  = true;
            require APP_ROOT . '/includes/location_picker.php';
            ?>

            <!-- ===== TERMS ===== -->
            <div class="form-check mt-4 mb-3">
                <input class="form-check-input" type="checkbox" name="agree_terms"
                       id="agree_terms" <?= isset($old['agree_terms']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="agree_terms">
                    I agree to the <a href="<?= APP_URL ?>/terms.php" target="_blank">Terms & Conditions</a> *
                </label>
            </div>

            <button type="submit" class="btn btn-blood w-100 py-2 fw-bold">
                <i class="fas fa-user-plus me-2"></i>Create Account
            </button>

            <div class="text-center mt-3">
                <p class="mb-0">
                    Already have an account?
                    <a href="login.php" class="auth-link">
                        <i class="fas fa-sign-in-alt me-1"></i>Login here
                    </a>
                </p>
            </div>
        </form>
    </div>
</div>
</div>

<script>
// show/hide password toggle
// password strength meter — evaluates 5 criteria and shows colour-coded bar
function checkPwStrength(pw) {
    var bar = document.getElementById('pw-bar');
    var hint = document.getElementById('pw-hint');
    if (!bar || !hint) return;

    var score = 0;
    if (pw.length >= 8)          score++;  // minimum length
    if (pw.length >= 12)         score++;  // good length
    if (/[A-Z]/.test(pw))        score++;  // has uppercase
    if (/[a-z]/.test(pw))        score++;  // has lowercase
    if (/[0-9!@#$%^&*]/.test(pw)) score++; // has number or symbol

    var width = (score / 5) * 100;
    var color = '#e74c3c';  // red
    var text  = 'Weak';

    if (score >= 4) {
        color = '#27ae60'; text = 'Strong';
    } else if (score >= 3) {
        color = '#f39c12'; text = 'Medium';
    } else if (score >= 2) {
        color = '#e67e22'; text = 'Fair';
    }

    bar.style.width = width + '%';
    bar.style.background = color;

    if (pw.length === 0) {
        bar.style.width = '0';
        hint.textContent = 'Min 8 chars, 1 uppercase, 1 lowercase';
        hint.className = 'text-muted';
    } else {
        hint.textContent = text;
        hint.style.color = color;
    }
}

function togglePw(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function setFieldError(fieldId, message, invalid) {
    var field = document.getElementById(fieldId);
    var errorMsg = document.getElementById(fieldId + '-error');
    if (!field || !errorMsg) return;
    if (invalid) {
        errorMsg.innerHTML = '<i class="fas fa-times-circle me-1"></i>' + message;
        errorMsg.classList.add('show');
        field.classList.add('is-invalid');
    } else {
        errorMsg.classList.remove('show');
        field.classList.remove('is-invalid');
    }
}

function validateEmail() {
    var field = document.getElementById('email');
    if (!field) return;
    var value = field.value.trim();
    var valid = /^[^\s@]+@gmail\.com$/i.test(value);
    setFieldError('email', 'Email must be a valid Gmail address like eg@gmail.com.', value.length > 0 && !valid);
}

function validatePhone(fieldId) {
    var field = document.getElementById(fieldId);
    if (!field) return;
    var value = field.value.replace(/[^0-9]/g, '');
    setFieldError(fieldId, 'Phone number must be 10 digits.', value.length > 0 && value.length !== 10);
}

function validatePasswordMatch() {
    var pw = document.getElementById('password');
    var confirmPw = document.getElementById('confirm_password');
    var errorMsg = document.getElementById('pw-match-error');
    if (!pw || !confirmPw || !errorMsg) return;
    if (!confirmPw.value) {
        errorMsg.classList.remove('show');
        confirmPw.classList.remove('is-invalid');
        return;
    }
    if (pw.value !== confirmPw.value) {
        errorMsg.classList.add('show');
        confirmPw.classList.add('is-invalid');
    } else {
        errorMsg.classList.remove('show');
        confirmPw.classList.remove('is-invalid');
    }
}

function validateDob() {
    var field = document.getElementById('donor_dob');
    if (!field) return;
    var value = field.value;
    if (!value) {
        setFieldError('donor_dob', 'Date of birth is required and must be 18-65 years old.', false);
        return;
    }
    var dob = new Date(value + 'T00:00:00');
    if (isNaN(dob.getTime())) {
        setFieldError('donor_dob', 'Date of birth is required and must be 18-65 years old.', true);
        return;
    }
    var today = new Date();
    var age = today.getFullYear() - dob.getFullYear();
    var m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    setFieldError('donor_dob', 'Date of birth is required and must be 18-65 years old.', age < 18 || age > 65);
}

function validateWeight() {
    var field = document.getElementById('donor_weight');
    if (!field) return;
    var value = parseFloat(field.value);
    if (!field.value) {
        setFieldError('donor_weight', 'Weight is required.', false);
        return;
    }
    setFieldError('donor_weight', 'Weight must be at least 45 kg.', !isNaN(value) && value < 45);
}

function validateLicenseNumber() {
    var field = document.getElementById('license_number');
    if (!field) return;
    setFieldError('license_number', 'License number is required.', field.value.trim().length === 0);
}

function validateHospitalName() {
    var field = document.getElementById('hospital_name');
    if (!field) return;
    setFieldError('hospital_name', 'Hospital name is required.', field.value.trim().length > 0 && field.value.trim().length < 2);
}

function validateAllFields() {
    validateEmail();
    validatePhone('donor_phone');
    validatePhone('hospital_phone');
    validatePasswordMatch();
    validateDob();
    validateWeight();
    validateLicenseNumber();
    validateHospitalName();
}

// show/hide donor vs hospital fields
function toggleRole() {
    var isHosp = document.getElementById('role_hospital').checked;
    var df = document.getElementById('donorFields');
    var hf = document.getElementById('hospitalFields');

    df.style.display = isHosp ? 'none' : 'block';
    hf.style.display = isHosp ? 'block' : 'none';

    df.querySelectorAll('input, select').forEach(function(el) {
        if (!isHosp) el.setAttribute('required', 'required');
        else el.removeAttribute('required');
    });
    hf.querySelectorAll('input[name=hospital_name], input[name=license_number], input[name=hospital_phone]')
        .forEach(function(el) {
            if (isHosp) el.setAttribute('required', 'required');
            else el.removeAttribute('required');
        });
}

document.addEventListener('DOMContentLoaded', function() {
    var pw = document.getElementById('password');
    if (pw) {
        pw.addEventListener('input', validatePasswordMatch);
    }
    var regForm = document.getElementById('regForm');
    if (regForm) {
        regForm.addEventListener('submit', function() {
            validateAllFields();
        });
    }
    var alertBox = document.querySelector('.alert-danger');
    if (alertBox) {
        setTimeout(function() {
            alertBox.style.transition = 'opacity 0.5s ease-out';
            alertBox.style.opacity = '0';
            setTimeout(function() {
                alertBox.style.display = 'none';
            }, 500);
        }, 6000);
    }
});

toggleRole();
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>