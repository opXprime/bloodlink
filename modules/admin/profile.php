<?php
// Admin profile — manage own credentials, create new admins, security checklist

$pageTitle = 'Admin Profile';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- Create new admin account ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin']) && verifyCSRF()) {
    $aName  = trim($_POST['admin_name'] ?? '');
    $aEmail = trim($_POST['admin_email'] ?? '');
    $aPw    = $_POST['admin_password'] ?? '';
    $aPin   = trim($_POST['admin_pin'] ?? '');
    $aSq    = trim($_POST['admin_sq'] ?? '');
    $aSa    = trim($_POST['admin_sa'] ?? '');
    $errs   = [];

    if (strlen($aName) < 2)  $errs[] = 'Name required.';
    if (!validateEmail($aEmail)) $errs[] = 'Valid email required.';
    $pwErrs = validatePassword($aPw);
    if ($pwErrs) $errs = array_merge($errs, $pwErrs);
    if (strlen($aPin) < 4)   $errs[] = 'PIN must be at least 4 characters.';
    if (strlen($aSq) < 3)    $errs[] = 'Security question required.';
    if (strlen($aSa) < 1)    $errs[] = 'Security answer required.';

    // Check for duplicate email
    if (empty($errs)) {
        $chk = $db->prepare("SELECT id FROM users WHERE email = :e");
        $chk->execute([':e' => $aEmail]);
        if ($chk->fetch()) $errs[] = 'Email already in use.';
    }

    if (!empty($errs)) {
        setFlash('error', implode(' ', $errs));
    } else {
        // Hash all credentials (bcrypt cost 12)
        $hPw  = password_hash($aPw, PASSWORD_BCRYPT, ['cost' => 12]);
        $hPin = password_hash($aPin, PASSWORD_BCRYPT, ['cost' => 12]);
        $hSa  = password_hash(strtolower($aSa), PASSWORD_BCRYPT, ['cost' => 12]);

        $db->prepare(
            "INSERT INTO users (name, email, password_hash, security_question, security_answer_hash, role, country_id, is_active)
             VALUES (:n, :e, :p, :sq, :sa, 'admin', :c, 1)"
        )->execute([
            ':n' => $aName, ':e' => $aEmail, ':p' => $hPw,
            ':sq' => $aSq, ':sa' => $hSa, ':c' => currentCountryId()
        ]);

        $newAdminId = $db->lastInsertId();

        // Store PIN separately in admin_credentials table
        $db->prepare("INSERT INTO admin_credentials (user_id, pin_hash) VALUES (:u, :pin)")
           ->execute([':u' => $newAdminId, ':pin' => $hPin]);

        logAction('admin_created', "$aEmail by " . currentUser()['email']);
        setFlash('success', "Admin account created for $aEmail — remind them of their PIN");
    }
    redirect('/modules/admin/profile.php');
}

// ---- Update own password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password']) && verifyCSRF()) {
    $curPw  = $_POST['current_password'] ?? '';
    $newPw  = $_POST['new_password'] ?? '';
    $confPw = $_POST['confirm_password'] ?? '';

    $me = $db->prepare("SELECT password_hash FROM users WHERE id = :u");
    $me->execute([':u' => currentUserId()]);
    $row = $me->fetch();

    if (!$row || !password_verify($curPw, $row['password_hash'])) {
        setFlash('error', 'Current password is incorrect.');
    } else {
        $pwErrs = validatePassword($newPw);
        if ($pwErrs) {
            setFlash('error', implode(' ', $pwErrs));
        } elseif ($newPw !== $confPw) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $db->prepare("UPDATE users SET password_hash = :p WHERE id = :u")
               ->execute([':p' => password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]), ':u' => currentUserId()]);
            logAction('password_changed', 'admin self-change');
            setFlash('success', 'Password updated.');
        }
    }
    redirect('/modules/admin/profile.php');
}

// ---- Update own login PIN ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pin']) && verifyCSRF()) {
    $curPw  = $_POST['pin_current_password'] ?? '';
    $newPin = trim($_POST['new_pin'] ?? '');

    $me = $db->prepare("SELECT password_hash FROM users WHERE id = :u");
    $me->execute([':u' => currentUserId()]);
    $row = $me->fetch();

    if (!$row || !password_verify($curPw, $row['password_hash'])) {
        setFlash('error', 'Current password is incorrect.');
    } elseif (strlen($newPin) < 4) {
        setFlash('error', 'PIN must be at least 4 characters.');
    } else {
        $db->prepare("UPDATE admin_credentials SET pin_hash = :p WHERE user_id = :u")
           ->execute([':p' => password_hash($newPin, PASSWORD_BCRYPT, ['cost' => 12]), ':u' => currentUserId()]);
        logAction('pin_changed', 'admin self-change');
        setFlash('success', 'Login PIN updated.');
    }
    redirect('/modules/admin/profile.php');
}

// ---- Update own security question/answer ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_security_qa']) && verifyCSRF()) {
    $curPw = $_POST['qa_current_password'] ?? '';
    $newQ  = trim($_POST['new_question'] ?? '');
    $newA  = trim($_POST['new_answer'] ?? '');

    $me = $db->prepare("SELECT password_hash FROM users WHERE id = :u");
    $me->execute([':u' => currentUserId()]);
    $row = $me->fetch();

    if (!$row || !password_verify($curPw, $row['password_hash'])) {
        setFlash('error', 'Current password is incorrect.');
    } elseif (strlen($newQ) < 3 || strlen($newA) < 1) {
        setFlash('error', 'Question and answer required.');
    } else {
        $db->prepare("UPDATE users SET security_question = :q, security_answer_hash = :a WHERE id = :u")
           ->execute([
               ':q' => $newQ,
               ':a' => password_hash(strtolower($newA), PASSWORD_BCRYPT, ['cost' => 12]),
               ':u' => currentUserId()
           ]);
        logAction('security_qa_changed', 'admin self-change');
        setFlash('success', 'Security question updated.');
    }
    redirect('/modules/admin/profile.php');
}

// ---- Self-delete (blocked if last admin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_delete_self']) && verifyCSRF()) {
    $confirm    = $_POST['confirm_delete'] ?? '';
    $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

    if ($adminCount <= 1) {
        setFlash('error', 'Cannot delete the only admin. Create another first.');
    } elseif ($confirm === 'DELETE') {
        logAction('admin_self_deleted', currentUser()['email']);
        $db->prepare("DELETE FROM users WHERE id = :u")
           ->execute([':u' => currentUserId()]);
        session_destroy();
        header('Location: ' . APP_URL . '/index.php');
        exit;
    } else {
        setFlash('error', 'Type DELETE to confirm.');
    }
    redirect('/modules/admin/profile.php');
}

// ---- Load profile data for security checklist ----
$me = $db->prepare("SELECT * FROM users WHERE id = :u");
$me->execute([':u' => currentUserId()]);
$admin = $me->fetch();

$daysSinceCreated = max(0, (time() - strtotime($admin['created_at'])) / 86400);
$daysSinceUpdated = max(0, (time() - strtotime($admin['updated_at'])) / 86400);

$pinCheck = $db->prepare("SELECT pin_hash FROM admin_credentials WHERE user_id = :u");
$pinCheck->execute([':u' => currentUserId()]);
$pinRow = $pinCheck->fetch();

$hasPin = !empty($pinRow['pin_hash']);
$hasSQ  = !empty($admin['security_question']) && strlen($admin['security_question']) > 2;
$pwOld  = $daysSinceUpdated > 21; // Flag if password might be stale (3+ weeks)

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-user-shield me-2 text-danger"></i>Admin Profile</h2>

<!-- ========== SECURITY CHECKLIST ========== -->
<div class="card mb-4 border-info">
    <div class="card-header bg-info bg-opacity-10">
        <h5 class="mb-0 text-info"><i class="fas fa-clipboard-check me-2"></i>Security Checklist</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p class="mb-2">
                    <?= $hasPin ? '<i class="fas fa-check-circle text-success me-2"></i>' : '<i class="fas fa-times-circle text-danger me-2"></i>' ?>
                    Login PIN is <?= $hasPin ? 'set' : '<strong>not set</strong> — set one below' ?>
                </p>
                <p class="mb-2">
                    <?= $hasSQ ? '<i class="fas fa-check-circle text-success me-2"></i>' : '<i class="fas fa-times-circle text-danger me-2"></i>' ?>
                    Security question is <?= $hasSQ ? 'set' : '<strong>not set</strong>' ?>
                </p>
                <p class="mb-2">
                    <?= !$pwOld ? '<i class="fas fa-check-circle text-success me-2"></i>' : '<i class="fas fa-exclamation-triangle text-warning me-2"></i>' ?>
                    Password <?= $pwOld ? 'may be stale (last update ' . round($daysSinceUpdated) . ' days ago) — consider changing it' : 'recently updated' ?>
                </p>
            </div>
            <div class="col-md-6">
                <p class="mb-2"><i class="fas fa-info-circle text-info me-2"></i>Logged in as: <strong><?= e($admin['email']) ?></strong></p>
                <p class="mb-2"><i class="fas fa-info-circle text-info me-2"></i>Account created: <?= e(date('M j, Y', strtotime($admin['created_at']))) ?></p>
                <p class="mb-0"><i class="fas fa-info-circle text-info me-2"></i>Current security Q: <em><?= e($admin['security_question'] ?? 'Not set') ?></em></p>
            </div>
        </div>
        <hr>
        <p class="text-muted small mb-0">
            <i class="fas fa-lightbulb me-1"></i>Change your password every few weeks. Avoid using your birthday or common words as your PIN.
            Pick a security answer only you would know — not something anyone could find on social media.
        </p>
    </div>
</div>

<!-- ========== UPDATE CREDENTIALS ========== -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Update Credentials</h5>
    </div>
    <div class="card-body">
        <div class="row g-4">

            <!-- Change password -->
            <div class="col-md-4">
                <h6 class="fw-bold"><i class="fas fa-key me-1"></i>Change Password</h6>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-2"><input type="password" class="form-control form-control-sm" name="current_password" placeholder="Current password" required></div>
                    <div class="mb-2"><input type="password" class="form-control form-control-sm" name="new_password" placeholder="New password (8+ chars, upper+lower)" required minlength="8"></div>
                    <div class="mb-2"><input type="password" class="form-control form-control-sm" name="confirm_password" placeholder="Confirm new password" required></div>
                    <button type="submit" name="update_password" class="btn btn-outline-primary btn-sm w-100">Update Password</button>
                </form>
            </div>

            <!-- Change login PIN -->
            <div class="col-md-4">
                <h6 class="fw-bold"><i class="fas fa-hashtag me-1"></i>Change Login PIN</h6>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-2"><input type="password" class="form-control form-control-sm" name="pin_current_password" placeholder="Current password" required></div>
                    <div class="mb-2"><input type="password" class="form-control form-control-sm" name="new_pin" placeholder="New PIN (4+ characters)" required minlength="4"></div>
                    <button type="submit" name="update_pin" class="btn btn-outline-primary btn-sm w-100">Update PIN</button>
                </form>
            </div>

            <!-- Change security question -->
            <div class="col-md-4">
                <h6 class="fw-bold"><i class="fas fa-question-circle me-1"></i>Change Security Question</h6>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-2"><input type="password" class="form-control form-control-sm" name="qa_current_password" placeholder="Current password" required></div>
                    <div class="mb-2"><input type="text" class="form-control form-control-sm" name="new_question" placeholder="New security question" required></div>
                    <div class="mb-2"><input type="text" class="form-control form-control-sm" name="new_answer" placeholder="New answer" required></div>
                    <button type="submit" name="update_security_qa" class="btn btn-outline-primary btn-sm w-100">Update Q&A</button>
                </form>
            </div>

        </div>
    </div>
</div>

<!-- ========== CREATE NEW ADMIN ========== -->
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary bg-opacity-10">
        <h6 class="mb-0 text-primary"><i class="fas fa-user-plus me-2"></i>Create New Admin</h6>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <div class="col-md-2"><label class="form-label small mb-0">Name</label><input type="text" class="form-control form-control-sm" name="admin_name" required></div>
            <div class="col-md-2"><label class="form-label small mb-0">Email</label><input type="email" class="form-control form-control-sm" name="admin_email" required></div>
            <div class="col-md-1"><label class="form-label small mb-0">Password</label><input type="password" class="form-control form-control-sm" name="admin_password" required minlength="8"></div>
            <div class="col-md-1"><label class="form-label small mb-0">Login PIN</label><input type="password" class="form-control form-control-sm" name="admin_pin" required minlength="4" placeholder="e.g. 5291"></div>
            <div class="col-md-2"><label class="form-label small mb-0">Security Q</label><input type="text" class="form-control form-control-sm" name="admin_sq" required></div>
            <div class="col-md-2"><label class="form-label small mb-0">Security A</label><input type="text" class="form-control form-control-sm" name="admin_sa" required></div>
            <div class="col-md-2"><button type="submit" name="create_admin" class="btn btn-primary btn-sm w-100"><i class="fas fa-plus me-1"></i>Create Admin</button></div>
        </form>
    </div>
</div>

<!-- ========== DELETE OWN ACCOUNT ========== -->
<div class="card border-danger">
    <div class="card-header bg-danger bg-opacity-10">
        <h5 class="mb-0 text-danger"><i class="fas fa-trash-alt me-2"></i>Delete My Admin Account</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">This permanently removes your admin account. Another admin must exist before you can do this.</p>
        <form method="POST" onsubmit="return this.querySelector('[name=confirm_delete]').value === 'DELETE' || alert('Type DELETE to confirm.') || false">
            <?= csrfField() ?>
            <div class="input-group">
                <input type="text" class="form-control" name="confirm_delete" placeholder="Type DELETE to confirm">
                <button type="submit" name="admin_delete_self" class="btn btn-danger">
                    <i class="fas fa-trash me-1"></i>Delete
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>