<?php
// First-run setup script — creates the initial admin account with PIN
//
// Run once after importing schema.sql, then delete this file.
// Only accessible from localhost for security.

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';

// Block remote access — localhost only
$allowed = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed)) {
    http_response_code(403);
    die('Forbidden — localhost only.');
}

// Admin credentials — change before running if needed
$adminEmail    = 'admin@bloodlink.com';
$adminPassword = 'Admin123';
$adminPin      = '1234';
$adminName     = 'System Admin';
$secQuestion   = 'What is the system name?';
$secAnswer     = 'bloodlink';

try {
    $db = Database::getInstance();

    // Refuse if admin already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute([':email' => $adminEmail]);

    if ($stmt->fetch()) {
        echo "<h2 style='color:orange; font-family:sans-serif;'>Admin already exists.</h2>";
        echo "<p style='font-family:sans-serif;'>Email: <strong>{$adminEmail}</strong></p>";
        echo "<p style='font-family:sans-serif;'>Forgot password? Reset it in phpMyAdmin or re-import schema.sql.</p>";
        echo "<p style='font-family:sans-serif;'><a href='modules/auth/login.php'>Go to Login</a></p>";
        exit;
    }

    // Hash credentials (bcrypt cost 12)
    $hPw  = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $hPin = password_hash($adminPin, PASSWORD_BCRYPT, ['cost' => 12]);
    $hAns = password_hash(strtolower($secAnswer), PASSWORD_BCRYPT, ['cost' => 12]);

    // Insert admin user
    $stmt = $db->prepare(
        "INSERT INTO users (name, email, password_hash, security_question, security_answer_hash, role, is_active)
         VALUES (:name, :email, :pw, :sq, :sa, 'admin', 1)"
    );
    $stmt->execute([
        ':name'  => $adminName,
        ':email' => $adminEmail,
        ':pw'    => $hPw,
        ':sq'    => $secQuestion,
        ':sa'    => $hAns,
    ]);

    $userId = $db->lastInsertId();

    // Store PIN separately in admin_credentials table
    $stmt = $db->prepare(
        "INSERT INTO admin_credentials (user_id, pin_hash) VALUES (:uid, :pin)"
    );
    $stmt->execute([':uid' => $userId, ':pin' => $hPin]);

    // Display credentials to the user
    echo "<div style='font-family:sans-serif; max-width:480px; margin:50px auto; padding:20px; border:2px solid #28a745; border-radius:8px;'>";
    echo "<h2 style='color:#28a745;'>Admin account created</h2>";
    echo "<table style='width:100%;'>";
    echo "<tr><td style='padding:5px;'><b>Email:</b></td><td style='padding:5px;'>{$adminEmail}</td></tr>";
    echo "<tr><td style='padding:5px;'><b>Password:</b></td><td style='padding:5px;'>{$adminPassword}</td></tr>";
    echo "<tr><td style='padding:5px;'><b>Login PIN:</b></td><td style='padding:5px;'>{$adminPin}</td></tr>";
    echo "<tr><td style='padding:5px;'><b>Security Q:</b></td><td style='padding:5px;'>{$secQuestion}</td></tr>";
    echo "<tr><td style='padding:5px;'><b>Security A:</b></td><td style='padding:5px;'>{$secAnswer}</td></tr>";
    echo "</table>";
    echo "<div style='margin-top:15px; padding:10px; background:#fff3cd; border-radius:4px;'>";
    echo "<strong>Important:</strong> Write down these credentials now. Delete this file after use.";
    echo "<br>You can change your password and PIN from the admin profile after logging in.</div>";
    echo "<p style='margin-top:15px;'><a href='modules/auth/login.php' style='color:#dc3545;'>Go to Login &rarr;</a></p>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<h2 style='color:red; font-family:sans-serif;'>Error</h2>";
    echo "<p style='font-family:sans-serif;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p style='font-family:sans-serif;'>Did you import <code>sql/schema.sql</code> first?</p>";
}