<?php
// AJAX endpoint — returns areas for a given city (used by location picker)

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';

initSession();
apiRateLimit(30);
header('Content-Type: application/json');

// Validate city_id from query string
$cityId = filter_input(INPUT_GET, 'city_id', FILTER_VALIDATE_INT);
$q = trim($_GET['q'] ?? '');

if (!$cityId || $cityId < 1) {
    echo json_encode(['error' => 'Invalid city_id', 'areas' => []]);
    exit;
}

// Sanitise search query — allow letters, numbers, spaces, hyphens only
$q = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $q);

$db = Database::getInstance();

if (strlen($q) < 1) {
    // No search term — return all areas for this city
    $stmt = $db->prepare(
        "SELECT id, name FROM areas WHERE city_id = :c AND is_active = 1 ORDER BY name LIMIT 50"
    );
    $stmt->execute([':c' => $cityId]);
} else {
    // Search term provided — filter by prefix match
    $stmt = $db->prepare(
        "SELECT id, name FROM areas WHERE city_id = :c AND is_active = 1 AND name LIKE :q ORDER BY name LIMIT 10"
    );
    $stmt->execute([':c' => $cityId, ':q' => $q . '%']);
}

echo json_encode(['areas' => $stmt->fetchAll()]);