<?php
// Shared helper functions — auth, CSRF, notifications, matching engine, etc.

if (!defined('APP_ROOT')) die('Direct access not permitted');


// =========================================================================
// SESSION HANDLING
// =========================================================================

function initSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly'  => true,
            'samesite'  => 'Strict'
        ]);
        session_start();
    }

    // Auto-expire after inactivity (defined in config.php)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_error'] = 'Session expired. Please log in again.';
    }
    $_SESSION['last_activity'] = time();
}


// =========================================================================
// CSRF PROTECTION
// =========================================================================

function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(generateCSRFToken()) . '">';
}

function verifyCSRF(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;

    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) return false;

    // Single-use: clear after verification
    unset($_SESSION['csrf_token']);
    return true;
}


// =========================================================================
// OUTPUT ESCAPING
// =========================================================================

function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}


// =========================================================================
// AUTH HELPERS
// =========================================================================

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole(): ?string {
    return $_SESSION['user']['role'] ?? null;
}

function currentCountryId(): ?int {
    return $_SESSION['user']['country_id'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Please log in.');
        redirect('/modules/auth/login.php');
    }
}

// Enforce role-based access — accepts one or more allowed roles
function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array(currentUserRole(), $roles)) {
        setFlash('error', 'Access denied.');
        redirect('/index.php');
    }
}

// Block unverified hospitals from accessing protected pages
function requireVerifiedHospital(): void {
    requireRole('hospital');
    $db = Database::getInstance();
    $s = $db->prepare("SELECT verification_status FROM hospital_profiles WHERE user_id = :u");
    $s->execute([':u' => currentUserId()]);
    $h = $s->fetch();

    if (!$h || $h['verification_status'] !== 'verified') {
        setFlash('error', 'Hospital must be verified first.');
        redirect('/modules/hospital/dashboard.php');
    }
}


// =========================================================================
// REDIRECT + FLASH MESSAGES
// =========================================================================

function redirect(string $path): void {
    header('Location: ' . APP_URL . $path);
    exit;
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash_' . $type] = $message;
}

function getFlash(string $type): ?string {
    $key = 'flash_' . $type;
    $msg = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    return $msg;
}

// Render any pending flash messages as Bootstrap alerts
function renderFlash(): string {
    $html = '';
    $hasFlash = false;

    foreach (['success', 'error', 'info', 'warning'] as $type) {
        $msg = getFlash($type);
        if (!$msg) continue;

        $class = ($type === 'error') ? 'danger' : $type;
        $hasFlash = true;
        $html .= '<div class="alert alert-' . $class . ' alert-dismissible fade show flash-auto">'
            . e($msg)
            . '<button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>'
            . '</div>';
    }

    // Auto-dismiss after 4 seconds
    if ($hasFlash) {
        $html .= '<script>'
            . 'setTimeout(function(){'
            . 'document.querySelectorAll(".flash-auto").forEach(function(el){'
            . 'el.style.transition="opacity .5s";el.style.opacity="0";'
            . 'setTimeout(function(){el.remove();},500);'
            . '});'
            . '},4000);'
            . '</script>';
    }

    return $html;
}


// =========================================================================
// SYSTEM LOGGING
// =========================================================================

function logAction(string $action, ?string $details = null): void {
    try {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO system_logs (user_id, action, details, ip_address)
             VALUES (:u, :a, :d, :ip)"
        )->execute([
            ':u'  => currentUserId(),
            ':a'  => $action,
            ':d'  => $details,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (PDOException $ex) {
        // Silently fail — logging should never break the app
    }
}


// =========================================================================
// NOTIFICATIONS
// =========================================================================

function createNotification(int $userId, string $title, string $msg, string $type = 'info', ?string $link = null): void {
    try {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO notifications (user_id, title, message, type, link)
             VALUES (:u, :t, :m, :tp, :l)"
        )->execute([
            ':u'  => $userId,
            ':t'  => $title,
            ':m'  => $msg,
            ':tp' => $type,
            ':l'  => $link
        ]);
    } catch (PDOException $ex) {}
}

function getUnreadNotificationCount(): int {
    if (!isLoggedIn()) return 0;
    try {
        $db = Database::getInstance();
        $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0");
        $s->execute([':u' => currentUserId()]);
        return (int)$s->fetchColumn();
    } catch (PDOException $ex) {
        return 0;
    }
}


// =========================================================================
// VALIDATION
// =========================================================================

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateBloodType(string $type): bool {
    return in_array($type, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']);
}

// Password must be 8+ chars with at least 1 uppercase and 1 lowercase
function validatePassword(string $pw): array {
    $errors = [];
    if (strlen($pw) < 8)              $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $pw))  $errors[] = 'Password must contain at least 1 uppercase letter.';
    if (!preg_match('/[a-z]/', $pw))  $errors[] = 'Password must contain at least 1 lowercase letter.';
    return $errors;
}


// =========================================================================
// GEOGRAPHIC DISTANCE
// =========================================================================

// Haversine formula — returns straight-line distance in km between two coordinates
function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Prevent donors/hospitals from accessing data outside their country
function enforceCountry(int $countryId): void {
    if (currentUserRole() !== 'admin' && $countryId !== currentCountryId()) {
        setFlash('error', 'Country mismatch.');
        redirect('/index.php');
    }
}

function getAreaCoords(int $areaId): ?array {
    $db = Database::getInstance();
    $s = $db->prepare("SELECT centroid_lat, centroid_lon FROM areas WHERE id = :id AND is_active = 1");
    $s->execute([':id' => $areaId]);
    return $s->fetch() ?: null;
}

// Format distance for display — "Nearby" only for genuinely same area
function formatDistance(?float $dist, ?int $donorAreaId, ?int $requestAreaId): string {
    if ($dist === null) return '-';
    if ($donorAreaId && $requestAreaId && $donorAreaId === $requestAreaId) return '< 1 km · Same Area';
    if ($dist < 0.5) return '< 1 km · Nearby';
    return number_format($dist, 1) . ' km';
}


// =========================================================================
// DONOR ELIGIBILITY (90-DAY RULE)
// =========================================================================

function computeNextEligibleDate(?string $lastDonation): ?string {
    if (!$lastDonation) return null;
    return date('Y-m-d', strtotime($lastDonation . ' + 90 days'));
}

function isDonorEligible(?string $lastDonation): bool {
    if (!$lastDonation) return true;
    $nextDate = computeNextEligibleDate($lastDonation);
    return $nextDate <= date('Y-m-d');
}

// Called after a booking is marked complete — sets 90-day cooldown
function markDonorPostDonation(int $donorProfileId, string $donationDate): void {
    $db = Database::getInstance();
    $nextDate = date('Y-m-d', strtotime($donationDate . ' + 90 days'));
    $db->prepare(
        "UPDATE donor_profiles
         SET last_donation_date = :d, next_eligible_date = :n, is_eligible = 0, is_available = 0
         WHERE id = :id"
    )->execute([':d' => $donationDate, ':n' => $nextDate, ':id' => $donorProfileId]);
}


// =========================================================================
// MATCHING ENGINE
// Scores and ranks donors for a blood request using four weighted factors
// =========================================================================

/**
 * Score a single donor against a request on a 0-100 scale.
 * Weighting: distance(40) + donation recency(25) + urgency boost(20) + profile freshness(15)
 */
function computeMatchScore(array $donor, array $request, ?float $distance): array {
    $score = 0;
    $reasons = [];

    // --- Distance score: 0-40 pts ---
    // Radius changes with urgency so critical requests search wider
    $urgencyRadius = ['low' => 10, 'medium' => 15, 'high' => 25, 'critical' => 50];
    $maxRadius = $urgencyRadius[$request['urgency']] ?? 15;

    if ($distance !== null) {
        if ($distance <= $maxRadius) {
            // Linear scale: 0 km = 40 pts, edge of radius = 5 pts
            $distScore = max(5, 40 - (35 * $distance / $maxRadius));
        } else {
            // Beyond radius: steep dropoff but not zero for nearby-ish donors
            $distScore = max(0, 5 - (($distance - $maxRadius) / 20));
        }
        $score += $distScore;
        $reasons[] = number_format($distance, 1) . ' km away';
    } else {
        $score += 5;
        $reasons[] = 'Distance unknown';
    }

    // --- Profile freshness: 0-15 pts ---
    // Recently active donors are more likely to respond
    if (!empty($donor['updated_at'])) {
        $daysSinceUpdate = max(0, (time() - strtotime($donor['updated_at'])) / 86400);
        if ($daysSinceUpdate <= 7)       { $score += 15; $reasons[] = 'Recently active'; }
        elseif ($daysSinceUpdate <= 30)  { $score += 10; $reasons[] = 'Active this month'; }
        elseif ($daysSinceUpdate <= 90)  { $score += 5; }
    } else {
        $score += 5;
    }

    // --- Donation recency: 0-25 pts ---
    // Only eligible donors reach here (is_eligible=1 filter in SQL)
    // Everyone here is 90+ days since last donation or has never donated
    if (!empty($donor['last_donation_date'])) {
        $daysSinceDonation = max(0, (time() - strtotime($donor['last_donation_date'])) / 86400);
        if ($daysSinceDonation >= 180)    { $score += 25; $reasons[] = 'Long-term ready (6+ months)'; }
        elseif ($daysSinceDonation >= 90) { $score += 20; $reasons[] = 'Eligible (90+ days since last)'; }
        else                              { $score += 5;  $reasons[] = 'Recently eligible'; }
    } else {
        $score += 20; // Never donated — fresh candidate
        $reasons[] = 'First-time donor';
    }

    // --- Urgency boost: 0-20 pts ---
    // Critical/high requests push scores up so expanded-radius donors still rank well
    $urgencyBoost = ['low' => 5, 'medium' => 10, 'high' => 15, 'critical' => 20];
    $ub = $urgencyBoost[$request['urgency']] ?? 5;
    $score += $ub;
    if ($request['urgency'] === 'critical') $reasons[] = 'Critical urgency boost';
    elseif ($request['urgency'] === 'high') $reasons[] = 'High urgency boost';

    return [
        'score'        => round(min(100, max(0, $score)), 1),
        'reasons'      => $reasons,
        'distance'     => $distance,
        'radius'       => $maxRadius,
        'within_radius' => $distance !== null && $distance <= $maxRadius,
    ];
}

// Radius thresholds per urgency — used for progressive expansion
function getUrgencyRadius(string $urgency): array {
    $map = [
        'low'      => ['primary' => 10,  'expanded' => 20,  'max' => 50],
        'medium'   => ['primary' => 15,  'expanded' => 30,  'max' => 50],
        'high'     => ['primary' => 25,  'expanded' => 50,  'max' => 100],
        'critical' => ['primary' => 50,  'expanded' => 100, 'max' => 999],
    ];
    return $map[$urgency] ?? $map['medium'];
}

/**
 * Main matching pipeline — filters, scores, and returns ranked donors.
 * Uses progressive radius expansion: primary -> expanded -> same city -> all
 */
function findMatchedDonors(int $requestId, int $limit = 20): array {
    $db = Database::getInstance();

    // Load the request with its location coordinates
    $s = $db->prepare(
        "SELECT br.*, a.centroid_lat AS req_lat, a.centroid_lon AS req_lon, a.city_id AS req_city_id
         FROM blood_requests br
         LEFT JOIN areas a ON br.area_id = a.id
         WHERE br.id = :id"
    );
    $s->execute([':id' => $requestId]);
    $request = $s->fetch();
    if (!$request) return [];

    // Look up compatible blood types for the requested type
    $compatible = BLOOD_COMPATIBILITY[$request['blood_type']] ?? [];
    if (empty($compatible)) return [];

    // Build parameterised placeholders for the IN clause
    $phs = [];
    $params = [':cid' => $request['country_id']];
    foreach ($compatible as $i => $bt) {
        $k = ':bt' . $i;
        $phs[] = $k;
        $params[$k] = $bt;
    }

    // Pull all eligible donors — six filters applied at database level
    $sql = "SELECT dp.*, u.name AS donor_name, u.email, u.area_id AS user_area_id,
                a.centroid_lat AS donor_lat, a.centroid_lon AS donor_lon,
                a.name AS area_name, a.city_id AS donor_city_id
            FROM donor_profiles dp
            JOIN users u ON dp.user_id = u.id
            LEFT JOIN areas a ON dp.area_id = a.id
            WHERE u.country_id = :cid
              AND u.role = 'donor'
              AND u.is_active = 1
              AND dp.is_available = 1
              AND dp.is_eligible = 1
              AND dp.blood_type IN (" . implode(',', $phs) . ")
            ORDER BY dp.updated_at DESC";

    $s = $db->prepare($sql);
    $s->execute($params);
    $donors = $s->fetchAll();

    $radii = getUrgencyRadius($request['urgency']);
    $rLat = $request['req_lat'];
    $rLon = $request['req_lon'];

    // Score each donor
    $scored = [];
    foreach ($donors as $d) {
        // Haversine distance between hospital and donor
        $dist = null;
        if ($rLat && $rLon && $d['donor_lat'] && $d['donor_lon']) {
            $dist = haversineDistance((float)$rLat, (float)$rLon, (float)$d['donor_lat'], (float)$d['donor_lon']);
        }

        // Check if donor already has a pending/confirmed booking
        $bs = $db->prepare("SELECT COUNT(*) FROM bookings WHERE donor_id = :did AND status IN ('pending','confirmed')");
        $bs->execute([':did' => $d['id']]);
        $hasActive = (int)$bs->fetchColumn() > 0;

        // Run the scoring function
        $match = computeMatchScore($d, $request, $dist);
        $match['donor'] = $d;
        $match['has_active_booking'] = $hasActive;

        // Check if they've already booked this specific request
        $ds = $db->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE blood_request_id = :rid AND donor_id = :did
               AND status NOT IN ('cancelled','rejected')"
        );
        $ds->execute([':rid' => $requestId, ':did' => $d['id']]);
        $match['already_booked_this'] = (int)$ds->fetchColumn() > 0;

        $scored[] = $match;
    }

    // Rank by composite score, highest first
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    // Progressive radius expansion — try tightest circle first
    $primary = array_filter($scored, fn($m) => $m['within_radius']);
    if (count($primary) >= 3) return array_slice($primary, 0, $limit);

    // Not enough nearby — widen the search
    $expanded = array_filter($scored, function ($m) use ($radii) {
        return $m['distance'] === null || $m['distance'] <= $radii['expanded'];
    });
    if (count($expanded) >= 3) return array_slice($expanded, 0, $limit);

    // Still short — fall back to same city
    if ($request['req_city_id']) {
        $cityFallback = array_filter(
            $scored,
            fn($m) => ($m['donor']['donor_city_id'] ?? 0) == $request['req_city_id']
        );
        if (!empty($cityFallback)) return array_slice(array_values($cityFallback), 0, $limit);
    }

    // Last resort — return whatever we have
    return array_slice($scored, 0, $limit);
}

// Send in-app notifications to the top N matched donors for a request
function notifyTopMatches(int $requestId, int $topN = 10): int {
    $db = Database::getInstance();
    $s = $db->prepare(
        "SELECT br.*, hp.hospital_name
         FROM blood_requests br
         JOIN hospital_profiles hp ON br.hospital_id = hp.id
         WHERE br.id = :id"
    );
    $s->execute([':id' => $requestId]);
    $req = $s->fetch();
    if (!$req) return 0;

    $matches = findMatchedDonors($requestId, $topN);
    $notified = 0;

    foreach ($matches as $m) {
        if ($m['already_booked_this'] || !isset($m['donor']['user_id'])) continue;

        $ds = $db->prepare("SELECT user_id FROM donor_profiles WHERE id = :id");
        $ds->execute([':id' => $m['donor']['id']]);
        $du = $ds->fetch();
        if (!$du) continue;

        $urgLabel = strtoupper($req['urgency']);
        $distText = $m['distance'] !== null
            ? number_format($m['distance'], 1) . 'km away'
            : 'your area';

        createNotification(
            $du['user_id'],
            "[$urgLabel] Blood Request Near You",
            $req['hospital_name'] . " needs {$req['blood_type']} — {$distText}. Book now!",
            $req['urgency'] === 'critical' ? 'danger' : ($req['urgency'] === 'high' ? 'warning' : 'info'),
            '/modules/matching/requests.php'
        );
        $notified++;
    }

    return $notified;
}


// =========================================================================
// RATE LIMITING (for API endpoints)
// =========================================================================

function apiRateLimit(int $maxPerMinute = 30): void {
    $now = time();
    $key = 'api_hits';
    if (!isset($_SESSION[$key])) $_SESSION[$key] = [];

    // Drop entries older than 60 seconds
    $_SESSION[$key] = array_filter($_SESSION[$key], fn($t) => $t > $now - 60);

    if (count($_SESSION[$key]) >= $maxPerMinute) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded']);
        exit;
    }

    $_SESSION[$key][] = $now;
}


// =========================================================================
// BRUTE FORCE PROTECTION
// =========================================================================

// Check if an email is locked out from too many failed login attempts
function isLoginLocked(string $email): bool {
    $db = Database::getInstance();
    $cutoff = date('Y-m-d H:i:s', time() - (LOGIN_LOCKOUT_MINUTES * 60));
    $s = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE email = :e AND attempted_at > :t"
    );
    $s->execute([':e' => $email, ':t' => $cutoff]);
    return (int)$s->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

// Log a failed login attempt
function recordFailedLogin(string $email): void {
    try {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO login_attempts (email, ip_address) VALUES (:e, :ip)"
        )->execute([
            ':e'  => $email,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (PDOException $ex) {}
}

// Clear failed attempts after a successful login
function clearLoginAttempts(string $email): void {
    try {
        $db = Database::getInstance();
        $db->prepare("DELETE FROM login_attempts WHERE email = :e")
            ->execute([':e' => $email]);
    } catch (PDOException $ex) {}
}

// Count unread admin replies on contact messages for the current user
function getUnreadContactReplyCount(): int {
    if (!isLoggedIn()) return 0;
    try {
        $db = Database::getInstance();
        $s = $db->prepare(
            "SELECT COUNT(*) FROM contact_messages
             WHERE user_id = :u AND admin_reply IS NOT NULL AND is_read = 0"
        );
        $s->execute([':u' => currentUserId()]);
        return (int)$s->fetchColumn();
    } catch (PDOException $ex) {
        return 0;
    }
}