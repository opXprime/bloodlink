<?php
// Password and PIN recovery via security question verification
// Step 1: Enter email → Step 2: Answer security question → Step 3: Choose what to reset

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/../../includes/bootstrap.php';

// Already logged in — no need for recovery
if (isLoggedIn()) redirect('/index.php');

$db     = Database::getInstance();
$step   = $_SESSION['recovery_step'] ?? 'email';
$errors = [];

// ---- Step 1: Find account by email ----
if ($step === 'email' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find_email'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!validateEmail($email)) {
            $errors[] = 'Invalid email.';
        } else {
            $s = $db->prepare("SELECT id, security_question, role FROM users WHERE email = :e AND is_active = 1");
            $s->execute([':e' => $email]);
            $user = $s->fetch();

            if (!$user) {
                $errors[] = 'No account found.';
            } else {
                $_SESSION['recovery_step']  = 'verify';
                $_SESSION['recovery_user']  = $user;
                $_SESSION['recovery_email'] = $email;
                $step = 'verify';
            }
        }
    }
}

// ---- Step 2: Verify security answer ----
if ($step === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_answer'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $user   = $_SESSION['recovery_user'] ?? null;
        $answer = trim($_POST['security_answer'] ?? '');

        if (!$user) {
            $errors[] = 'Session expired.';
            $step = 'email';
        } else {
            $s = $db->prepare("SELECT security_answer_hash FROM users WHERE id = :id");
            $s->execute([':id' => $user['id']]);
            $row = $s->fetch();

            if ($row && password_verify(strtolower($answer), $row['security_answer_hash'])) {
                // Admin gets to choose what to reset, donors/hospitals go straight to password
                if ($user['role'] === 'admin') {
                    $_SESSION['recovery_step'] = 'choose';
                    $step = 'choose';
                } else {
                    $_SESSION['recovery_step'] = 'reset_password';
                    $step = 'reset_password';
                }
            } else {
                $errors[] = 'Incorrect answer.';
            }
        }
    }
}

// ---- Step 2b (admin only): Choose what to reset ----
if ($step === 'choose' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_choice'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $choice = $_POST['reset_choice'] ?? '';
        if (in_array($choice, ['password', 'pin', 'both'])) {
            $_SESSION['recovery_step'] = 'reset_' . $choice;
            $step = 'reset_' . $choice;
        }
    }
}

// ---- Step 3: Perform the reset ----
if (in_array($step, ['reset_password', 'reset_pin', 'reset_both']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } else {
        $user = $_SESSION['recovery_user'] ?? null;

        if (!$user) {
            $errors[] = 'Session expired.';
        } else {
            $resetPw  = in_array($step, ['reset_password', 'reset_both']);
            $resetPin = in_array($step, ['reset_pin', 'reset_both']);

            // Validate password if resetting it
            if ($resetPw) {
                $newPw     = $_POST['new_password'] ?? '';
                $confirmPw = $_POST['confirm_password'] ?? '';
                if (strlen($newPw) < 6)      $errors[] = 'Password must be at least 6 characters.';
                elseif ($newPw !== $confirmPw) $errors[] = 'Passwords do not match.';
            }

            // Validate PIN if resetting it
            if ($resetPin) {
                $newPin = trim($_POST['new_pin'] ?? '');
                if (strlen($newPin) < 4) $errors[] = 'PIN must be at least 4 characters.';
            }

            if (empty($errors)) {
                // Reset password
                if ($resetPw) {
                    $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
                    $db->prepare("UPDATE users SET password_hash = :p WHERE id = :id")
                       ->execute([':p' => $hash, ':id' => $user['id']]);
                }

                // Reset PIN
                if ($resetPin) {
                    $pinHash = password_hash($newPin, PASSWORD_BCRYPT, ['cost' => 12]);
                    $db->prepare("UPDATE admin_credentials SET pin_hash = :p WHERE user_id = :u")
                       ->execute([':p' => $pinHash, ':u' => $user['id']]);
                }

                // Log the action
                $what = $resetPw && $resetPin ? 'password_and_pin' : ($resetPin ? 'pin' : 'password');
                logAction($what . '_reset', "User #{$user['id']}");

                // Clear recovery session data
                unset($_SESSION['recovery_step'], $_SESSION['recovery_user'], $_SESSION['recovery_email']);

                setFlash('success', ucfirst(str_replace('_', ' & ', $what)) . ' reset! Please login.');
                redirect('/modules/auth/login.php');
            }
        }
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="auth-container">
    <div class="card auth-card shadow">
        <div class="card-header">
            <h4 class="mb-0"><i class="fas fa-key me-2"></i>Account Recovery</h4>
        </div>
        <div class="card-body p-4">

            <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Progress indicator -->
            <div class="d-flex justify-content-center mb-4">
                <span class="badge <?= $step === 'email' ? 'bg-danger' : 'bg-secondary' ?> me-1 px-3 py-2">1. Email</span>
                <span class="badge <?= $step === 'verify' ? 'bg-danger' : 'bg-secondary' ?> me-1 px-3 py-2">2. Verify</span>
                <span class="badge <?= in_array($step, ['choose', 'reset_password', 'reset_pin', 'reset_both']) ? 'bg-danger' : 'bg-secondary' ?> px-3 py-2">3. Reset</span>
            </div>

            <?php // ---- STEP 1: Enter email ---- ?>
            <?php if ($step === 'email'): ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Your registered email</label>
                    <input type="email" class="form-control" name="email" required autofocus>
                </div>
                <button type="submit" name="find_email" class="btn btn-blood w-100 py-2">Find Account</button>
            </form>

            <?php // ---- STEP 2: Answer security question ---- ?>
            <?php elseif ($step === 'verify'): ?>
            <?php $ru = $_SESSION['recovery_user'] ?? []; ?>
            <div class="alert alert-info"><i class="fas fa-shield-alt me-2"></i>Answer your security question to continue.</div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= e($ru['security_question'] ?? '') ?></label>
                    <input type="text" class="form-control" name="security_answer" required autofocus>
                </div>
                <button type="submit" name="verify_answer" class="btn btn-blood w-100 py-2">Verify</button>
            </form>

            <?php // ---- STEP 2b (admin only): Choose what to reset ---- ?>
            <?php elseif ($step === 'choose'): ?>
            <div class="alert alert-info"><i class="fas fa-check-circle me-2"></i>Identity verified. What would you like to reset?</div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="d-grid gap-2">
                    <button name="reset_choice" value="password" class="btn btn-outline-danger py-3">
                        <i class="fas fa-lock me-2"></i>Reset Password Only
                    </button>
                    <button name="reset_choice" value="pin" class="btn btn-outline-warning py-3">
                        <i class="fas fa-hashtag me-2"></i>Reset Login PIN Only
                    </button>
                    <button name="reset_choice" value="both" class="btn btn-blood py-3">
                        <i class="fas fa-key me-2"></i>Reset Both Password & PIN
                    </button>
                </div>
            </form>

            <?php // ---- STEP 3: Reset password ---- ?>
            <?php elseif ($step === 'reset_password'): ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="new_password" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                </div>
                <button type="submit" name="do_reset" class="btn btn-blood w-100 py-2">Reset Password</button>
            </form>

            <?php // ---- STEP 3: Reset PIN only ---- ?>
            <?php elseif ($step === 'reset_pin'): ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-hashtag me-1"></i>New Login PIN</label>
                    <input type="password" class="form-control" name="new_pin" required minlength="4" maxlength="20"
                           placeholder="At least 4 characters">
                </div>
                <button type="submit" name="do_reset" class="btn btn-blood w-100 py-2">Reset PIN</button>
            </form>

            <?php // ---- STEP 3: Reset both ---- ?>
            <?php elseif ($step === 'reset_both'): ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="new_password" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-hashtag me-1"></i>New Login PIN</label>
                    <input type="password" class="form-control" name="new_pin" required minlength="4" maxlength="20"
                           placeholder="At least 4 characters">
                </div>
                <button type="submit" name="do_reset" class="btn btn-blood w-100 py-2">Reset Password & PIN</button>
            </form>
            <?php endif; ?>

            <!-- Navigation links -->
            <div class="text-center mt-3">
                <a href="login.php" class="auth-link"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
                <?php if ($step !== 'email'): ?>
                    <span class="text-muted mx-1">·</span>
                    <a href="forgot_password.php?reset=1" class="auth-link">Start Over</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php
// Handle "Start Over" link — clear recovery session and redirect
if (isset($_GET['reset'])) {
    unset($_SESSION['recovery_step'], $_SESSION['recovery_user'], $_SESSION['recovery_email']);
    redirect('/modules/auth/forgot_password.php');
}

require_once APP_ROOT . '/includes/footer.php';
?>