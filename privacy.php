<?php
// Privacy policy page

$pageTitle = 'Privacy Policy';
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/header.php';
?>

<div class="card">
<div class="card-body p-4">

    <h2 class="mb-4"><i class="fas fa-shield-alt me-2 text-danger"></i>Privacy Policy</h2>
    <p class="text-muted">Last updated: <?= date('F Y') ?></p>

    <!-- 1. Data collection -->
    <h5>1. Information We Collect</h5>
    <p>When you register, we collect your name (or hospital name), email address, password (stored encrypted),
       a security question and answer (stored encrypted), your selected country, city, and area, and your role
       (donor or hospital). For donors, we also collect blood type and optional phone number. For hospitals,
       we collect license number, contact phone, email, address, and verification documents.</p>

    <!-- 2. Data usage -->
    <h5>2. How We Use Your Data</h5>
    <p>Your data is used solely for blood donation coordination, including matching donors with hospital blood
       requests based on blood type compatibility and area proximity, managing bookings between donors and
       hospitals, hospital verification by administrators, and sending in-app notifications about requests
       and booking updates.</p>

    <!-- 3. Location privacy -->
    <h5>3. Location Data</h5>
    <p>We only store area-level location data (e.g., "Baneshwor" or "Nørrebro"), not your exact address or
       GPS coordinates. Area centroid coordinates are stored server-side for distance calculations but are
       never shared with other users. Donors and hospitals only see area names, never coordinates.</p>

    <!-- 4. Country-level isolation -->
    <h5>4. Data Isolation</h5>
    <p>All data is isolated by country. Donors in Nepal cannot see hospital requests from Denmark and vice
       versa. This ensures regional data separation and compliance.</p>

    <!-- 5. Security -->
    <h5>5. Security Measures</h5>
    <p>All passwords are hashed using bcrypt with a cost factor of 12. Security answers are also hashed.
       CSRF tokens protect all form submissions. Sessions expire after 30 minutes of inactivity. Admin
       accounts require an additional security key. File uploads are validated for type and size, and PHP
       execution is blocked in the uploads directory.</p>

    <!-- 6. No third-party sharing -->
    <h5>6. Data Sharing</h5>
    <p>We do not share, sell, or rent your personal data to any third parties. Hospital verification
       documents are only visible to system administrators. Donor profiles (name, blood type, area) are
       visible to hospitals only through the matching system within the same country.</p>

    <!-- 7. Retention -->
    <h5>7. Data Retention</h5>
    <p>Your data is retained as long as your account is active. Donation history is kept for medical record
       purposes. System logs are maintained for security auditing.</p>

    <!-- 8. Right to erasure -->
    <h5>8. Account Deletion</h5>
    <p>You can delete your account at any time from your profile page. Account deletion is permanent and
       removes your user record and associated profile data. Some data (such as completed donation records
       and system logs) may be retained for medical and audit purposes even after account deletion.</p>

    <!-- 9. Violations -->
    <h5>9. Account Termination for Violations</h5>
    <p>If you violate the Terms and Conditions, misuse the system, provide false medical information, or
       engage in any activity that compromises the safety and integrity of the platform, your account may
       be deactivated or permanently deleted by an administrator. In such cases, you will be notified with
       a reason for the action taken. Repeated misuse, fraudulent bookings, or any behaviour that endangers
       donors or patients will result in immediate account removal without prior notice.</p>

    <!-- 10. Cookies -->
    <h5>10. Cookies</h5>
    <p>We use session cookies to maintain your login state. These cookies are HTTP-only and use the SameSite
       Strict policy for security. We do not use tracking cookies or third-party analytics.</p>

    <!-- 11. Contact messages -->
    <h5>11. Contact Messages</h5>
    <p>Messages sent through our contact form (name, email, message) are stored in our database and accessible
       only to system administrators. These are used solely to respond to your inquiries.</p>

    <!-- 12. Policy updates -->
    <h5>12. Changes to This Policy</h5>
    <p>We may update this privacy policy from time to time. Continued use of the platform constitutes
       acceptance of the updated policy.</p>

    <!-- 13. Contact -->
    <h5>13. Contact</h5>
    <p>For privacy-related inquiries, please use our <a href="<?= APP_URL ?>/contact.php">contact form</a>.</p>

</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>