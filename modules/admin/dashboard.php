<?php
// Admin dashboard — stats, charts, quick navigation, recent activity

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();

// ---- Stat card data ----
$stats = [
    ['icon' => 'fa-users',          'n' => $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn(),                                      'l' => 'Total Users'],
    ['icon' => 'fa-user-friends',   'n' => $db->query("SELECT COUNT(*) FROM users WHERE role = 'donor' AND is_active = 1")->fetchColumn(),                   'l' => 'Donors'],
    ['icon' => 'fa-hospital',       'n' => $db->query("SELECT COUNT(*) FROM hospital_profiles WHERE verification_status = 'verified'")->fetchColumn()
                                         . '/' . $db->query("SELECT COUNT(*) FROM hospital_profiles")->fetchColumn(),                                         'l' => 'Hospitals'],
    ['icon' => 'fa-clock',          'n' => $db->query("SELECT COUNT(*) FROM hospital_profiles WHERE verification_status = 'pending'")->fetchColumn(),         'l' => 'Pending'],
    ['icon' => 'fa-clipboard-list', 'n' => $db->query("SELECT COUNT(*) FROM blood_requests WHERE status = 'open'")->fetchColumn(),                           'l' => 'Open Requests'],
    ['icon' => 'fa-tint',           'n' => $db->query("SELECT COALESCE(SUM(units), 0) FROM donation_history")->fetchColumn(),                                'l' => 'Units Donated'],
    ['icon' => 'fa-calendar-check', 'n' => $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn(),                                                       'l' => 'Bookings'],
    ['icon' => 'fa-globe',          'n' => $db->query("SELECT COUNT(*) FROM countries WHERE is_active = 1")->fetchColumn(),                                  'l' => 'Countries'],
];

$pendingH       = $stats[3]['n'];
$pendingReports = (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
$unreadMsgs     = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0")->fetchColumn();

// ---- Chart data: donations per month (last 6 months) ----
$monthlyDonations = $db->query(
    "SELECT DATE_FORMAT(donation_date, '%Y-%m') AS month, SUM(units) AS total
     FROM donation_history
     WHERE donation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY month ORDER BY month"
)->fetchAll();

$chartLabels = [];
$chartData   = [];
foreach ($monthlyDonations as $md) {
    $chartLabels[] = date('M Y', strtotime($md['month'] . '-01'));
    $chartData[]   = (int)$md['total'];
}

// ---- Chart data: blood type distribution ----
$bloodDist = $db->query(
    "SELECT blood_type, COUNT(*) AS cnt FROM donor_profiles GROUP BY blood_type ORDER BY blood_type"
)->fetchAll();

$btLabels = [];
$btData   = [];
foreach ($bloodDist as $bd) {
    $btLabels[] = $bd['blood_type'];
    $btData[]   = (int)$bd['cnt'];
}

// ---- Recent activity log ----
$logs = $db->query(
    "SELECT sl.action, sl.details, sl.created_at, u.name AS uname
     FROM system_logs sl
     LEFT JOIN users u ON sl.user_id = u.id
     ORDER BY sl.created_at DESC LIMIT 10"
)->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-shield-alt me-2 text-danger"></i>Admin Dashboard</h2>

<!-- ========== STAT CARDS ========== -->
<div class="row g-4 mb-4">
    <?php foreach ($stats as $s): ?>
    <div class="col-6 col-md-3">
        <div class="card card-stat">
            <div class="card-body dash-stat">
                <i class="fas <?= $s['icon'] ?> text-danger fa-2x"></i>
                <div class="number"><?= $s['n'] ?></div>
                <div class="label"><?= $s['l'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ========== CHARTS ========== -->
<div class="row g-4 mb-4">
    <!-- Donations per month bar chart -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-chart-bar me-2 text-danger"></i>Donations Per Month</h6>
            </div>
            <div class="card-body" style="height:280px">
                <canvas id="donationsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Blood type distribution doughnut chart -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-danger"></i>Donor Blood Types</h6>
            </div>
            <div class="card-body" style="height:280px">
                <canvas id="bloodTypeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ========== QUICK NAVIGATION ========== -->
<div class="row g-4 mb-4">
    <!-- Hospitals -->
    <div class="col-md-3">
        <a href="hospitals.php" class="card text-decoration-none h-100 <?= $pendingH ? 'border-warning' : '' ?>">
            <div class="card-body text-center p-4">
                <i class="fas fa-hospital fa-2x text-danger mb-2"></i>
                <h6>Hospitals</h6>
                <?php if ($pendingH): ?>
                    <span class="badge bg-warning text-dark"><?= $pendingH ?> pending</span>
                <?php endif; ?>
            </div>
        </a>
    </div>

    <!-- Users -->
    <div class="col-md-3">
        <a href="users.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-users-cog fa-2x text-danger mb-2"></i>
                <h6>Users</h6>
            </div>
        </a>
    </div>

    <!-- Reports (with pending badge) -->
    <div class="col-md-3">
        <a href="reports.php" class="card text-decoration-none h-100 position-relative <?= $pendingReports ? 'border-warning' : '' ?>">
            <div class="card-body text-center p-4">
                <i class="fas fa-flag fa-2x text-danger mb-2"></i>
                <h6>Reports</h6>
            </div>
            <?php if ($pendingReports > 0): ?>
                <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:.7em">
                    <i class="fas fa-exclamation me-1"></i><?= $pendingReports ?>
                </span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Profile -->
    <div class="col-md-3">
        <a href="profile.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-user-shield fa-2x text-danger mb-2"></i>
                <h6>My Profile</h6>
            </div>
        </a>
    </div>

    <!-- Locations -->
    <div class="col-md-3">
        <a href="locations.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-map-marker-alt fa-2x text-danger mb-2"></i>
                <h6>Locations</h6>
            </div>
        </a>
    </div>

    <!-- CSV Import -->
    <div class="col-md-3">
        <a href="import.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-file-upload fa-2x text-danger mb-2"></i>
                <h6>Import CSV</h6>
            </div>
        </a>
    </div>

    <!-- Logs -->
    <div class="col-md-3">
        <a href="logs.php" class="card text-decoration-none h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-file-alt fa-2x text-danger mb-2"></i>
                <h6>Logs</h6>
            </div>
        </a>
    </div>

    <!-- Messages (with unread badge) -->
    <div class="col-md-3">
        <a href="messages.php" class="card text-decoration-none h-100 position-relative <?= $unreadMsgs ? 'border-warning' : '' ?>">
            <div class="card-body text-center p-4">
                <i class="fas fa-envelope fa-2x text-danger mb-2"></i>
                <h6>Messages</h6>
            </div>
            <?php if ($unreadMsgs > 0): ?>
                <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:.7em">
                    <i class="fas fa-exclamation me-1"></i><?= $unreadMsgs ?>
                </span>
            <?php endif; ?>
        </a>
    </div>
</div>

<!-- ========== RECENT ACTIVITY LOG ========== -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">Recent Activity</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><small><?= e($l['created_at']) ?></small></td>
                        <td><?= e($l['uname'] ?? 'System') ?></td>
                        <td><code><?= e($l['action']) ?></code></td>
                        <td><small><?= e(substr($l['details'] ?? '', 0, 80)) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========== CHART.JS ========== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Donations per month — bar chart
var ctx1 = document.getElementById('donationsChart').getContext('2d');
new Chart(ctx1, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Units Donated',
            data: <?= json_encode($chartData) ?>,
            backgroundColor: 'rgba(192, 57, 43, 0.7)',
            borderRadius: 6,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});

// Blood type distribution — doughnut chart
var ctx2 = document.getElementById('bloodTypeChart').getContext('2d');
new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($btLabels) ?>,
        datasets: [{
            data: <?= json_encode($btData) ?>,
            backgroundColor: [
                '#c0392b', '#e74c3c', '#3498db', '#2980b9',
                '#27ae60', '#2ecc71', '#f39c12', '#e67e22'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
        }
    }
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>