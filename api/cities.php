<?php
// AJAX endpoint — returns cities for a given country (used by location picker)

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';

initSession();
apiRateLimit(30);
header('Content-Type: application/json');

// Validate country_id from query string
$countryId = filter_input(INPUT_GET, 'country_id', FILTER_VALIDATE_INT);

if (!$countryId || $countryId < 1) {
    echo json_encode(['error' => 'Invalid country_id', 'cities' => []]);
    exit;
}

// Fetch all active cities for this country
$db = Database::getInstance();
$stmt = $db->prepare(
    "SELECT id, name FROM cities WHERE country_id = :cid AND is_active = 1 ORDER BY name"
);
$stmt->execute([':cid' => $countryId]);

echo json_encode(['cities' => $stmt->fetchAll()]);