<?php
// blood request management — create, edit, close requests with time slots
// Create form is collapsed by default; expand with "+ New Request" button
$pageTitle = 'Blood Requests';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('hospital');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

// ---- load hospital profile ----
$s = $db->prepare("SELECT * FROM hospital_profiles WHERE user_id = :u");
$s->execute([':u' => $uid]);
$hospital = $s->fetch();
$hid = $hospital['id'] ?? 0;
$errors = [];

// ---- handle CREATE REQUEST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])) {
    if (!verifyCSRF()) {
        $errors[] = 'Invalid token.';
    } elseif ($hospital['verification_status'] !== 'verified') {
        $errors[] = 'Only verified hospitals can create requests.';
    } else {
        $bt            = $_POST['blood_type'] ?? '';
        $units         = max(1, (int)($_POST['units_needed'] ?? 1));
        $urg           = $_POST['urgency'] ?? 'medium';
        $desc          = trim($_POST['description'] ?? '');
        $dl            = $_POST['deadline'] ?? null;
        $areaId        = $hospital['area_id'];
        $selectedSlots = $_POST['time_slots'] ?? [];
        $slotDate      = $_POST['slot_date'] ?? '';

        if (!validateBloodType($bt)) $errors[] = 'Invalid blood type.';
        if (!in_array($urg, ['low', 'medium', 'high', 'critical'])) {
            $errors[] = 'Invalid urgency.';
        }

        // low/medium urgency requires time slots
        if (in_array($urg, ['low', 'medium'])) {
            if (empty($slotDate))      $errors[] = 'Select a date for available time slots.';
            if (empty($selectedSlots)) $errors[] = 'Select at least one available time slot.';
        }

        if (empty($errors)) {
            $db->beginTransaction();
            try {
                // insert the request
                $db->prepare(
                    "INSERT INTO blood_requests
                     (hospital_id, country_id, area_id, blood_type, units_needed, urgency, description, deadline)
                     VALUES (:h, :c, :a, :bt, :u, :urg, :d, :dl)"
                )->execute([
                    ':h'   => $hid,   ':c'  => $cid,
                    ':a'   => $areaId, ':bt' => $bt,
                    ':u'   => $units,  ':urg' => $urg,
                    ':d'   => $desc,   ':dl' => $dl ?: null
                ]);
                $newReqId = (int)$db->lastInsertId();

                // create time slots for non-emergency requests
                if (in_array($urg, ['low', 'medium']) && !empty($selectedSlots) && $slotDate) {
                    foreach ($selectedSlots as $startTime) {
                        $st = $startTime;
                        $et = date('H:i', strtotime($startTime) + 1800); // +30 mins

                        $db->prepare(
                            "INSERT INTO time_slots
                             (request_id, hospital_id, slot_date, start_time, end_time, max_donors)
                             VALUES (:r, :h, :d, :s, :e, 1)"
                        )->execute([
                            ':r' => $newReqId, ':h' => $hid,
                            ':d' => $slotDate, ':s' => $st, ':e' => $et
                        ]);
                    }
                }

                $db->commit();
                logAction('blood_request_created', "#$newReqId $units $bt $urg");

                // auto-notify top donors based on urgency
                $autoNotify = match ($urg) {
                    'critical' => 20,
                    'high'     => 10,
                    default    => 5,
                };
                $notified = notifyTopMatches($newReqId, $autoNotify);
                logAction('auto_notify_matches', "Request#$newReqId notified $notified donors");

                setFlash('success', "Request created! $notified matched donors notified.");
                redirect("/modules/hospital/request_matches.php?id=$newReqId");
            } catch (PDOException $ex) {
                $db->rollBack();
                $errors[] = 'Failed: ' . $ex->getMessage();
            }
        }
    }
}

// ---- handle CLOSE REQUEST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_request']) && verifyCSRF()) {
    $rid = (int)$_POST['request_id'];
    $db->prepare(
        "UPDATE blood_requests SET status = 'closed'
         WHERE id = :r AND hospital_id = :h AND country_id = :c"
    )->execute([':r' => $rid, ':h' => $hid, ':c' => $cid]);
    logAction('request_closed', "#$rid");
    setFlash('success', 'Closed.');
    redirect('/modules/hospital/requests.php');
}

// ---- load all requests, sorted by urgency and creation date ----
$s = $db->prepare(
    "SELECT br.*, a.name AS area_name
     FROM blood_requests br
     LEFT JOIN areas a ON br.area_id = a.id
     WHERE br.hospital_id = :h AND br.country_id = :c
     ORDER BY FIELD(br.urgency, 'critical', 'high', 'medium', 'low'), br.created_at DESC"
);
$s->execute([':h' => $hid, ':c' => $cid]);
$requests = $s->fetchAll();

// count stats for bottom summary
$openCount      = 0;
$fulfilledCount = 0;
$closedCount    = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'open')         $openCount++;
    elseif ($r['status'] === 'fulfilled') $fulfilledCount++;
    elseif ($r['status'] === 'closed')    $closedCount++;
}

// generate 30-min time slots for 24hrs
$timeOptions = [];
for ($h = 0; $h < 24; $h++) {
    for ($m = 0; $m < 60; $m += 30) {
        $timeOptions[] = sprintf('%02d:%02d', $h, $m);
    }
}

// auto-expand create form if errors occurred OR ?new=1 in URL
$expandForm = !empty($errors) || isset($_GET['new']);

require_once APP_ROOT . '/includes/header.php';
?>

<!-- page header with "+ New Request" toggle button -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="fas fa-clipboard-list me-2 text-danger"></i>Blood Requests
    </h2>
    <?php if ($hospital && $hospital['verification_status'] === 'verified'): ?>
        <button type="button" class="btn btn-blood" id="toggleCreateBtn"
                onclick="toggleCreateForm()">
            <i class="fas fa-plus me-1"></i><span id="toggleBtnText">New Request</span>
        </button>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ===== CREATE REQUEST FORM (collapsed by default) ===== -->
<?php if ($hospital && $hospital['verification_status'] === 'verified'): ?>
    <div class="card mb-4" id="createRequestCard" style="display: <?= $expandForm ? 'block' : 'none' ?>;">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Create New Request</h5>
            <button type="button" class="btn-close" onclick="toggleCreateForm()" title="Close"></button>
        </div>
        <div class="card-body">
            <form method="POST" id="reqForm">
                <?= csrfField() ?>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Blood Type *</label>
                        <select class="form-select" name="blood_type" required>
                            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                                <option value="<?= $bt ?>"><?= $bt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Units *</label>
                        <input type="number" class="form-control" name="units_needed"
                               value="1" min="1" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Urgency *</label>
                        <select class="form-select" name="urgency" id="urgencySelect"
                                onchange="toggleSlots()">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Deadline</label>
                        <input type="date" class="form-control" name="deadline"
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description"
                               placeholder="Details...">
                    </div>
                </div>

                <!-- Time Slots (hidden for high/critical) -->
                <div id="timeSlotsSection" class="mt-3">
                    <hr>
                    <h6 class="fw-bold">
                        <i class="fas fa-clock me-2"></i>Available Time Slots
                    </h6>
                    <p class="text-muted small">
                        Select the date and mark which 30-minute slots you are available.
                        Donors will pick from these.
                    </p>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="slot_date"
                                   min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="row g-1">
                        <?php foreach ($timeOptions as $t): ?>
                            <div class="col-auto">
                                <label class="btn btn-sm btn-outline-secondary mb-1 slot-btn">
                                    <input type="checkbox" name="time_slots[]" value="<?= $t ?>" class="d-none">
                                    <small><?= $t ?></small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Emergency notice (shown for high/critical) -->
                <div id="asapSection" class="mt-3" style="display:none">
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-bolt me-2"></i>
                        <strong>Emergency Request:</strong> This will be marked as
                        "As Soon As Possible". No time slot selection needed — donors will
                        be contacted immediately.
                    </div>
                </div>

                <button type="submit" name="create_request" class="btn btn-blood mt-3">
                    <i class="fas fa-plus me-2"></i>Create & Find Matches
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ===== YOUR REQUESTS LIST ===== -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">
            Your Requests (<?= count($requests) ?>)
            <?php if ($openCount > 0): ?>
                <span class="badge bg-danger ms-2"><?= $openCount ?> open</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($requests)): ?>
            <p class="text-muted mb-0">
                No requests yet. Click "New Request" above to create your first blood request.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Blood</th>
                            <th>Needed</th>
                            <th>Fulfilled</th>
                            <th>Urgency</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td>
                                    <span class="blood-type-badge blood-type-badge-sm">
                                        <?= e($r['blood_type']) ?>
                                    </span>
                                </td>
                                <td><?= e($r['units_needed']) ?></td>
                                <td><?= e($r['units_fulfilled']) ?></td>
                                <td>
                                    <span class="badge badge-urgency-<?= e($r['urgency']) ?>">
                                        <?= e(ucfirst($r['urgency'])) ?>
                                    </span>
                                </td>
                                <td><?= e($r['deadline'] ?? '-') ?></td>
                                <td>
                                    <span class="badge badge-status-<?= e($r['status']) ?>">
                                        <?= e(ucfirst($r['status'])) ?>
                                    </span>
                                </td>
                                <td class="d-flex gap-1">
                                    <a href="<?= APP_URL ?>/modules/hospital/request_matches.php?id=<?= $r['id'] ?>"
                                       class="btn btn-sm <?= $r['status'] === 'open' ? 'btn-blood' : 'btn-outline-secondary' ?>">
                                        <i class="fas fa-<?= $r['status'] === 'open' ? 'bullseye' : 'eye' ?> me-1"></i>
                                        <?= $r['status'] === 'open' ? 'Matches' : 'View' ?>
                                    </a>
                                    <?php if ($r['status'] === 'open'): ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Close?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <button type="submit" name="close_request"
                                                    class="btn btn-sm btn-outline-secondary">
                                                Close
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== BOTTOM INFO SECTION ===== -->
<!-- combined summary stats + urgency guide + tips -->
<div class="row g-4 mt-4">

    <!-- Summary stats -->
    <div class="col-md-4">
        <div class="card border-info h-100">
            <div class="card-header bg-info bg-opacity-10">
                <h6 class="mb-0 text-info">
                    <i class="fas fa-chart-pie me-2"></i>Your Request Summary
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-circle text-danger me-2 small"></i>Open</span>
                    <strong><?= $openCount ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-circle text-success me-2 small"></i>Fulfilled</span>
                    <strong><?= $fulfilledCount ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-circle text-secondary me-2 small"></i>Closed</span>
                    <strong><?= $closedCount ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Urgency guide -->
    <div class="col-md-4">
        <div class="card border-warning h-100">
            <div class="card-header bg-warning bg-opacity-10">
                <h6 class="mb-0 text-warning">
                    <i class="fas fa-tachometer-alt me-2"></i>Urgency Levels Guide
                </h6>
            </div>
            <div class="card-body small">
                <p class="mb-1">
                    <span class="badge badge-urgency-critical">Critical</span>
                    Life-threatening · 50 km radius · notifies 20 donors
                </p>
                <p class="mb-1">
                    <span class="badge badge-urgency-high">High</span>
                    Urgent · 25 km radius · notifies 10 donors
                </p>
                <p class="mb-1">
                    <span class="badge badge-urgency-medium">Medium</span>
                    Standard · 15 km radius · notifies 5 donors
                </p>
                <p class="mb-0">
                    <span class="badge badge-urgency-low">Low</span>
                    Planned · 10 km radius · notifies 5 donors
                </p>
            </div>
        </div>
    </div>

    <!-- Tips -->
    <div class="col-md-4">
        <div class="card border-success h-100">
            <div class="card-header bg-success bg-opacity-10">
                <h6 class="mb-0 text-success">
                    <i class="fas fa-lightbulb me-2"></i>Tips for Better Matches
                </h6>
            </div>
            <div class="card-body small">
                <p class="mb-2">
                    <i class="fas fa-check-circle text-success me-1"></i>
                    Use Critical only for true emergencies — it searches further and alerts more donors.
                </p>
                <p class="mb-2">
                    <i class="fas fa-check-circle text-success me-1"></i>
                    Provide multiple time slots for Low/Medium requests — more flexibility attracts more donors.
                </p>
                <p class="mb-0">
                    <i class="fas fa-check-circle text-success me-1"></i>
                    Add a clear description with patient context to help donors understand urgency.
                </p>
            </div>
        </div>
    </div>
</div>

<style>
.slot-btn {
    min-width: 62px;
    padding: .25rem .5rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all .2s;
    user-select: none;
}
.slot-btn.selected {
    background: #27ae60;
    color: #fff;
    border-color: #27ae60;
}
</style>

<script>
// toggle create form visibility
function toggleCreateForm() {
    var card = document.getElementById('createRequestCard');
    var btnText = document.getElementById('toggleBtnText');
    if (card.style.display === 'none') {
        card.style.display = 'block';
        btnText.textContent = 'Cancel';
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        card.style.display = 'none';
        btnText.textContent = 'New Request';
    }
}

// show/hide time slots based on urgency
function toggleSlots() {
    var u = document.getElementById('urgencySelect').value;
    var s = document.getElementById('timeSlotsSection');
    var a = document.getElementById('asapSection');

    if (u === 'high' || u === 'critical') {
        s.style.display = 'none';
        a.style.display = 'block';
        // clear any selected slots
        s.querySelectorAll('input[type=checkbox]').forEach(function(c) {
            c.checked = false;
            c.closest('.slot-btn').classList.remove('selected');
        });
        var dt = s.querySelector('input[type=date]');
        if (dt) dt.value = '';
    } else {
        s.style.display = 'block';
        a.style.display = 'none';
    }
}

// clicking a slot button toggles its checkbox
document.querySelectorAll('.slot-btn').forEach(function(b) {
    b.addEventListener('click', function(e) {
        e.preventDefault();
        var cb = this.querySelector('input[type=checkbox]');
        cb.checked = !cb.checked;
        this.classList.toggle('selected', cb.checked);
    });
});

toggleSlots();
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>