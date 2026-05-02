<?php
// Landing page — public homepage with system info, how it works, FAQ, and stats

$pageTitle = 'Home';
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- ========== HERO SECTION ========== -->
<div class="hero-section text-center">
    <div class="container">
        <h1><i class="fas fa-heartbeat me-3"></i>BloodLink</h1>
        <p class="lead mt-3 mb-4" style="max-width:700px;margin:0 auto;">
            A blood donation coordination platform connecting donors with hospitals in need. Every drop counts.
        </p>

        <?php if (!isLoggedIn()): ?>
        <div class="mt-4">
            <a href="<?= APP_URL ?>/modules/auth/register.php" class="btn btn-light btn-lg me-2 fw-bold">
                <i class="fas fa-user-plus me-2"></i>Register Now
            </a>
            <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn btn-outline-light btn-lg">
                <i class="fas fa-sign-in-alt me-2"></i>Login
            </a>
        </div>
        <?php else: ?>
        <div class="mt-4">
            <?php
            $r = currentUserRole();
            $d = $r === 'donor' ? '/modules/donor/dashboard.php'
               : ($r === 'hospital' ? '/modules/hospital/dashboard.php'
               : '/modules/admin/dashboard.php');
            ?>
            <a href="<?= APP_URL . $d ?>" class="btn btn-light btn-lg fw-bold">Go to Dashboard</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========== AIMS & OBJECTIVES ========== -->
<h2 class="text-center mb-4 fw-bold">Our Mission</h2>
<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-bullseye"></i></div>
            <h5>Our Aim</h5>
            <p class="text-muted">To bridge the gap between blood donors and hospitals through a secure, country-based coordination platform that saves lives.</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-list-check"></i></div>
            <h5>Objectives</h5>
            <p class="text-muted">Enable real-time blood request matching, streamline hospital verification, and maintain secure donor privacy with location-based isolation.</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-shield-alt"></i></div>
            <h5>Privacy First</h5>
            <p class="text-muted">Users select areas instead of sharing exact coordinates. All data is isolated by country. Security questions protect every account.</p>
        </div>
    </div>
</div>

<!-- ========== HOW IT WORKS ========== -->
<h2 class="text-center mb-4 fw-bold">How It Works</h2>
<div class="row g-4 mb-5">
    <div class="col-md-3">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-user-plus"></i></div>
            <h5>1. Register</h5>
            <p class="text-muted">Sign up as a Donor or Hospital. Select your country, city, and area.</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-hospital"></i></div>
            <h5>2. Verification</h5>
            <p class="text-muted">Hospitals submit license details for admin verification before posting requests.</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-search-location"></i></div>
            <h5>3. Match</h5>
            <p class="text-muted">Smart matching connects compatible donors with nearby hospital blood requests.</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-4 h-100">
            <div class="feature-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-hand-holding-heart"></i></div>
            <h5>4. Donate</h5>
            <p class="text-muted">Book, donate, and track your impact. Every donation can save up to three lives.</p>
        </div>
    </div>
</div>

<!-- ========== BLOOD COMPATIBILITY TABLE ========== -->
<div class="card mb-5">
    <div class="card-body p-4">
        <h3 class="text-center mb-4 fw-bold">Blood Type Compatibility</h3>
        <div class="table-responsive">
            <table class="table table-bordered text-center mb-0">
                <thead>
                    <tr><th>Recipient</th><th>Can Receive From</th></tr>
                </thead>
                <tbody>
                    <tr><td><span class="badge bg-danger">O-</span></td><td>O-</td></tr>
                    <tr><td><span class="badge bg-danger">O+</span></td><td>O-, O+</td></tr>
                    <tr><td><span class="badge bg-danger">A-</span></td><td>O-, A-</td></tr>
                    <tr><td><span class="badge bg-danger">A+</span></td><td>O-, O+, A-, A+</td></tr>
                    <tr><td><span class="badge bg-danger">B-</span></td><td>O-, B-</td></tr>
                    <tr><td><span class="badge bg-danger">B+</span></td><td>O-, O+, B-, B+</td></tr>
                    <tr><td><span class="badge bg-danger">AB-</span></td><td>O-, A-, B-, AB-</td></tr>
                    <tr><td><span class="badge bg-danger">AB+</span></td><td>All Types</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========== FAQ SECTION ========== -->
<h2 class="text-center mb-4 fw-bold">Frequently Asked Questions</h2>
<div class="row justify-content-center mb-5">
<div class="col-md-8">
    <?php
    $faqs = [
        ['Who can donate blood?',
         'Most healthy adults aged 18-65 weighing over 50kg can donate. You must wait at least 12 weeks (90 days) between donations.'],
        ['How long does a donation take?',
         'The actual blood draw takes about 10 minutes. The entire visit including registration and a short rest takes 30-45 minutes.'],
        ['Is my data safe?',
         'Yes. We only store area-level location (not exact coordinates). All passwords and security answers are encrypted with industry-standard hashing.'],
        ['How does matching work?',
         'When a hospital posts a blood request, compatible donors in the same country are found based on blood type compatibility and distance from the hospital area.'],
        ['What happens after I book?',
         'The hospital will review your booking and confirm or reschedule. You will receive a notification when your booking status changes.'],
        ['Can hospitals see my exact address?',
         'No. Hospitals only see your area (e.g., Baneshwor). Your exact address is never shared.'],
        ['Why am I marked as ineligible?',
         'After donating, you are automatically set as ineligible for 12 weeks (90 days) for your safety. Your next eligible date is shown on your dashboard.'],
    ];

    foreach ($faqs as $i => $f):
    ?>
    <div class="card mb-2">
        <div class="card-body py-3 px-4">
            <!-- Toggle question/answer -->
            <div class="d-flex justify-content-between align-items-center" style="cursor:pointer"
                 onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
                <strong><?= e($f[0]) ?></strong>
                <i class="fas fa-chevron-down text-muted"></i>
            </div>
            <p class="text-muted mb-0 mt-2" style="display:none"><?= e($f[1]) ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</div>

<!-- ========== CONTACT CTA ========== -->
<div class="text-center mb-5">
    <h5>Still have questions?</h5>
    <a href="<?= APP_URL ?>/contact.php" class="btn btn-blood">
        <i class="fas fa-envelope me-2"></i>Contact Us
    </a>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>