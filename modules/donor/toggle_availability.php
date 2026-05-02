<?php
// toggles donor availability — but blocks "Set Available" if weight is below 45 kg
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('donor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRF()) {
    setFlash('error', 'Invalid request.');
    redirect('/modules/donor/dashboard.php');
}

$db  = Database::getInstance();
$uid = currentUserId();

// load current profile
$s = $db->prepare(
    "SELECT id, is_available, is_eligible, weight_kg FROM donor_profiles WHERE user_id = :u"
);
$s->execute([':u' => $uid]);
$p = $s->fetch();

if (!$p) {
    setFlash('error', 'Profile not found.');
    redirect('/modules/donor/dashboard.php');
}

$newValue = $p['is_available'] ? 0 : 1;

// ===== WEIGHT CHECK =====
// block setting available if weight is below 45 kg
if ($newValue === 1 && $p['weight_kg'] && $p['weight_kg'] < 45) {
    setFlash('error', 'Cannot set available: your weight (' . $p['weight_kg'] . ' kg) is below the 45 kg minimum. Update your weight in your profile first.');
    redirect('/modules/donor/dashboard.php');
}

// ===== ELIGIBILITY CHECK =====
// block setting available if still in 90-day cooldown
if ($newValue === 1 && !$p['is_eligible']) {
    setFlash('error', 'Cannot set available: you are still in the 90-day donation cooldown period.');
    redirect('/modules/donor/dashboard.php');
}

// update availability
$db->prepare("UPDATE donor_profiles SET is_available = :v WHERE id = :id")
    ->execute([':v' => $newValue, ':id' => $p['id']]);

logAction('donor_availability_toggle', $newValue ? 'available' : 'unavailable');
setFlash('success', $newValue ? 'You are now marked as available.' : 'You are now unavailable.');
redirect('/modules/donor/dashboard.php');