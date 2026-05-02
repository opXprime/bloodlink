<?php
// Contact page — send messages, view admin replies, continue conversation

$pageTitle = 'Contact Us';
require_once __DIR__ . '/includes/bootstrap.php';

$db = Database::getInstance();
$errors = [];
$sent = false;

// ---- Handle new message submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && verifyCSRF()) {
    $name  = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $msg   = trim($_POST['contact_message'] ?? '');

    if (strlen($name) < 2)      $errors[] = 'Name is required.';
    if (!validateEmail($email))  $errors[] = 'Valid email is required.';
    if (strlen($msg) < 5)        $errors[] = 'Message must be at least 5 characters.';

    if (empty($errors)) {
        $userId = isLoggedIn() ? currentUserId() : null;
        $db->prepare(
            "INSERT INTO contact_messages (user_id, name, email, message) VALUES (:uid, :n, :e, :m)"
        )->execute([':uid' => $userId, ':n' => $name, ':e' => $email, ':m' => $msg]);
        logAction('contact_message', "From: $email");
        $sent = true;
    }
}

// ---- Handle follow-up reply on an existing thread ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['follow_up']) && verifyCSRF() && isLoggedIn()) {
    $parentId = (int)$_POST['parent_id'];
    $reply    = trim($_POST['follow_up_text'] ?? '');

    if (strlen($reply) >= 2) {
        $db->prepare(
            "INSERT INTO contact_messages (user_id, name, email, message) VALUES (:u, :n, :e, :m)"
        )->execute([
            ':u' => currentUserId(),
            ':n' => currentUser()['name'],
            ':e' => currentUser()['email'],
            ':m' => $reply
        ]);
        logAction('contact_followup', "parent#$parentId");
        $sent = true;
    }
}

// ---- Load previous messages for logged-in users ----
$myMessages = [];
if (isLoggedIn()) {
    // Mark all replied messages as read when user visits this page
    $db->prepare(
        "UPDATE contact_messages SET is_read = 1 WHERE user_id = :u AND admin_reply IS NOT NULL AND is_read = 0"
    )->execute([':u' => currentUserId()]);

    $s = $db->prepare("SELECT * FROM contact_messages WHERE user_id = :u ORDER BY created_at DESC LIMIT 20");
    $s->execute([':u' => currentUserId()]);
    $myMessages = $s->fetchAll();
}

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-envelope me-2 text-danger"></i>Contact Us</h2>

<div class="row">
<div class="col-md-7">

    <?php // Success message ?>
    <?php if ($sent): ?>
    <div class="alert alert-success" id="sentAlert">
        <i class="fas fa-check-circle me-2"></i>Your message has been sent!
    </div>
    <script>
    setTimeout(function(){
        var a = document.getElementById('sentAlert');
        if (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(function(){ a.remove(); }, 500); }
    }, 4000);
    </script>
    <?php endif; ?>

    <?php // Validation errors ?>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Contact form -->
    <div class="card mb-4">
    <div class="card-body p-4">
        <form method="POST">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label fw-bold">Your Name *</label>
                <input type="text" class="form-control" name="contact_name"
                       value="<?= e(isLoggedIn() ? currentUser()['name'] : '') ?>"
                       <?= isLoggedIn() ? 'readonly' : '' ?> required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Your Email *</label>
                <input type="email" class="form-control" name="contact_email"
                       value="<?= e(isLoggedIn() ? currentUser()['email'] : '') ?>"
                       <?= isLoggedIn() ? 'readonly' : '' ?> required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Message *</label>
                <textarea class="form-control" name="contact_message" rows="4"
                          placeholder="How can we help?" required></textarea>
                <small class="text-muted mt-1 d-block">
                    <i class="fas fa-globe me-1"></i>Want your country or city added to the system?
                    Let us know which country, city, and areas you'd like included and we'll set it up.
                </small>
            </div>

            <button type="submit" name="send_message" class="btn btn-blood w-100">
                <i class="fas fa-paper-plane me-2"></i>Send Message
            </button>
        </form>
    </div>
    </div>

    <?php // Guest info ?>
    <?php if (!isLoggedIn()): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>Since you are not registered, we will reply to your query via email.
        Please make sure your email address is correct.
    </div>
    <?php endif; ?>

    <?php // Message history for logged-in users ?>
    <?php if (isLoggedIn() && !empty($myMessages)): ?>
    <div class="card">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-comments me-2 text-danger"></i>My Messages</h5>
        </div>
        <div class="card-body">
            <?php foreach ($myMessages as $m): ?>
            <div class="border rounded p-3 mb-3 <?= $m['admin_reply'] ? 'border-success' : '' ?>">

                <!-- Timestamp and status badge -->
                <div class="d-flex justify-content-between">
                    <small class="text-muted"><i class="fas fa-clock me-1"></i><?= e($m['created_at']) ?></small>
                    <?php if ($m['admin_reply']): ?>
                        <span class="badge bg-success">Replied</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Pending</span>
                    <?php endif; ?>
                </div>

                <!-- User's original message -->
                <p class="mt-2 mb-1"><strong>You:</strong> <?= e($m['message']) ?></p>

                <?php if ($m['admin_reply']): ?>
                <hr class="my-2">

                <!-- Admin reply -->
                <p class="mb-1 text-success">
                    <strong><i class="fas fa-reply me-1"></i>Admin:</strong> <?= e($m['admin_reply']) ?>
                </p>
                <small class="text-muted"><?= e($m['replied_at']) ?></small>

                <!-- Follow-up reply form -->
                <form method="POST" class="mt-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="parent_id" value="<?= $m['id'] ?>">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" name="follow_up_text" placeholder="Reply..." required>
                        <button type="submit" name="follow_up" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-reply me-1"></i>Reply
                        </button>
                    </div>
                </form>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Sidebar — contact info -->
<div class="col-md-5">
    <div class="card">
    <div class="card-body p-4">
        <h5><i class="fas fa-info-circle text-danger me-2"></i>Get in Touch</h5>
        <p class="text-muted">Have questions, feedback, or need support? We'd love to hear from you.</p>
        <p><i class="fas fa-envelope me-2 text-danger"></i>support@bloodlink.org</p>
        <p><i class="fas fa-globe me-2 text-danger"></i>www.bloodlink.org</p>
        <?php if (isLoggedIn()): ?>
            <p class="text-muted small">As a registered user, you can see admin replies here and continue the conversation.</p>
        <?php else: ?>
            <p class="text-muted small">Not registered? We will reply to your email directly within 24-48 hours.</p>
        <?php endif; ?>
    </div>
    </div>
</div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>