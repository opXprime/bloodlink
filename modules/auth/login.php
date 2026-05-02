<?php
// Login — multi-step authentication
// Step 1: email + password
// Step 2a (donor/hospital): security question
// Step 2b (admin): PIN + security question

$pageTitle = 'Login';
require_once __DIR__ . '/../../includes/bootstrap.php';

if (isLoggedIn()) redirect('/index.php');

$db     = Database::getInstance();
$errors = [];
$old    = [];
$step   = $_SESSION['login_step'] ?? 'credentials';

// Cleanup old failed login attempts occasionally (1 in 20 chance)
if (rand(1, 20) === 1) {
    try {
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (PDOException $ex) {}
}


// =========================================================================
// STEP 1: Email + Password
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step_credentials'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $old['email'] = $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $errors[] = 'Email and password required.';
        } elseif (isLoginLocked($email)) {
            $errors[] = 'Too many failed attempts. Account locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes. Try again later.';
        } else {
            // Check if account was deleted by admin
            $delCheck = $db->prepare(
                "SELECT details FROM system_logs
                 WHERE action = 'account_deleted_by_admin' AND details LIKE :e
                 ORDER BY created_at DESC LIMIT 1"
            );
            $delCheck->execute([':e' => $email . '|%']);
            $delRecord = $delCheck->fetch();

            if ($delRecord) {
                $deletedMsg = true;
            } else {
                $s = $db->prepare(
                    "SELECT u.*, c.name AS country_name
                     FROM users u
                     LEFT JOIN countries c ON u.country_id = c.id
                     WHERE u.email = :e AND u.is_active = 1"
                );
                $s->execute([':e' => $email]);
                $user = $s->fetch();

                if (!$user) {
                    recordFailedLogin($email);
                    $errors[] = 'Invalid email or password.';
                } elseif (!password_verify($password, $user['password_hash'])) {
                    recordFailedLogin($email);
                    $errors[] = 'Invalid email or password.';
                } elseif ($user['role'] === 'admin') {
                    // Admin — load PIN and proceed to PIN+security step
                    $pinStmt = $db->prepare("SELECT pin_hash FROM admin_credentials WHERE user_id = :uid");
                    $pinStmt->execute([':uid' => $user['id']]);
                    $pinRow = $pinStmt->fetch();
                    $user['pin_hash'] = $pinRow['pin_hash'] ?? null;

                    $_SESSION['login_pending_user'] = $user;
                    $_SESSION['login_step'] = 'admin_pin';
                    $step = 'admin_pin';
                } else {
                    // Donor/hospital — proceed to security question
                    $_SESSION['login_pending_user'] = $user;
                    $_SESSION['login_step'] = 'security';
                    $step = 'security';
                }
            }
        }
    }
}


// =========================================================================
// STEP 2a: Donor/Hospital — Security Question
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step_security'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $user   = $_SESSION['login_pending_user'] ?? null;
        $answer = trim($_POST['security_answer'] ?? '');

        if (!$user) {
            $errors[] = 'Session expired.';
            $step = 'credentials';
            unset($_SESSION['login_step']);
        } elseif (password_verify(strtolower($answer), $user['security_answer_hash'])) {
            // Correct — complete login
            clearLoginAttempts($user['email']);
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = [
                'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                'role' => $user['role'], 'country_id' => $user['country_id'],
                'country_name' => $user['country_name'] ?? '',
            ];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['login_step'], $_SESSION['login_pending_user']);

            logAction('user_login', $user['email']);
            redirect($user['role'] === 'hospital' ? '/modules/hospital/dashboard.php' : '/modules/donor/dashboard.php');
        } else {
            recordFailedLogin($user['email']);
            $errors[] = 'Incorrect security answer.';
            $step = 'security';
        }
    }
}


// =========================================================================
// STEP 2b: Admin — PIN + Security Question
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step_admin_pin'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $user   = $_SESSION['login_pending_user'] ?? null;
        $pin    = trim($_POST['login_pin'] ?? '');
        $answer = trim($_POST['security_answer'] ?? '');

        if (!$user) {
            $errors[] = 'Session expired.';
            $step = 'credentials';
            unset($_SESSION['login_step']);
        } else {
            $pinOk = !empty($user['pin_hash']) && password_verify($pin, $user['pin_hash']);
            $ansOk = password_verify(strtolower($answer), $user['security_answer_hash']);

            if (!$pinOk) {
                recordFailedLogin($user['email']);
                $errors[] = 'Invalid login PIN.';
                $step = 'admin_pin';
            } elseif (!$ansOk) {
                recordFailedLogin($user['email']);
                $errors[] = 'Incorrect security answer.';
                $step = 'admin_pin';
            } else {
                // Both correct — complete admin login
                clearLoginAttempts($user['email']);
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = [
                    'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                    'role' => 'admin', 'country_id' => $user['country_id'],
                    'country_name' => $user['country_name'] ?? '',
                ];
                $_SESSION['last_activity'] = time();
                unset($_SESSION['login_step'], $_SESSION['login_pending_user']);

                logAction('admin_login', $user['email']);
                redirect('/modules/admin/dashboard.php');
            }
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="auth-container">
    <div class="card auth-card shadow">
        <div class="card-header">
            <h4 class="mb-0"><i class="fas fa-sign-in-alt me-2"></i>Login</h4>
        </div>
        <div class="card-body p-4">

            <?php // Validation errors ?>
            <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php // Account deleted popup ?>
            <?php if (!empty($deletedMsg)): ?>
            <div id="deletedPopup" class="alert alert-danger shadow"
                 style="position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:9999;max-width:420px;text-align:center;animation:slideIn .4s ease">
                <i class="fas fa-ban fa-2x mb-2 d-block"></i>
                <strong>Account Deleted</strong><br>
                This account has been removed due to violation of our terms and conditions.
            </div>
            <style>@keyframes slideIn{from{opacity:0;transform:translate(-50%,-20px)}to{opacity:1;transform:translate(-50%,0)}}</style>
            <script>
            setTimeout(function(){
                var p = document.getElementById('deletedPopup');
                if (p) { p.style.transition = 'opacity 0.5s'; p.style.opacity = '0'; setTimeout(function(){ p.remove(); }, 500); }
            }, 5000);
            </script>
            <?php endif; ?>

            <?php // ---- STEP 1: Email + Password ---- ?>
            <?php if ($step === 'credentials'): ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" name="email"
                           value="<?= e($old['email'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" name="password" required>
                </div>
                <button type="submit" name="step_credentials" class="btn btn-blood w-100 py-2 fw-bold">Continue</button>
                <div class="text-center mt-3">
                    <a href="forgot_password.php" class="auth-link">Forgot password?</a>
                    <span class="text-muted mx-1">&middot;</span>
                    <a href="register.php" class="auth-link">Create Account</a>
                </div>
            </form>

            <?php // ---- STEP 2a: Donor/Hospital Security Question ---- ?>
            <?php elseif ($step === 'security'): ?>
            <?php $pu = $_SESSION['login_pending_user'] ?? []; ?>
            <div class="alert alert-info">
                <i class="fas fa-shield-alt me-2"></i>Answer your security question to continue.
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= e($pu['security_question'] ?? 'Security Question') ?></label>
                    <input type="text" class="form-control" name="security_answer" required autofocus>
                </div>
                <button type="submit" name="step_security" class="btn btn-blood w-100 py-2 fw-bold">Login</button>
                <div class="text-center mt-3">
                    <a href="login.php?reset=1" class="auth-link"><i class="fas fa-arrow-left me-1"></i>Start Over</a>
                </div>
            </form>

            <?php // ---- STEP 2b: Admin PIN + Security Question ---- ?>
            <?php elseif ($step === 'admin_pin'): ?>
            <?php $pu = $_SESSION['login_pending_user'] ?? []; ?>
            <div class="alert alert-warning">
                <i class="fas fa-key me-2"></i>Admin account detected. Enter your login PIN and security answer.
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-key me-1"></i>Login PIN</label>
                    <input type="password" class="form-control" name="login_pin" required autofocus
                           maxlength="20" placeholder="Your personal login PIN">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= e($pu['security_question'] ?? 'Security Question') ?></label>
                    <input type="text" class="form-control" name="security_answer" required>
                </div>
                <button type="submit" name="step_admin_pin" class="btn btn-blood w-100 py-2 fw-bold">Login as Admin</button>
                <div class="text-center mt-3">
                    <a href="login.php?reset=1" class="auth-link"><i class="fas fa-arrow-left me-1"></i>Start Over</a>
                </div>
            </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php
// Handle "Start Over" link — clear login session and redirect
if (isset($_GET['reset'])) {
    unset($_SESSION['login_step'], $_SESSION['login_pending_user']);
    redirect('/modules/auth/login.php');
}

require_once APP_ROOT . '/includes/footer.php';
?>