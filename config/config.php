<?php
// App-wide configuration — database creds, paths, security settings, blood type rules

if (!defined('APP_ROOT')) die('Direct access not permitted');

// Database
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'bloodbank');
define('DB_USER', 'root');
define('DB_PASS', '');

// Paths
define('APP_URL', 'http://localhost/bloodlink');

// Security
define('SESSION_TIMEOUT', 1800);         // 30 minutes of inactivity
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
define('ADMIN_SECURITY_KEY', 'bloodlink-admin-2024');

// Blood type compatibility map
// Key = recipient, value = array of donor types that can donate to them
define('BLOOD_COMPATIBILITY', [
    'A+'  => ['A+', 'A-', 'O+', 'O-'],
    'A-'  => ['A-', 'O-'],
    'B+'  => ['B+', 'B-', 'O+', 'O-'],
    'B-'  => ['B-', 'O-'],
    'AB+' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
    'AB-' => ['A-', 'B-', 'AB-', 'O-'],
    'O+'  => ['O+', 'O-'],
    'O-'  => ['O-'],
]);

// File uploads
define('UPLOAD_DIR', APP_ROOT . '/public/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB